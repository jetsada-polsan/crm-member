"""Loopback client for the Windows Bplus read-only Bridge."""

import json
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.request


class LocalBridge:
    def __init__(self, script):
        self.script = script
        self.process = None
        self.lock = threading.Lock()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def ensure_running(self, port):
        with self.lock:
            for attempt in range(40):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.5):
                        return
                except (ConnectionRefusedError, TimeoutError):
                    if attempt == 0 and (self.process is None or self.process.poll() is not None):
                        self.process = subprocess.Popen(
                            ["powershell.exe", "-NoProfile", "-File", str(self.script)],
                            stdout=subprocess.DEVNULL,
                            stderr=subprocess.DEVNULL,
                        )
                    if self.process is not None and self.process.poll() is not None:
                        raise RuntimeError("Bridge เริ่มทำงานไม่สำเร็จ")
                    time.sleep(0.25)
        raise RuntimeError("Bridge ยังไม่เปิดพอร์ตบน Server 201")

    def request(self, cfg, path, method="GET"):
        port = int(cfg.get("BRIDGE_PORT", "18900"))
        token = cfg.get("BRIDGE_API_TOKEN", "").strip()
        if len(token) < 32:
            raise RuntimeError("ยังไม่ได้ตั้งค่า Bridge Token")
        self.ensure_running(port)
        request = urllib.request.Request(
            f"http://127.0.0.1:{port}{path}",
            headers={"X-Bridge-Token": token},
            data=b"" if method == "POST" else None,
            method=method,
        )
        try:
            with self.opener.open(request, timeout=30) as response:
                result = json.load(response)
        except urllib.error.HTTPError as error:
            raise RuntimeError(f"Bridge ตอบ HTTP {error.code} ({path.split('?')[0]})") from error
        if not isinstance(result, dict) or result.get("ok") is not True:
            raise RuntimeError("Bridge ไม่ยืนยันผลการอ่านข้อมูล")
        return result

    def customer_batches(self, cfg, limit=None):
        cursor = 0
        size = 250 if limit is None else min(limit, 250)
        while True:
            result = self.request(cfg, f"/export/customers?limit={size}&after_key={cursor}")
            records = result.get("records")
            if not isinstance(records, list) or len(records) > size:
                raise RuntimeError("จำนวนลูกหนี้ที่ Bridge ส่งกลับไม่ถูกต้อง")
            try:
                # The PowerShell bridge serializes SQL values as strings.
                keys = [int(str(row["erp_row_key"])) for row in records]
            except (KeyError, TypeError, ValueError) as error:
                raise RuntimeError("Bridge ส่งรหัสลูกหนี้ไม่ถูกต้อง") from error
            if keys != sorted(set(keys)) or (keys and keys[0] <= cursor):
                raise RuntimeError("ลำดับลูกหนี้จาก Bridge ไม่ถูกต้อง")
            if records:
                yield records
            if limit is not None or not result.get("has_more"):
                return
            next_cursor = result.get("next_cursor")
            if not keys or next_cursor != keys[-1]:
                raise RuntimeError("Bridge ไม่ได้ส่งตำแหน่งสำหรับอ่านชุดถัดไป")
            cursor = next_cursor

    def pages(self, cfg, kind, after_key=0, size=250, max_records=None):
        """Yield (records, next_cursor) pages of one export kind until the bridge has no more."""
        if kind not in ("customers", "products", "salespersons", "members", "invoices"):
            raise ValueError(f"Unsupported bridge kind {kind}")
        cursor = max(0, int(after_key))
        read = 0
        while True:
            result = self.request(cfg, f"/export/{kind}?limit={size}&after_key={cursor}")
            records = result.get("records")
            if not isinstance(records, list) or len(records) > size:
                raise RuntimeError(f"Bridge ส่ง {kind} กลับมาไม่ถูกต้อง")
            try:
                next_cursor = int(result.get("next_cursor") or 0)
            except (TypeError, ValueError) as error:
                raise RuntimeError(f"Bridge ส่งตำแหน่ง {kind} ไม่ถูกต้อง") from error
            if records:
                yield records, next_cursor
                read += len(records)
            if not result.get("has_more") or (max_records is not None and read >= max_records):
                return
            if next_cursor <= cursor:
                raise RuntimeError(f"Bridge cursor ของ {kind} ไม่ขยับ หยุดเพื่อไม่ให้อ่านซ้ำ")
            cursor = next_cursor

    def close(self):
        with self.lock:
            if self.process is not None and self.process.poll() is None:
                self.process.terminate()
                self.process.wait(timeout=5)
