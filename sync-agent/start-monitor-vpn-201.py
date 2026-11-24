"""Start the Server 201 Monitor through the VPN gateway."""
import hashlib
import hmac
import os
import secrets
import sys
import threading
from pathlib import Path

import popstar_api_monitor_201 as monitor
from agent.http_client import HOME_CRM_VPN_URL
from agent.local_bridge import LocalBridge

SERVER_ADDRESS = "192.168.88.201"
VPN_GATEWAY = "192.168.88.1"


class VpnMonitorServer(monitor.ThreadingHTTPServer):
    def verify_request(self, request, client_address):
        return client_address[0] == VPN_GATEWAY


class VpnMonitorHandler(monitor.MonitorHandler):
    password_file: Path
    password_lock = threading.Lock()

    def login_setup_required(self):
        return not self.password_file.read_text(encoding="utf-8").startswith("pbkdf2_sha256:")

    @staticmethod
    def password_hash(password, salt):
        return hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, 600_000).hex()

    def validate_login(self, form, cfg):
        password = form.get("password", [""])[0]
        with self.password_lock:
            saved = self.password_file.read_text(encoding="utf-8").strip()
            if saved.startswith("pbkdf2_sha256:"):
                _, salt, expected = saved.split(":")
                if hmac.compare_digest(self.password_hash(password, bytes.fromhex(salt)), expected):
                    return 200, ""
                return 401, "รหัสผ่านไม่ถูกต้อง"

            if not hmac.compare_digest(password.encode("utf-8"), saved.encode("utf-8")):
                return 401, "รหัสผ่านตั้งต้นไม่ถูกต้อง"
            new_password = form.get("new_password", [""])[0]
            if not 8 <= len(new_password) <= 256:
                return 400, "รหัสผ่านใหม่ต้องมี 8–256 ตัวอักษร"
            if new_password != form.get("confirm_password", [""])[0] or new_password == password:
                return 400, "กรุณายืนยันรหัสผ่านใหม่ให้ตรงกันและใช้รหัสที่ต่างจากรหัสตั้งต้น"

            salt = secrets.token_bytes(16)
            pending_file = self.password_file.with_suffix(".pending")
            try:
                pending_file.write_text(
                    f"pbkdf2_sha256:{salt.hex()}:{self.password_hash(new_password, salt)}\n",
                    encoding="utf-8",
                )
                pending_file.replace(self.password_file)
            except OSError:
                return 500, "บันทึกรหัสผ่านใหม่ไม่สำเร็จ กรุณาลองอีกครั้ง"
            return 200, ""


def main():
    password_file = Path(os.environ["LOCALAPPDATA"]) / "PopstarCrmAgent201" / "monitor-vpn-password.txt"
    password_file.parent.mkdir(parents=True, exist_ok=True)
    try:
        with password_file.open("x", encoding="utf-8") as handle:
            handle.write(secrets.token_urlsafe(32) + "\n")
    except FileExistsError:
        pass

    os.environ.update(
        POPSTAR_AGENT_HOST=SERVER_ADDRESS,
        POPSTAR_AGENT_PORT="8770",
        POPSTAR_AUTO_SYNC="true",
    )
    monitor.SETTINGS_PATH = password_file.with_name("monitor-settings.json")
    if monitor.load_env_files().get("SYNC_BASE_URL", "").rstrip("/") == "http://127.0.0.1:18090":
        os.environ["SYNC_BASE_URL"] = HOME_CRM_VPN_URL
    VpnMonitorHandler.password_file = password_file
    monitor.MonitorHandler = VpnMonitorHandler
    monitor.ThreadingHTTPServer = VpnMonitorServer
    print(f"Allowing VPN gateway {VPN_GATEWAY} only. Password file: {password_file}")
    monitor.BPLUS_BRIDGE = LocalBridge(Path(__file__).resolve().with_name("local_bridge.ps1"))
    try:
        return monitor.main()
    finally:
        monitor.BPLUS_BRIDGE.close()


if __name__ == "__main__":
    sys.exit(main())
