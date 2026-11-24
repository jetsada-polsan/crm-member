"""Read Bplus via the same read-only SQL files the Local Bridge uses and queue
batches for the HTTPS Push API (/api/sync/v1/*). No VPN / LAN pull required."""
from __future__ import annotations

import json
import re
from datetime import date, datetime
from decimal import Decimal
from pathlib import Path

QUERIES = Path(__file__).resolve().parents[1] / 'queries'
MAX_INVOICE_LINES = 2000
PAGE = 500

FIELDS = {
    'customers': ['erp_customer_code', 'customer_name', 'phone', 'province', 'address', 'ar_enable',
                  'salesperson_code', 'salesperson_name', 'route_code', 'route_name', 'credit_limit',
                  'credit_term_days', 'tax_id'],
    'products': ['erp_product_code', 'product_name', 'unit', 'category_code', 'category_name', 'is_excluded_kpi'],
    'salespersons': ['salesperson_code', 'salesperson_name', 'is_active', 'line_code', 'line_name', 'team_code',
                     'team_name', 'area_code', 'area_name', 'remark'],
    'members': ['member_code', 'full_name', 'title', 'phone', 'mobile_phone', 'email', 'address', 'member_type_code',
                'member_type_name', 'member_since', 'member_expire', 'erp_point_balance', 'erp_redeemed_total',
                'erp_spent_total', 'last_point_at'],
    'invoices': ['di_key', 'branch_code', 'doc_type', 'dt_properties', 'doc_category', 'invoice_no', 'erp_customer_code',
                 'salesperson_code', 'salesperson_name', 'sold_at', 'before_vat_amount', 'vat_amount',
                 'gross_amount', 'net_amount', 'status'],
    'items': ['line_no', 'erp_product_code', 'product_name', 'iccat_code', 'iccat_name', 'is_excluded_kpi', 'qty',
              'unit_name', 'unit_price', 'before_vat_amount', 'vat_amount', 'gross_amount', 'net_amount',
              'refer_trd_key', 'refer_doc_ref'],
}
REQUIRED = {
    'customers': ['erp_customer_code', 'customer_name'], 'products': ['erp_product_code', 'product_name'],
    'salespersons': ['salesperson_code', 'salesperson_name'], 'members': ['member_code', 'full_name'],
    'invoices': ['invoice_no', 'erp_customer_code', 'sold_at'],
}
MASTER_SQL = {'customers': 'bplus_customers.sql', 'products': 'bplus_products.sql',
              'salespersons': 'bplus_salespersons.sql', 'members': 'bplus_members.sql'}
INT_FIELDS = {'di_key', 'credit_term_days', 'dt_properties', 'line_no', 'erp_point_balance', 'erp_redeemed_total'}
BOOL_FIELDS = {'is_excluded_kpi', 'is_active'}


def _sql(name: str) -> str:
    text = (QUERIES / name).read_text(encoding='utf-8-sig')
    clean = re.sub(r'/\*[\s\S]*?\*/', '', re.sub(r'--[^\r\n]*', '', text)).strip()
    if not re.match(r'(?is)^(SELECT|WITH)\b', clean) or re.search(
            r'(?is)\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|EXEC|EXECUTE|MERGE|GRANT|REVOKE)\b', clean):
        raise RuntimeError(f'Blocked non read-only query {name}')
    return text


def _val(key, v):
    if v is None:
        return None
    if isinstance(v, (datetime, date)):
        return v.isoformat()
    if key in BOOL_FIELDS:
        return v in (True, 1, '1', 'Y', 'y', 'true', 'True')
    if isinstance(v, Decimal):
        return int(v) if key in INT_FIELDS else float(v)
    if isinstance(v, bytes):
        return v.decode('utf-8', 'replace')
    if isinstance(v, str):
        v = v.strip()
        return v or None
    return v


def _clean(kind, row):
    out = {k: _val(k, row.get(k)) for k in FIELDS[kind] if k in row}
    for k in REQUIRED.get(kind, []):
        if out.get(k) in (None, ''):
            return None
    for k in ('erp_customer_code', 'erp_product_code', 'salesperson_code', 'member_code', 'invoice_no'):
        if out.get(k) is not None:
            out[k] = str(out[k])
    return out


def _fetch(conn, sql, params):
    cur = conn.cursor()
    cur.execute(sql, params)
    cols = [c[0] for c in cur.description]
    return [dict(zip(cols, r)) for r in cur.fetchall()]


def connect(conn_str):
    import pyodbc
    return pyodbc.connect(conn_str, timeout=15, autocommit=True, readonly=True)


def queue_master(conn, kind, q, source) -> int:
    """Full sweep of a master table (idempotent upsert on the server)."""
    sql, after, batches = _sql(MASTER_SQL[kind]), 0, 0
    while True:
        rows = _fetch(conn, sql, [PAGE, after])
        if not rows:
            break
        after = int(rows[-1]['erp_row_key'])
        recs = [r for r in (_clean(kind, x) for x in rows) if r]
        for i in range(0, len(recs), 250):
            if q.enqueue(kind, source, recs[i:i + 250]):
                batches += 1
        if len(rows) < PAGE:
            break
    return batches


def load_state(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return {}


def save_state(path: Path, state: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(state), encoding='utf-8')
    tmp.replace(path)


LOOKBACK = 50  # same re-read window as the LAN pull, catches recently edited bills


def queue_invoices(conn, q, source, state_path: Path, start_key: int, max_invoices: int,
                   server_cursor: int | None = None) -> int:
    """Incremental by DI_KEY. The server cursor (shared with the LAN pull) wins when known,
    so the two sync paths never diverge; local state is only a fallback when offline."""
    inv_sql, item_sql = _sql('bplus_invoices.sql'), _sql('bplus_sales_items.sql')
    state = load_state(state_path)
    local = int(state.get('invoices_after_key', start_key))
    after = max(0, server_cursor - LOOKBACK) if server_cursor else local
    done, batches = 0, 0
    while done < max_invoices:
        page = min(100, max_invoices - done)
        rows = _fetch(conn, inv_sql, [page, after, after])
        if not rows:
            break
        recs = []
        for inv in rows:
            key = int(inv['di_key'])
            items = _fetch(conn, item_sql, [MAX_INVOICE_LINES + 1, key])
            if len(items) > MAX_INVOICE_LINES:
                raise RuntimeError(f"Invoice {inv.get('invoice_no')} has more than {MAX_INVOICE_LINES} lines")
            rec = _clean('invoices', inv)
            if rec:
                rec['items'] = [_clean('items', it) for it in items]
                rec['items_complete'] = True
                recs.append(rec)
        for i in range(0, len(recs), 25):
            if q.enqueue('invoices', source, recs[i:i + 25]):
                batches += 1
        after = int(rows[-1]['di_key'])
        state['invoices_after_key'] = after
        save_state(state_path, state)  # batches are already durable in the queue
        done += len(rows)
        if len(rows) < page:
            break
    return batches
