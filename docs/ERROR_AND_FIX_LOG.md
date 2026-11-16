# ERROR AND FIX LOG

**Audit Date:** 2026-11-15  
**Purpose:** รวบรวม Error ที่พบจริงจาก Logs, Database (`sync_runs`, `sync_errors`), Terminal, และ Static Code Audit พร้อมสาเหตุรากเหง้าและแนวทางแก้ไขที่ถูกต้อง

---

## LOG-01: `cURL error 7: Failed to connect to host.docker.internal port 18900`
- **หลักฐาน:** Screenshot ของผู้ใช้ + ตาราง PostgreSQL `sync_runs` ID `1` (`2026-11-15 08:26:01+00` / `15:26:01+07`)
- **สาเหตุจริง:** ณ เวลา `15:26:01` ยังไม่ได้รัน `sync-agent\start-local-bridge.cmd` บน Windows ทำให้ไม่มี Process ฟังที่พอร์ต `18900`
- **สถานะปัจจุบัน:** **แก้ไขพฤติกรรมแล้วเมื่อผู้ใช้รัน Bridge** — ยืนยันจากการทดสอบรันคำสั่งจากใน `app` container (`http://host.docker.internal:18900/health` ได้ HTTP `200` `ok: true`) และ `sync_runs` ID `2` (`15:27:51+07`) ซึ่งวิ่งทะลุถึง Bridge บน Windows ได้สำเร็จ
- **ข้อแนะนำถาวร:** สร้าง Windows Scheduled Task หรือ Startup shortcut สำหรับ `sync-agent\start-local-bridge.cmd` เพื่อไม่ให้ดับหลังรีบูตเครื่อง และปรับหน้า ERP Sync Monitor ให้นำ Exception ยาวๆ เข้า `ErrorDetailDrawer`

---

## LOG-02: `SQL_CONNECTION_STRING is not configured in sync-agent\config.env` (HTTP 500)
- **หลักฐาน:** Terminal output จากผู้ใช้ + PostgreSQL `sync_runs` ID `2` (`2026-11-15 08:27:51+00`) + `curl.exe -X POST http://127.0.0.1:18900/test-connection` (HTTP `500`)
- **สาเหตุจริง:** ไฟล์ `d:\crm-member\popstar-business-platform-v4\sync-agent\config.env` บรรทัดที่ 7 (`SQL_CONNECTION_STRING=`) ยังเป็นค่าว่าง และในเครื่องนี้ไม่มีไฟล์ `d:\crm-member\storage\private\secrets.php` หรือ Windows ODBC DSN `BPLUSERP_POPSTAR_2021` สำหรับให้ดึงค่าเชื่อมต่ออัตโนมัติ (ตรวจ Windows ODBC ด้วย `Get-OdbcDsn` แล้วพบเฉพาะ `BplusBack0001` Firebird และ Desktop drivers)
- **แนวทางแก้ไข:** ผู้ใช้ต้องกรอก `SQL_CONNECTION_STRING` (บัญชี **SELECT-only** เท่านั้น) ใน `sync-agent\config.env` หรือส่งมอบ Server/Port/DB/User ของ Bplus SQL Server (ห้ามใช้ `sa` หรือปิด Firewall/Security)

---

## LOG-03: `/health` ใน `local_bridge.ps1` ไม่อ่าน `config.env` ใหม่หลังแก้ไฟล์
- **หลักฐาน:** `sync-agent/local_bridge.ps1` บรรทัด 144–162
- **สาเหตุจริง:** `$SqlConnStr` ถูกโหลดครั้งเดียวตอนเปิดสคริปต์ และโหลดซ้ำเฉพาะหลังบล็อก `if ($path -eq "/health")` ทำให้ถ้าผู้ใช้แก้ `config.env` ระหว่างเปิด Bridge ไว้ `/health` จะยังรายงาน `sql_configured: false` จนกว่าจะเรียก endpoint ที่ต้องใช้ token หรือรีสตาร์ต Bridge
- **สถานะ:** **Pending Fix** (สามารถแก้ได้ปลอดภัยในรอบแก้ Bridge)

---

## LOG-04: `Writing to directory /var/www/.config/psysh is not allowed.` (เมื่อรัน `php artisan tinker`)
- **หลักฐาน:** Terminal output ของผู้ใช้ + `docker/php/Dockerfile` (`USER www-data`)
- **สาเหตุจริง:** Image PHP รันด้วย `USER www-data` ที่ไม่มีสิทธิ์เขียน `/var/www/.config/psysh`
- **Workaround ที่ใช้งานได้ทันที:** รัน `docker compose exec -e HOME=/tmp app php artisan tinker ...` หรือใช้คำสั่งเฉพาะทาง `docker compose exec app php artisan popstar:create-staff-admin` / Query ผ่าน `db` (`psql`)
- **แนวทางแก้ถาวร:** ตั้ง `ENV PSYSH_CONFIG_DIR=/tmp/.psysh` หรือ `ENV HOME=/tmp` หรือสร้าง `/var/www/.config/psysh` ที่ owner เป็น `www-data` ใน `docker/php/Dockerfile`

---

## LOG-05: `php artisan test` ล้มเหลว (`Command "test" is not defined`) & `PopstarSecurityTest.php` หายจาก V4
- **หลักฐาน:** `docker compose ... exec -T app php artisan test` คืน `EXIT 1`; `ls -la vendor/bin` ไม่มี `phpunit`; `v4/backend/tests/Feature/` มีเพียง `ExampleTest.php` ที่เรียก `$this->get('/')` (route `/` ไม่มีใน `routes/web.php`); `HealthController.php` คืน `{"status":"ok","database":"ok"}` ขณะที่ test V2 ตรวจ `{"ok":true}`
- **แนวทางแก้ไข:** ติดตั้ง `composer install` (รวม dev dependencies ใน stage ทดสอบ) + พอร์ต `PopstarSecurityTest.php` จาก V2 เข้า V4 + ปรับ assert ให้ตรงกับ `HealthController` และเพิ่ม Feature Test สำหรับ Field Sales / Lead / ERP Sync

---

## LOG-06: ความคลาดเคลื่อน SQL ระหว่าง `bplus_*.sql` (V4) กับ Logic จริงของ Popstar (`sql.csv` & Legacy PHP)
- **หลักฐาน:**
  1. `bplus_invoices.sql` กรอง `DT_PROPERTIES IN (307, 308, 302, 337)` -> **ขาด `207`** (ใบจอง `B*` ผ่าน `AROE` / POS ตามที่ `SQL11001205`, `p_customer_calendar4.php`, `p_executive_dashboard.php`, `p_reconciliation_reserve_vs_credit.php` ใช้งาน)
  2. `bplus_invoices.sql` กรอง `DI_ACTIVE = 0` -> บิลที่ยกเลิกภายหลังจะไม่ถูกซิงก์ไปอัปเดตสถานะ `'cancelled'` ใน PostgreSQL (Legacy `index.php` ใช้ `DI_ACTIVE <> 1`)
  3. `bplus_customers.sql` join เฉพาะ `ARFILE.AR_SLMNCODE = SALESMAN.SLMN_CODE` -> ขาด fallback `ARFILE.AR_SLMN` และ `ARADDRESS.ARA_SLMN` ตาม `p_customer_calendar4.php`
  4. ยอดขาย: `bplus_invoices.sql` ใช้ `ARD_B_AMT` (รวม VAT), `p_executive_dashboard.php` ใช้ `SKM_SELL - SKM_VAT`, `p_sales_integrity_report.php` ใช้ `TRD_G_AMT` และ `p_warroom.php` ตัดหมวด `'CC','CO','AS','PK'`
- **สถานะ:** บันทึกไว้ใน `DATABASE_MAPPING.md` แล้ว รอทดสอบกับฐาน SQL จริงเพื่อล็อกตัวเลข Reconciliation 1:1

---

## LOG-07: Legacy CSV Parsing Failure เมื่อเปิด `รายงาน.csv` ด้วย Parser มาตรฐาน
- **หลักฐาน:** ทดสอบอ่าน `d:\crm-member\รายงาน.csv` ด้วย `.NET TextFieldParser` (`HasFieldsEnclosedInQuotes = true`, delimiter `\t`) ได้ `22,755` แถวแทนที่จะเป็น `1,000` แถว เนื่องจากไฟล์เป็น Unquoted Tab-Delimited ที่ปล่อย Multiline SQL ขึ้นบรรทัดใหม่โดยไม่ครอบด้วย `"`
- **แนวทางแก้ไข:** ใช้ `d:\crm-member\sql.csv` (RFC-4180 Comma-delimited) เป็นแหล่งอ้างอิงหลัก หรือรวมบรรทัด TSV ด้วยหัวระเบียน `^\d+\t\d+\t\d+\t` (ตรวจยืนยันแล้วว่าได้ 1,000 แถวตรงกัน 100%)

---

## LOG-08: Missing Files — `popstar-modern-mockup/index.html`, `storage/private/secrets.php`, `assets/`, `storage/reports/`
- **หลักฐาน:** ค้นหาแบบ Recursive ใน `d:\crm-member\` ไม่พบไฟล์และโฟลเดอร์ดังกล่าว
- **ผลกระทบ:** Legacy PHP UI & DB รันบนเครื่องนี้ไม่ได้ (`assets/*` & `secrets.php` หาย), และไม่สามารถเริ่ม UI Refactor ให้ตรงตาม Design Baseline โดยไม่เดาดีไซน์ได้จนกว่าจะได้ `popstar-modern-mockup/index.html`
