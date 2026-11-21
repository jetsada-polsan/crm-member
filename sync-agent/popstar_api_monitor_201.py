#!/usr/bin/env python3
"""
POPSTAR CRM v4 — Standalone Python Web API & Sync Monitor for Server 201
========================================================================
Runs completely independently on its own dedicated port (default: 8770)
so it NEVER conflicts with or touches bplusAPI (port 8765) or any other app.

Zero external web framework required (uses Python 3 standard library + optional pyodbc).
Read-only SELECT access to Bplus SQL Server (192.168.88.200:1433).

Quick start on Server 201 (192.168.88.201):
  1. Copy the sync-agent folder to e.g. C:\popstar-crm-agent\
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

from agent.http_client import send_batch  # noqa: E402
from agent.queue import PendingQueue  # noqa: E402

APP_TITLE = "POPSTAR CRM Sync & API Monitor"
DEFAULT_PORT = 8770
SESSION_COOKIE = "popstar_201_sid"
VALID_SESSIONS: dict[str, float] = {}
SESSION_TTL_SEC = 12 * 3600

STATE_LOCK = threading.Lock()
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
        if k.startswith(("POPSTAR_", "SYNC_", "SQL_", "ERP_", "LOCAL_BRIDGE_")):
            cfg[k] = v
    return cfg


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
        f"Driver={{{driver}}};Server={server},{port};Database={database};"
        f"Uid={user};Pwd={pwd};Encrypt=yes;TrustServerCertificate=yes;Connection Timeout=10;"
    )


def test_bplus_sql(cfg: dict[str, str]) -> dict[str, Any]:
    t0 = time.perf_counter()
    server = cfg.get("SQL_SERVER", "192.168.88.200").strip()
    port = int(cfg.get("SQL_PORT", "1433") or 1433)
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
        with urllib.request.urlopen(req, timeout=6) as resp:
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
        if kind == "customers":
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
            try:
                reply = send_batch(base_url, token, r_kind, body_json)
                if reply.get("batch_id") != json.loads(body_json)["batch_id"]:
                    raise RuntimeError("Batch ID mismatch")
                q.mark_sent(row_id)
                sent += 1
            except Exception as exc:  # noqa: BLE001
                failed += 1
                last_err = str(exc)
                break

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
        log_event("ok" if res["ok"] else "error", f"sync:{kind}", f"sent={sent}, failed={failed}, err={last_err or '-'}")
        return res
    except Exception as exc:  # noqa: BLE001
        log_event("error", f"sync:{kind}", str(exc))
        return {"ok": False, "error": str(exc)}
    finally:
        with STATE_LOCK:
            AUTO_SYNC_STATE["running"] = False


def scheduler_loop() -> None:
    while True:
        time.sleep(20)
        cfg = load_env_files()
        with STATE_LOCK:
            enabled = AUTO_SYNC_STATE["enabled"]
            interval = max(5, int(AUTO_SYNC_STATE["interval_minutes"]))
            last_str = AUTO_SYNC_STATE["last_run_at"]
        if not enabled:
            continue
        due = True
        if last_str:
            try:
                last_dt = datetime.strptime(last_str, "%Y-%m-%d %H:%M:%S")
                due = (datetime.now() - last_dt).total_seconds() >= interval * 60
            except ValueError:
                due = True
        if due:
            run_push_sync("customers", cfg)


def render_login_html(error_msg: str = "") -> str:
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
<h1>เข้าสู่ระบบตรวจสอบ API</h1>
<p>หน้ามอนิเตอร์แยกอิสระสำหรับระบบ POPSTAR CRM (ไม่เกี่ยวกับ bplusAPI พอร์ต 8765)</p>
{err_html}
<label>รหัสผ่านผู้ดูแล (POPSTAR_WEB_PASSWORD)</label>
<input type="password" name="password" placeholder="กรอกรหัสผ่าน..." autofocus required>
<button type="submit">เข้าสู่หน้าตรวจสอบสถานะ</button>
</form></body></html>"""


def render_dashboard_html(port: int) -> str:
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
    <a class="btn" href="/health" target="_blank">JSON /health</a>
    <form method="POST" action="/logout" style="margin:0"><button type="submit">ออกจากระบบ</button></form>
  </div>
</div>

<div class="grid">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <strong>🗄️ Bplus SQL Server (192.168.88.200)</strong>
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
      <button class="primary" id="btnSyncCust" onclick="triggerSync('customers')">👥 ซิงก์ลูกหนี้</button>
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
  const btn = document.getElementById(kind==='customers'?'btnSyncCust':'btnFlush');
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


class MonitorHandler(BaseHTTPRequestHandler):
    server_port: int = DEFAULT_PORT

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
                "crm_target": cfg.get("SYNC_BASE_URL", ""),
            })
            return

        if path == "/login":
            self._send(200, render_login_html().encode("utf-8"), "text/html; charset=utf-8")
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
            pwd = (form.get("password") or [""])[0]
            expected = cfg.get("POPSTAR_WEB_PASSWORD", "Popstar2026!").strip()
            if hmac.compare_digest(pwd, expected):
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
                self._send(401, render_login_html("รหัสผ่านไม่ถูกต้อง").encode("utf-8"), "text/html; charset=utf-8")
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
