#!/usr/bin/env python3
"""
POPSTAR CRM v4 — Standalone Python Web API & Sync Monitor for Server 201
========================================================================
Runs completely independently on its own dedicated port (default: 8770)
so it NEVER conflicts with or touches bplusAPI (port 8765) or any other app.

Zero external web framework required (uses Python 3 standard library + optional pyodbc).
Read-only SELECT access to Bplus SQL Server (192.168.88.200:1433).

Quick start on Server 201 (192.168.88.201):
  1. Copy the sync-agent folder to e.g. D:/Xamp/htdocs/sync-agent
  2. Configure server201.env (or config.env)
  3. Double-click start-popstar-api-201.cmd
  4. Open http://192.168.88.201:8770/ in Chrome
"""

from __future__ import annotations

import hashlib
import hmac
import html
import http.cookies
import json
import os
import re
import secrets
import socket
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import deque
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

BASE_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(BASE_DIR))

from agent.http_client import get_invoice_cursor, open_crm_request, send_batch  # noqa: E402
from agent.local_bridge import LocalBridge  # noqa: E402
from agent.queue import PendingQueue  # noqa: E402

APP_TITLE = "POPSTAR CRM Sync & API Monitor"
DEFAULT_PORT = 8770
SESSION_COOKIE = "popstar_201_sid"
VALID_SESSIONS: dict[str, float] = {}
SESSION_TTL_SEC = 12 * 3600

STATE_LOCK = threading.Lock()
SETTINGS_LOCK = threading.Lock()
SETTINGS_PATH: Path | None = None
BPLUS_BRIDGE: LocalBridge | None = None
SQL_DRIVERS = ("ODBC Driver 18 for SQL Server", "ODBC Driver 17 for SQL Server", "SQL Server")
SETTINGS_FIELDS = ("SQL_SERVER", "SQL_PORT", "SQL_DATABASE", "SQL_ODBC_DRIVER", "SQL_USER", "SQL_PASSWORD", "SYNC_BASE_URL", "SYNC_API_TOKEN")
ACTIVITY_LOG: deque[dict[str, Any]] = deque(maxlen=80)
AUTO_SYNC_STATE: dict[str, Any] = {
    "enabled": False,
    "interval_minutes": 15,
    "running": False,
    "last_run_at": None,
    "last_result": None,
}


def load_env_files() -> dict[str, str]:
    cfg: dict[str, str] = {}
    for filename in ("config.env", "server201.env"):
        p = BASE_DIR / filename
        if not p.is_file():
            continue
        for raw_line in p.read_text(encoding="utf-8", errors="replace").splitlines():
            line = raw_line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            k = k.strip()
            v = v.strip().strip('"').strip("'")
            if k:
                cfg[k] = v
    # Environment variables always win
    for k, v in os.environ.items():
        if k.startswith(("POPSTAR_", "SYNC_", "SQL_", "ERP_", "LOCAL_BRIDGE_", "BRIDGE_")):
            cfg[k] = v
    cfg.update(read_web_settings())
    return cfg


def read_web_settings() -> dict[str, str]:
    if SETTINGS_PATH is None:
        return {}
    try:
        return json.loads(SETTINGS_PATH.read_text(encoding="utf-8"))
    except FileNotFoundError:
        return {}


def save_web_settings(form: dict[str, list[str]]) -> str:
    values = {key: form.get(key, [""])[0].strip() for key in SETTINGS_FIELDS}
    sql_server = values["SQL_SERVER"]
    if not re.fullmatch(r"[A-Za-z0-9.-]+", sql_server):
        return "กรุณากรอกชื่อเครื่องหรือ IPv4 ของ SQL Server"
    port = values["SQL_PORT"]
    if not re.fullmatch(r"[0-9]{1,5}", port) or not 1 <= int(port) <= 65535:
        return "พอร์ต SQL ต้องอยู่ระหว่าง 1–65535"
    if not values["SQL_DATABASE"] or not values["SQL_USER"] or values["SQL_ODBC_DRIVER"] not in SQL_DRIVERS:
        return "กรุณากรอกฐานข้อมูล ผู้ใช้ และเลือก ODBC Driver"

    values["SYNC_BASE_URL"] = values["SYNC_BASE_URL"].rstrip("/")
    token = values["SYNC_API_TOKEN"]
    if token and (len(token) < 32 or not token.isascii() or any(c.isspace() for c in token)):
        return "Push Token ต้องมีอย่างน้อย 32 ตัวอักษร และไม่มีช่องว่าง"

    with SETTINGS_LOCK:
        current = load_env_files()
        saved = read_web_settings()
        if values["SYNC_BASE_URL"] != current.get("SYNC_BASE_URL", "").rstrip("/"):
            try:
                url = urllib.parse.urlsplit(values["SYNC_BASE_URL"])
                valid_url = (url.scheme == "https" and url.hostname and not url.username
                             and url.password is None and not url.query and not url.fragment
                             and not any(c.isspace() for c in values["SYNC_BASE_URL"])
                             and url.port != 0)
            except ValueError:
                valid_url = False
            if not valid_url:
                return "กรุณากรอก CRM Base URL แบบ HTTPS โดยไม่ใส่ชื่อผู้ใช้ รหัสผ่าน หรือพารามิเตอร์"
            if not token:
                return "เมื่อเปลี่ยน CRM URL กรุณากรอก Push Token สำหรับ URL ใหม่"
        for key in ("SQL_PASSWORD", "SYNC_API_TOKEN"):
            if not values[key]:
                values.pop(key)
        if "SQL_PASSWORD" in values or "SQL_PASSWORD" in saved:
            values["SQL_CONNECTION_STRING"] = ""
        saved.update(values)
        assert SETTINGS_PATH is not None
        temporary = SETTINGS_PATH.with_suffix(".tmp")
        try:
            temporary.write_text(json.dumps(saved, ensure_ascii=False), encoding="utf-8")
            temporary.replace(SETTINGS_PATH)
        except OSError:
            return "บันทึกการตั้งค่าไม่สำเร็จ"
    return ""



def log_event(level: str, action: str, detail: str) -> None:
    with STATE_LOCK:
        ACTIVITY_LOG.appendleft({
            "time": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "level": level,
            "action": action,
            "detail": detail,
        })


def queue_path(cfg: dict[str, str]) -> Path:
    specified = cfg.get("SYNC_QUEUE_PATH", "").strip()
    if specified:
        return Path(specified)
    local = os.getenv("LOCALAPPDATA") or str(Path.home())
    return Path(local) / "PopstarCrmAgent201" / "queue.sqlite3"


def quote_odbc_value(value: str) -> str:
    return "{" + value.replace("}", "}}") + "}"


def get_sql_conn_string(cfg: dict[str, str]) -> str:
    direct = cfg.get("SQL_CONNECTION_STRING", "").strip()
    if direct:
        return direct
    server = cfg.get("SQL_SERVER", "192.168.88.200").strip()
    port = cfg.get("SQL_PORT", "1433").strip()
    database = cfg.get("SQL_DATABASE", "BPLUSERP_POPSTAR_2021").strip()
    user = cfg.get("SQL_USER", "").strip()
    pwd = cfg.get("SQL_PASSWORD", "").strip()
    driver = cfg.get("SQL_ODBC_DRIVER", "ODBC Driver 17 for SQL Server").strip()
    if not (server and database and user and pwd):
        return ""
    return (
        f"Driver={{{driver}}};Server={server},{port};Database={quote_odbc_value(database)};"
        f"Uid={quote_odbc_value(user)};Pwd={quote_odbc_value(pwd)};"
        "Encrypt=yes;TrustServerCertificate=yes;Connection Timeout=10;"
    )


def test_bplus_sql(cfg: dict[str, str]) -> dict[str, Any]:
    t0 = time.perf_counter()
    server = cfg.get("SQL_SERVER", "192.168.88.200").strip()
    port = int(cfg.get("SQL_PORT", "1433") or 1433)
    if BPLUS_BRIDGE is not None:
        try:
            result = BPLUS_BRIDGE.request(cfg, "/test-connection", method="POST")
            return {
                "ok": True, "tcp_ok": True, "server": f"{server}:{port}",
                "latency_ms": round((time.perf_counter() - t0) * 1000, 1),
                "message": result.get("message", "เชื่อมต่อ Bplus ผ่าน Bridge สำเร็จ"),
            }
        except (OSError, RuntimeError, ValueError) as error:
            return {
                "ok": False, "tcp_ok": False, "server": f"{server}:{port}",
                "latency_ms": round((time.perf_counter() - t0) * 1000, 1),
                "message": str(error),
            }

    conn_str = get_sql_conn_string(cfg)

    # First verify raw TCP reachability to Bplus SQL Server
    tcp_ok = False
    tcp_err = None
    try:
        with socket.create_connection((server, port), timeout=4):
            tcp_ok = True
    except OSError as exc:
        tcp_err = str(exc)

    if not tcp_ok:
        ms = round((time.perf_counter() - t0) * 1000, 1)
        return {
            "ok": False,
            "tcp_ok": False,
            "server": f"{server}:{port}",
            "latency_ms": ms,
            "message": f"TCP connect failed: {tcp_err}",
        }

    if not conn_str:
        ms = round((time.perf_counter() - t0) * 1000, 1)
        return {
            "ok": False,
            "tcp_ok": True,
            "server": f"{server}:{port}",
            "latency_ms": ms,
            "message": "TCP เปิดปกติ แต่ยังไม่ได้ใส่ SQL_USER/SQL_PASSWORD หรือ SQL_CONNECTION_STRING ใน server201.env",
        }

    try:
        import pyodbc  # type: ignore
    except ImportError:
        ms = round((time.perf_counter() - t0) * 1000, 1)
        return {
            "ok": False,
            "tcp_ok": True,
            "server": f"{server}:{port}",
            "latency_ms": ms,
            "message": "TCP เปิดปกติ แต่ยังไม่ได้ติดตั้ง pyodbc (รัน: pip install pyodbc)",
        }

    try:
        with pyodbc.connect(conn_str, readonly=True, timeout=8) as conn:
            cur = conn.cursor()
            cur.execute("SELECT DB_NAME(), @@SERVERNAME, COUNT(*) FROM ARFILE")
            row = cur.fetchone()
        ms = round((time.perf_counter() - t0) * 1000, 1)
        return {
            "ok": True,
            "tcp_ok": True,
            "server": f"{server}:{port}",
            "database": str(row[0]) if row else "",
            "server_name": str(row[1]) if row else "",
            "ar_count": int(row[2]) if row else 0,
            "latency_ms": ms,
            "message": f"เชื่อมต่อ Bplus SQL สำเร็จ (Read-Only · ARFILE {int(row[2]):,} ราย)",
        }
    except Exception as exc:  # noqa: BLE001
        ms = round((time.perf_counter() - t0) * 1000, 1)
        return {
            "ok": False,
            "tcp_ok": True,
            "server": f"{server}:{port}",
            "latency_ms": ms,
            "message": f"SQL error: {exc}",
        }


def test_crm_api(cfg: dict[str, str]) -> dict[str, Any]:
    t0 = time.perf_counter()
    base = cfg.get("SYNC_BASE_URL", "http://popstar.localhost").strip().rstrip("/")
    url = f"{base}/api/health"
    try:
        req = urllib.request.Request(url, headers={"Accept": "application/json"}, method="GET")
        with open_crm_request(base, req, timeout=6) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            code = resp.status
        ms = round((time.perf_counter() - t0) * 1000, 1)
        return {
            "ok": code == 200,
            "url": url,
            "http_status": code,
            "latency_ms": ms,
            "body": body[:240],
            "token_configured": len(cfg.get("SYNC_API_TOKEN", "").strip()) >= 32,
        }
    except Exception as exc:  # noqa: BLE001
        ms = round((time.perf_counter() - t0) * 1000, 1)
        return {
            "ok": False,
            "url": url,
            "http_status": 0,
            "latency_ms": ms,
            "body": str(exc)[:240],
            "token_configured": len(cfg.get("SYNC_API_TOKEN", "").strip()) >= 32,
        }


MASTER_KINDS = ("salespersons", "customers", "products", "members")
PUSH_KINDS = MASTER_KINDS + ("invoices",)
INVOICE_LOOKBACK = 50  # same re-read window as the CRM's LAN pull, catches recently edited bills
MAX_INVOICE_LINES = 2000


def _push_state_path(cfg: dict[str, str]) -> Path:
    return queue_path(cfg).with_name("push_state.json")


def _load_push_state(cfg: dict[str, str]) -> dict[str, Any]:
    try:
        return json.loads(_push_state_path(cfg).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def _save_push_state(cfg: dict[str, str], state: dict[str, Any]) -> None:
    path = _push_state_path(cfg)
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps(state), encoding="utf-8")
    tmp.replace(path)


def _queue_from_bridge(kind: str, cfg: dict[str, str], q: PendingQueue, source: str, base_url: str, token: str) -> int:
    """Queue one kind read through the Windows Bridge (it holds the read-only SQL login on Server 201)."""
    assert BPLUS_BRIDGE is not None
    queued = 0
    if kind != "invoices":
        for records, _ in BPLUS_BRIDGE.pages(cfg, kind, size=250):
            if q.enqueue(kind, source, records):
                queued += 1
        return queued

    state = _load_push_state(cfg)
    try:
        server_cursor = get_invoice_cursor(base_url, token, source)
        after_key = max(0, server_cursor - INVOICE_LOOKBACK)
    except Exception as exc:  # noqa: BLE001
        after_key = int(state.get("invoices_after_key", 0))
        log_event("warn", "sync:invoices", f"อ่าน cursor จาก CRM ไม่ได้ ใช้ค่าในเครื่องแทน ({after_key}): {exc}")
    if after_key <= 0:
        raise RuntimeError("ไม่มีตำแหน่งบิลเริ่มต้น จะไม่อ่านบิลทั้งหมดตั้งแต่ต้นโดยอัตโนมัติ")

    max_invoices = int(cfg.get("POPSTAR_INVOICE_MAX_PER_RUN", "2000") or 2000)
    for records, next_cursor in BPLUS_BRIDGE.pages(cfg, "invoices", after_key=after_key, size=100, max_records=max_invoices):
        for record in records:
            if len(record.get("items") or []) > MAX_INVOICE_LINES:
                raise RuntimeError(f"บิล {record.get('invoice_no')} มีเกิน {MAX_INVOICE_LINES} รายการ")
        for offset in range(0, len(records), 25):
            if q.enqueue("invoices", source, records[offset:offset + 25]):
                queued += 1
        state["invoices_after_key"] = next_cursor
        _save_push_state(cfg, state)  # the batches are already durable in the queue
    return queued


def run_push_sync(kind: str, cfg: dict[str, str]) -> dict[str, Any]:
    with STATE_LOCK:
        if AUTO_SYNC_STATE["running"]:
            return {"ok": False, "error": "กำลังซิงก์รอบอื่นอยู่ กรุณารอสักครู่"}
        AUTO_SYNC_STATE["running"] = True

    try:
        base_url = cfg.get("SYNC_BASE_URL", "").strip().rstrip("/")
        token = cfg.get("SYNC_API_TOKEN", "").strip()
        source = cfg.get("SYNC_SOURCE", "BPLUS").strip() or "BPLUS"
        if not base_url or len(token) < 32:
            msg = "กรุณาตั้งค่า SYNC_BASE_URL และ SYNC_API_TOKEN (>= 32 ตัวอักษร) ใน server201.env"
            log_event("error", f"sync:{kind}", msg)
            return {"ok": False, "error": msg}

        q = PendingQueue(queue_path(cfg))
        queued = 0
        test_one = kind == "customers-test"
        test_batch = None
        kinds = {"all": PUSH_KINDS, "masters": MASTER_KINDS}.get(kind, (kind,) if kind in PUSH_KINDS else ())
        if test_one and BPLUS_BRIDGE is not None:
            for records in BPLUS_BRIDGE.customer_batches(cfg, limit=1):
                test_batch = q.enqueue("customers", source, records)
                queued += 1
            if test_batch is None:
                return {"ok": False, "error": "Bridge ไม่พบลูกหนี้สำหรับทดสอบ"}
        elif kinds and BPLUS_BRIDGE is not None:
            for k in kinds:
                queued += _queue_from_bridge(k, cfg, q, source, base_url, token)
        elif kind == "customers":
            conn_str = get_sql_conn_string(cfg)
            if not conn_str:
                return {"ok": False, "error": "ยังไม่ได้ตั้งค่า SQL_CONNECTION_STRING"}
            from agent.sql_reader import read_customers
            rows = read_customers(conn_str)
            for offset in range(0, len(rows), 250):
                q.enqueue("customers", source, rows[offset:offset + 250])
                queued += 1
        elif kind != "flush":
            return {"ok": False, "error": f"ยังไม่รองรับประเภท {kind} ในโหมด Push"}

        sent = 0
        failed = 0
        last_err = None
        for row_id, r_kind, body_json in q.pending():
            batch_id = json.loads(body_json)["batch_id"]
            if test_one and batch_id != test_batch:
                continue
            try:
                reply = send_batch(base_url, token, r_kind, body_json)
                if reply.get("batch_id") != batch_id:
                    raise RuntimeError("Batch ID mismatch")
                q.mark_sent(row_id)
                sent += 1
            except Exception as exc:  # noqa: BLE001
                failed += 1
                last_err = f"{r_kind}: {exc}"
                break
        q.purge_sent(keep_days=3)

        res = {
            "ok": failed == 0,
            "kind": kind,
            "batches_queued": queued,
            "batches_sent": sent,
            "batches_failed": failed,
            "queue_counts": q.counts(),
            "error": last_err,
        }
        AUTO_SYNC_STATE["last_run_at"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        AUTO_SYNC_STATE["last_result"] = res
        log_event("ok" if res["ok"] else "error", f"sync:{kind}", f"queued={queued}, sent={sent}, failed={failed}, err={last_err or '-'}")
        return res
    except Exception as exc:  # noqa: BLE001
        log_event("error", f"sync:{kind}", str(exc))
        return {"ok": False, "error": str(exc)}
    finally:
        with STATE_LOCK:
            AUTO_SYNC_STATE["running"] = False


def scheduler_loop() -> None:
    """Invoices every POPSTAR_AUTO_SYNC_MINUTES; master tables every POPSTAR_MASTER_SYNC_MINUTES."""
    # Attempt times, not success times: a failing run waits for the next slot instead of retrying every loop.
    last_attempt_at = 0.0
    last_masters_attempt_at = 0.0
    while True:
        time.sleep(20)
        with STATE_LOCK:
            enabled = AUTO_SYNC_STATE["enabled"]
            interval = max(5, int(AUTO_SYNC_STATE["interval_minutes"]))
        if not enabled or time.time() - last_attempt_at < interval * 60:
            continue
        last_attempt_at = time.time()
        cfg = load_env_files()
        master_minutes = max(15, int(cfg.get("POPSTAR_MASTER_SYNC_MINUTES", "60") or 60))
        if BPLUS_BRIDGE is not None and time.time() - last_masters_attempt_at >= master_minutes * 60:
            last_masters_attempt_at = time.time()
            run_push_sync("masters", cfg)
        run_push_sync("invoices" if BPLUS_BRIDGE is not None else "customers", cfg)


def render_login_html(error_msg: str = "", *, setup: bool = False) -> str:
    title = "ตั้งรหัสผ่านครั้งแรก" if setup else "เข้าสู่ระบบตรวจสอบ API"
    password_label = "รหัสผ่านตั้งต้น" if setup else "รหัสผ่านผู้ดูแล"
    new_fields = (
        '<label for="new-password">รหัสผ่านใหม่ (อย่างน้อย 8 ตัวอักษร)</label>'
        '<input id="new-password" type="password" name="new_password" autocomplete="new-password" minlength="8" maxlength="256" required>'
        '<label for="confirm-password">ยืนยันรหัสผ่านใหม่</label>'
        '<input id="confirm-password" type="password" name="confirm_password" autocomplete="new-password" required>'
    ) if setup else ""
    button = "บันทึกรหัสผ่านและเข้าสู่ระบบ" if setup else "เข้าสู่หน้าตรวจสอบสถานะ"
    err_html = f'<div class="err">{html.escape(error_msg)}</div>' if error_msg else ""
    return f"""<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>เข้าสู่ระบบ · {APP_TITLE}</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body{{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f172a 0%,#0b4678 100%);font-family:'Prompt',system-ui,sans-serif;padding:20px;box-sizing:border-box}}
.card{{background:#fff;border-radius:20px;padding:30px;max-width:420px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.25)}}
h1{{margin:0 0 6px;font-size:21px;color:#0f172a}} p{{margin:0 0 18px;color:#64748b;font-size:13.5px}}
label{{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#334155}}
input{{width:100%;box-sizing:border-box;padding:11px 14px;border:1px solid #cbd5e1;border-radius:12px;font-family:inherit;font-size:14px;margin-bottom:14px}}
button{{width:100%;padding:11px 16px;border:0;border-radius:12px;background:#0284c7;color:#fff;font-family:inherit;font-size:15px;font-weight:600;cursor:pointer}}
.err{{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:10px 12px;border-radius:10px;font-size:13px;margin-bottom:12px}}
.tag{{display:inline-block;background:#e0f2fe;color:#0369a1;font-size:12px;font-weight:600;padding:4px 10px;border-radius:999px;margin-bottom:10px}}
</style></head><body><form class="card" method="POST" action="/login">
<span class="tag">POPSTAR CRM v4 · Server 201 Agent</span>
<h1>{title}</h1>
<p>หน้ามอนิเตอร์แยกอิสระสำหรับระบบ POPSTAR CRM (ไม่เกี่ยวกับ bplusAPI พอร์ต 8765)</p>
{err_html}
<label for="password">{password_label}</label>
<input id="password" type="password" name="password" autocomplete="current-password" autofocus required>
{new_fields}
<button type="submit">{button}</button>
</form></body></html>"""


def render_dashboard_html(port: int) -> str:
    test_button = """<button id="btnSyncTest" onclick="triggerSync('customers-test')">ทดสอบ 1 รายการ</button>""" if BPLUS_BRIDGE else ""
    all_buttons = (
        """<button class="primary" id="btnSyncInvoices" onclick="triggerSync('invoices')">🧾 ซิงก์บิลล่าสุด</button>"""
        """<button id="btnSyncAll" onclick="triggerSync('all')">🔄 ซิงก์ทั้งหมด</button>"""
    ) if BPLUS_BRIDGE else ""
    cust_class = "" if BPLUS_BRIDGE else ' class="primary"'
    settings_link = '<a class="btn" href="/settings">ตั้งค่าการเชื่อมต่อ</a>' if SETTINGS_PATH else ""
    return f"""<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{APP_TITLE} (:{port})</title>
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
body{{margin:0;background:#f1f5f9;font-family:'Prompt',system-ui,sans-serif;color:#0f172a}}
.wrap{{max-width:1100px;margin:0 auto;padding:24px 18px}}
.head{{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}}
.head h1{{margin:0;font-size:22px}} .sub{{color:#64748b;font-size:13.5px}}
.grid{{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:14px;margin-bottom:16px}}
.card{{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:18px;box-shadow:0 4px 14px rgba(15,23,42,.04)}}
.row{{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13.5px}}
.row:last-child{{border-bottom:0}}
.tag{{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}}
.tag.ok{{background:#dcfce7;color:#166534}} .tag.err{{background:#fee2e2;color:#991b1b}} .tag.info{{background:#e0f2fe;color:#075985}}
.btns{{display:flex;gap:8px;flex-wrap:wrap}}
button,a.btn{{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none}}
button.primary{{background:#0284c7;border-color:#0284c7;color:#fff}}
button:disabled{{opacity:.55;cursor:wait}}
table{{width:100%;border-collapse:collapse;font-size:13px}} th,td{{text-align:left;padding:8px 10px;border-bottom:1px solid #f1f5f9}}
th{{color:#64748b;font-weight:600}} code{{background:#f1f5f9;padding:2px 6px;border-radius:6px;font-size:12px}}
</style></head><body><div class="wrap">
<div class="head">
  <div>
    <h1>🩺 {APP_TITLE}</h1>
    <div class="sub">พอร์ต {port} (แยกอิสระสำหรับ POPSTAR CRM v4 — Read-Only Bplus SQL)</div>
  </div>
  <div class="btns">
    <button class="primary" onclick="refreshAll(true)">⟳ ตรวจสอบทุกจุดทันที</button>
    {settings_link}
    <a class="btn" href="/health" target="_blank">JSON /health</a>
    <form method="POST" action="/logout" style="margin:0"><button type="submit">ออกจากระบบ</button></form>
  </div>
</div>

<div class="grid">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <strong>🗄️ Bplus SQL Server</strong>
      <span id="sqlTag" class="tag info">กำลังตรวจ...</span>
    </div>
    <div class="row"><span>Server</span><b id="sqlSrv">-</b></div>
    <div class="row"><span>Latency</span><b id="sqlMs">-</b></div>
    <div class="row"><span>รายละเอียด</span><span id="sqlMsg" style="text-align:right">-</span></div>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <strong>🌐 POPSTAR CRM API</strong>
      <span id="crmTag" class="tag info">กำลังตรวจ...</span>
    </div>
    <div class="row"><span>Health URL</span><code id="crmUrl">-</code></div>
    <div class="row"><span>Latency</span><b id="crmMs">-</b></div>
    <div class="row"><span>Push Token</span><b id="crmTok">-</b></div>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <strong>📦 คิวส่งข้อมูล &amp; สั่งซิงก์</strong>
      <span id="autoTag" class="tag info">Manual</span>
    </div>
    <div class="row"><span>คิวรอส่ง (Pending)</span><b id="qPending">0</b></div>
    <div class="row"><span>ส่งสำเร็จ (Sent)</span><b id="qSent">0</b></div>
    <div class="row"><span>ซิงก์ล่าสุด</span><b id="lastRun">-</b></div>
    <div class="btns" style="margin-top:12px">
      {all_buttons}
      <button{cust_class} id="btnSyncCust" onclick="triggerSync('customers')">👥 ซิงก์ลูกหนี้</button>
      {test_button}
      <button id="btnFlush" onclick="triggerSync('flush')">📤 ส่งคิวค้าง</button>
    </div>
  </div>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <strong>📜 บันทึกการทำงานล่าสุด (Activity Log)</strong>
    <span class="sub" id="checkedAt">-</span>
  </div>
  <table>
    <thead><tr><th>เวลา</th><th>สถานะ</th><th>คำสั่ง</th><th>รายละเอียด</th></tr></thead>
    <tbody id="logBody"><tr><td colspan="4" style="color:#64748b">กำลังโหลด...</td></tr></tbody>
  </table>
</div>
</div>
<script>
async function refreshAll(testSql=false){{
  const res = await fetch('/api/status?test_sql=' + (testSql ? '1' : '0'));
  if(res.status === 401){{ location.href='/login'; return; }}
  const d = await res.json();
  document.getElementById('checkedAt').textContent = 'ตรวจล่าสุด: ' + d.checked_at;
  const s = d.bplus_sql || {{}};
  document.getElementById('sqlTag').className = 'tag ' + (s.ok ? 'ok' : 'err');
  document.getElementById('sqlTag').textContent = s.ok ? 'ONLINE' : (s.tcp_ok ? 'CONFIG NEEDED' : 'OFFLINE');
  document.getElementById('sqlSrv').textContent = s.server || '-';
  document.getElementById('sqlMs').textContent = (s.latency_ms ?? '-') + ' ms';
  document.getElementById('sqlMsg').textContent = s.message || '-';

  const c = d.crm_api || {{}};
  document.getElementById('crmTag').className = 'tag ' + (c.ok ? 'ok' : 'err');
  document.getElementById('crmTag').textContent = c.ok ? '200 OK' : 'UNREACHABLE';
  document.getElementById('crmUrl').textContent = c.url || '-';
  document.getElementById('crmMs').textContent = (c.latency_ms ?? '-') + ' ms';
  document.getElementById('crmTok').textContent = c.token_configured ? 'พร้อมใช้งาน (>=32 chars)' : 'ยังไม่ได้ตั้งค่า';

  const qc = d.queue_counts || {{}};
  document.getElementById('qPending').textContent = qc.pending || 0;
  document.getElementById('qSent').textContent = qc.sent || 0;
  document.getElementById('lastRun').textContent = d.auto_sync?.last_run_at || 'ยังไม่เคยซิงก์';
  document.getElementById('autoTag').textContent = d.auto_sync?.enabled ? ('Auto ทุก ' + d.auto_sync.interval_minutes + ' นาที') : 'Manual';

  const rows = (d.logs || []).map(l => `<tr><td>${{l.time}}</td><td><span class="tag ${{l.level==='ok'?'ok':'err'}}">${{l.level}}</span></td><td><code>${{l.action}}</code></td><td>${{l.detail}}</td></tr>`).join('');
  document.getElementById('logBody').innerHTML = rows || '<tr><td colspan="4" style="color:#64748b">ยังไม่มีรายการบันทึก</td></tr>';
}}
async function triggerSync(kind){{
  const btn = document.getElementById({{customers:'btnSyncCust','customers-test':'btnSyncTest',invoices:'btnSyncInvoices',all:'btnSyncAll'}}[kind] || 'btnFlush');
  btn.disabled = true;
  try {{
    const r = await fetch('/api/sync/' + kind, {{ method: 'POST' }});
    const j = await r.json();
    alert(j.ok ? ('สำเร็จ: ส่ง ' + j.batches_sent + ' ชุด') : ('ไม่สำเร็จ: ' + (j.error || 'unknown')));
    await refreshAll(false);
  }} finally {{ btn.disabled = false; }}
}}
refreshAll(true);
setInterval(() => refreshAll(false), 15000);
</script></body></html>"""


def render_settings_html(cfg: dict[str, str], error: str = "") -> str:
    def field(key, label, default="", kind="text"):
        value = "" if kind == "password" else html.escape(cfg.get(key, default), quote=True)
        placeholder = ' placeholder="เว้นว่างเพื่อใช้ค่าเดิม"' if kind == "password" else ""
        autocomplete = "new-password" if kind == "password" else "off"
        return f'<label>{label}<input name="{key}" type="{kind}" value="{value}" autocomplete="{autocomplete}"{placeholder}></label>'

    driver = cfg.get("SQL_ODBC_DRIVER", "ODBC Driver 17 for SQL Server")
    options = "".join(
        f'<option{" selected" if name == driver else ""}>{name}</option>' for name in SQL_DRIVERS
    )
    error_html = f'<p role="alert">{html.escape(error)}</p>' if error else ""
    bridge_note = "<p>Server 201 ใช้บัญชี SQL ที่ตั้งไว้ใน Windows Bridge</p>" if BPLUS_BRIDGE else ""
    return f"""<!doctype html><html lang="th"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ตั้งค่าการเชื่อมต่อ · {APP_TITLE}</title>
<style>
body{{background:#eff4f8;color:#0f172a;font:16px system-ui,sans-serif;margin:24px}}
main{{max-width:620px;margin:auto;background:white;padding:24px;border-radius:16px}}
fieldset{{border:1px solid #dbe4ed;border-radius:12px;margin:0 0 16px;padding:16px}}
label{{display:block;margin-bottom:12px}}
input,select{{display:block;box-sizing:border-box;width:100%;padding:9px;border:1px solid #bac9d8;border-radius:8px;font:inherit}}
button{{padding:10px 16px;background:#027cac;color:white;border:0;border-radius:8px;font:inherit}}
a{{color:#025c85;margin-left:12px}} [role=alert]{{color:#a51d21}}
</style></head><body><main>
<h1>ตั้งค่าการเชื่อมต่อ</h1>{error_html}
<form method="post" action="/settings">
<fieldset><legend>Bplus SQL Server</legend>{bridge_note}
{field("SQL_SERVER", "SQL Server", "192.168.88.200")}
{field("SQL_PORT", "พอร์ต SQL", "1433", "number")}
{field("SQL_DATABASE", "ชื่อฐานข้อมูล", "BPLUSERP_POPSTAR_2021")}
<label>ODBC Driver<select name="SQL_ODBC_DRIVER">{options}</select></label>
{field("SQL_USER", "บัญชี SQL แบบอ่านอย่างเดียว")}
{field("SQL_PASSWORD", "รหัสผ่าน SQL", kind="password")}
</fieldset>
<fieldset><legend>POPSTAR CRM API</legend>
{field("SYNC_BASE_URL", "CRM Base URL (HTTPS)", kind="url")}
{field("SYNC_API_TOKEN", "Push Token", kind="password")}
</fieldset>
<button type="submit">บันทึกการตั้งค่า</button><a href="/">กลับ Dashboard</a>
</form></main></body></html>"""



class MonitorHandler(BaseHTTPRequestHandler):
    server_port: int = DEFAULT_PORT

    def login_setup_required(self) -> bool:
        return False

    def validate_login(self, form: dict[str, list[str]], cfg: dict[str, str]) -> tuple[int, str]:
        pwd = form.get("password", [""])[0]
        expected = cfg.get("POPSTAR_WEB_PASSWORD", "Popstar2026!").strip()
        if hmac.compare_digest(pwd.encode("utf-8"), expected.encode("utf-8")):
            return 200, ""
        return 401, "รหัสผ่านไม่ถูกต้อง"

    def _send_login(self, code: int = 200, error: str = "") -> None:
        body = render_login_html(error, setup=self.login_setup_required()).encode("utf-8")
        self._send(code, body, "text/html; charset=utf-8", {"Cache-Control": "no-store"})

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002
        return  # Keep console clean

    def _send(self, code: int, body: bytes, content_type: str, extra_headers: dict[str, str] | None = None) -> None:
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        if extra_headers:
            for k, v in extra_headers.items():
                self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def _json(self, code: int, data: Any) -> None:
        self._send(code, json.dumps(data, ensure_ascii=False).encode("utf-8"), "application/json; charset=utf-8")

    def _is_authenticated(self) -> bool:
        raw_cookie = self.headers.get("Cookie", "")
        if not raw_cookie:
            return False
        jar = http.cookies.SimpleCookie()
        try:
            jar.load(raw_cookie)
        except Exception:  # noqa: BLE001
            return False
        morsel = jar.get(SESSION_COOKIE)
        if not morsel:
            return False
        exp = VALID_SESSIONS.get(morsel.value)
        if not exp or exp < time.time():
            VALID_SESSIONS.pop(morsel.value, None)
            return False
        return True

    def do_GET(self) -> None:  # noqa: N802
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path.rstrip("/") or "/"
        cfg = load_env_files()

        if path == "/health":
            self._json(200, {
                "ok": True,
                "service": "popstar-crm-agent-201",
                "port": self.server_port,
                "time": datetime.now().isoformat(),
                "sql_configured": bool(get_sql_conn_string(cfg)),
                "bplus_method": "bridge" if BPLUS_BRIDGE else "odbc",
                "crm_target": cfg.get("SYNC_BASE_URL", ""),
            })
            return

        if path == "/login":
            self._send_login()
            return

        if not self._is_authenticated():
            if path.startswith("/api/"):
                self._json(401, {"ok": False, "error": "Unauthorized"})
            else:
                self._send(302, b"", "text/plain", {"Location": "/login"})
            return

        if path == "/":
            self._send(200, render_dashboard_html(self.server_port).encode("utf-8"), "text/html; charset=utf-8")
            return

        if path == "/settings" and SETTINGS_PATH:
            self._send(200, render_settings_html(cfg).encode("utf-8"), "text/html; charset=utf-8", {"Cache-Control": "no-store"})
            return

        if path == "/api/status":
            qs = urllib.parse.parse_qs(parsed.query)
            q = PendingQueue(queue_path(cfg))
            with STATE_LOCK:
                logs = list(ACTIVITY_LOG)
                auto_copy = dict(AUTO_SYNC_STATE)
            self._json(200, {
                "ok": True,
                "checked_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "bplus_sql": test_bplus_sql(cfg),
                "crm_api": test_crm_api(cfg),
                "queue_counts": q.counts(),
                "auto_sync": auto_copy,
                "logs": logs,
                "full_check": qs.get("test_sql", ["0"])[0] == "1",
            })
            return

        self._json(404, {"ok": False, "error": "Not found"})

    def do_POST(self) -> None:  # noqa: N802
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path.rstrip("/") or "/"
        cfg = load_env_files()
        length = int(self.headers.get("Content-Length", "0") or 0)
        raw_body = self.rfile.read(min(length, 65536)).decode("utf-8", errors="replace") if length > 0 else ""

        if path == "/login":
            form = urllib.parse.parse_qs(raw_body)
            code, error = self.validate_login(form, cfg)
            if code == 200:
                sid = secrets.token_hex(24)
                VALID_SESSIONS[sid] = time.time() + SESSION_TTL_SEC
                log_event("ok", "login", f"เข้าสู่ระบบจาก {self.client_address[0]}")
                self._send(
                    302,
                    b"",
                    "text/plain",
                    {
                        "Location": "/",
                        "Set-Cookie": f"{SESSION_COOKIE}={sid}; HttpOnly; SameSite=Lax; Path=/",
                    },
                )
            else:
                self._send_login(code, error)
            return

        if path == "/logout":
            self._send(
                302,
                b"",
                "text/plain",
                {
                    "Location": "/login",
                    "Set-Cookie": f"{SESSION_COOKIE}=; Max-Age=0; HttpOnly; Path=/",
                },
            )
            return

        if not self._is_authenticated():
            self._json(401, {"ok": False, "error": "Unauthorized"})
            return

        if path == "/settings" and SETTINGS_PATH:
            if self.headers.get("Origin") != f"http://{self.headers.get('Host')}":
                self._json(403, {"ok": False, "error": "Invalid request origin"})
                return
            form = urllib.parse.parse_qs(raw_body)
            error = save_web_settings(form)
            if error:
                values = {key: form[key][0] for key in SETTINGS_FIELDS if key in form and key not in ("SQL_PASSWORD", "SYNC_API_TOKEN")}
                body = render_settings_html({**cfg, **values}, error).encode("utf-8")
                self._send(400, body, "text/html; charset=utf-8", {"Cache-Control": "no-store"})
            else:
                log_event("ok", "settings", "บันทึกการตั้งค่าการเชื่อมต่อ")
                self._send(303, b"", "text/plain", {"Location": "/"})
            return

        if path.startswith("/api/sync/"):
            kind = path.split("/")[-1]
            res = run_push_sync(kind, cfg)
            self._json(200 if res.get("ok") else 422, res)
            return

        self._json(404, {"ok": False, "error": "Not found"})


def main() -> int:
    cfg = load_env_files()
    host = cfg.get("POPSTAR_AGENT_HOST", "0.0.0.0").strip() or "0.0.0.0"
    port = int(cfg.get("POPSTAR_AGENT_PORT", str(DEFAULT_PORT)) or DEFAULT_PORT)
    AUTO_SYNC_STATE["enabled"] = cfg.get("POPSTAR_AUTO_SYNC", "false").lower() in ("1", "true", "yes")
    AUTO_SYNC_STATE["interval_minutes"] = int(cfg.get("POPSTAR_AUTO_SYNC_MINUTES", "15") or 15)

    MonitorHandler.server_port = port
    threading.Thread(target=scheduler_loop, daemon=True).start()
    log_event("ok", "startup", f"เริ่มทำงานบน {host}:{port} (Read-Only Bplus SQL)")

    httpd = ThreadingHTTPServer((host, port), MonitorHandler)
    print(f"POPSTAR CRM Python API Monitor running at http://127.0.0.1:{port}/ (LAN: http://192.168.88.201:{port}/)")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
