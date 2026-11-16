# SETUP-REPORT — POPSTAR Platform: Docker → XAMPP + PostgreSQL

วันที่: 2026-11-16
โปรเจกต์: **`D:\xampp\htdocs\popstar-business-platform-v4`** (Laravel อยู่ใน `backend\`)
สถานะ: **เสร็จครบทุกขั้น** เหลือให้ผู้ใช้สร้างบัญชีแอดมิน CRM ปิด Docker และ push GitHub

ไม่ได้แตะ Bplus ERP / SQL Server · ไม่ได้ลบไฟล์ใด · ไม่ได้ push ขึ้น GitHub

## ปิด Docker แล้ว (2026-11-16)

- `docker compose -f _docker_backup\compose.yaml --env-file .env -p popstar-platform down` ลบ container ทั้ง 5 ตัวและ network แล้ว **ไม่ได้ลบ volume** (`popstar-platform_pgdata`, `popstar-platform_app_storage` ยังอยู่ ถ้าต้องการลบภายหลัง ใช้ `docker volume rm`)
- พอร์ต 18080–18082 ว่างแล้ว ส่วน localhost, popstar.localhost, member.popstar.localhost และ `/api` ยังได้ 200 และ PostgreSQL ในเครื่องรับการเชื่อมต่อปกติ
- `start-popstar.bat` ใช้พอร์ต 18081/18082 ได้แล้ว (เป็นทางเลือก ถ้าไม่ใช้ Apache)

## ย้ายข้อมูลจาก Docker — เสร็จแล้ว (2026-11-16)

- `pg_dump` จาก container `popstar-platform-db-1` (schema เดียวกัน 18 migrations) แล้ว `pg_restore --no-owner --no-privileges` ลง `popstar_business` ในเครื่อง
- ข้อมูลที่ย้ายมา: `erp_sales_items` 25,615 · `erp_sales_invoices` 9,247 · `erp_customers` / `crm_customer_masters` 7,804 · `erp_products` 4,208 · `sync_runs` 186 · `erp_members` 112 · `sync_errors` 34 · `erp_salespersons` 21 · visit + photos ฯลฯ (ตรงกับใน Docker ทุกตาราง)
- รูป visit 2 ไฟล์ คัดลอกจาก storage ของ container `app` ไปไว้ที่ `backend\storage\app\private\visits\1\`
- บัญชีแอดมิน: `admin@popstar.local` (id 1, ของเดิมจาก Docker) + `you@example.com` (id 7, เพิ่งสร้าง คงไว้ให้ login ได้ ควรเปลี่ยนเป็นอีเมลจริง)
- ไฟล์สำรอง (git-ignored และ Apache บล็อกไว้): `_docker_backup\db\local-before-import.dump`, `_docker_backup\db\docker-popstar_business.dump`, `_docker_backup\storage-app\` ⚠️ มีข้อมูลลูกค้า ห้ามแชร์
- แก้ที่อยู่ที่ยังชี้ไป Docker:
  - `backend\.env`: `LOCAL_ERP_BRIDGE_URL=http://127.0.0.1:18900` (เดิม `host.docker.internal`)
  - `sync-agent\config.env`: `SYNC_BASE_URL=http://127.0.0.1:18090` (เดิม `:18080` ของ Docker) สำรองเป็น `config.env.bak-docker`
- **⚠️ เหตุการณ์ระหว่างทดสอบ:** หลังเปลี่ยน `LOCAL_ERP_BRIDGE_URL` มี 3 tests ไม่ผ่าน เพราะ test ปลอม (`Http::fake`) เฉพาะ URL `host.docker.internal:18900` และ Laravel ส่งคำขอที่ไม่ตรงตัวปลอมออกไปจริง ตอนนั้น `local_bridge.ps1` กำลังรันอยู่ (เปิดตอน 19:38 โดยผู้ใช้หรือเครื่องมืออื่น) **จึงเป็นไปได้ว่ามีคำขออ่านข้อมูลบางส่วนผ่าน bridge ไปยัง Bplus** ประมาณ 19:44 ซึ่ง bridge ทำได้แค่อ่าน (บล็อก INSERT/UPDATE/DELETE/EXEC ฯลฯ และบล็อกบัญชี `sa`) ไม่มีการเขียนลง Bplus และผลลัพธ์ไปอยู่ใน SQLite ชั่วคราวของ test ที่ทิ้งไปทันที ส่วน bridge ไม่มี log ให้ยืนยัน
  - **แก้แล้ว:** `phpunit.xml` บังคับ `LOCAL_ERP_BRIDGE_URL=http://host.docker.internal:18900` และ token ปลอม ทำให้ test ไม่พึ่ง `.env` และไม่ส่ง token จริงอีก ผล tests 23 / 376 ผ่าน

## เปิดผ่าน Apache ของ XAMPP — เสร็จแล้ว (2026-11-16)

| ที่อยู่ | เปิดอะไร |
|---|---|
| http://localhost | htdocs เดิม (Index, Popstarfoods ฯลฯ) ไม่เปลี่ยน |
| **http://popstar.localhost** | CRM (`web\crm\dist`) + `/api` → Laravel |
| **http://member.popstar.localhost** | Member (`web\member\dist`) + `/api` → Laravel |
| http://127.0.0.1:18090 | Laravel API (`backend\public`) ฟังเฉพาะในเครื่อง |

- Config: `D:\xampp\apache\conf\extra\httpd-popstar.conf` (สำเนาอยู่ที่ `tools\apache\httpd-popstar.conf`) และ include ไว้ท้าย `httpd.conf`
- `httpd.conf` (สำรอง `httpd.conf.bak-popstar`): เปิด `mod_proxy_http` + Include ไฟล์ข้างบน
- `httpd-xampp.conf` (สำรอง `httpd-xampp.conf.bak-popstar`): เพิ่ม `LoadFile` สำหรับ `libsodium.dll`, `libssh2.dll`, `nghttp2.dll` ทำให้ `sodium` โหลดใน Apache ได้
- **ผลทดสอบ:** localhost / Popstarfoods ได้ 200, `popstar.localhost` ได้ 200 "Popstar CRM", `member.popstar.localhost` ได้ 200 "Popstar MEMBER", `/api/crm/csrf` และ `/api/member/csrf` ได้ 200, login (บัญชีที่ไม่มีอยู่) ได้ 422 "Invalid credentials" แสดงว่า PostgreSQL ผ่าน Apache ใช้งานได้, `.env` ยังได้ 403/404 ทุกเส้นทาง
- **ข้อจำกัด:** extension `curl` โหลดใน Apache ไม่ได้ เพราะ Apache ใช้ OpenSSL 3.5.1 ของตัวเอง ส่วน PHP สร้างกับ 3.5.9 จึงขึ้นคำเตือนตอน start (ไม่ทำให้ Apache ล้ม) แต่ Laravel `Http::` / Guzzle เปลี่ยนไปใช้ stream ของ PHP เอง ทดสอบแล้วได้ 200 ส่วน CLI (queue, scheduler) มี curl ปกติ
- **Queue worker:** `tools\run-scheduler.vbs` (Task Scheduler ทุก 1 นาที) รัน `queue:work --stop-when-empty` ต่อจาก `schedule:run` จึงไม่ต้องเปิด `start-popstar.bat` ค้างไว้ (log: `backend\storage\logs\queue.log`)
- `start-popstar.bat` ยังใช้ได้เป็นทางเลือก (พอร์ต 8000 / 18081 / 18082)
- ⚠️ Apache ถูกรีสตาร์ทด้วยคำสั่งตอนตั้งค่า ถ้า XAMPP Control Panel แสดงสถานะไม่ตรง ให้กด Stop → Start ใหม่ 1 ครั้ง

## Database — เสร็จแล้ว (2026-11-16)

- **รหัส postgres:** ตอนติดตั้งผ่าน winget ไม่ได้ตั้งรหัสไว้ จึงรีเซ็ตด้วย `tools\reset-postgres-password.ps1` ซึ่งผู้ใช้รันเองใน Admin PowerShell ผู้ใช้เป็นคนตั้งรหัส สคริปต์แก้ `pg_hba.conf` ชั่วคราวแล้วคืนค่าเดิม และบันทึกรหัสลง `backend\.pgpass-setup.txt` (git-ignored)
  - หมายเหตุ: ครั้งแรกสคริปต์ไม่ได้รัน เพราะรันแค่ `Set-ExecutionPolicy` ส่วนบรรทัดที่สองไม่ได้ถูกรัน ภายหลังรวมเป็นคำสั่งเดียว (`Set-ExecutionPolicy ... -Force; & "...ps1"`)
- สร้าง DB `popstar_business` (UTF8) บน PostgreSQL 17.12
- `backend\.env`: `DB_HOST=127.0.0.1`, `DB_USERNAME=postgres`, `DB_PASSWORD` = รหัสใหม่
- `php artisan migrate --seed --force` ผ่าน 18 migrations, seed สร้าง `Test User` ในตาราง `users` และ `storage:link` ทำแล้ว
- **ทดสอบ login CRM ผ่าน proxy:** `POST /api/crm/login` ตอบ `422 {"error":"Invalid credentials"}` แสดงว่าค้นตาราง `staff_users` ใน PostgreSQL ได้จริง (ตารางยังว่าง)
- Tests หลังต่อ DB: **23 / 376 ผ่าน**

### ผู้ใช้ต้องสร้างบัญชีแอดมิน CRM เอง (รหัสอย่างน้อย 12 ตัว ระบบจะถามแบบไม่แสดงบนจอ)
```
cd D:\xampp\htdocs\popstar-business-platform-v4\backend
D:\xampp\php\php.exe artisan popstar:create-staff-admin อีเมลของคุณ
```

## อัปเดตล่าสุด — ย้ายไป XAMPP (`D:\xampp`)

- **PHP ที่ใช้ตอนนี้: XAMPP PHP 8.5.12 (`D:\xampp\php\php.exe`)** แทน `C:\php84` ซึ่งยังเก็บไว้เป็นสำรอง
  - `php.ini` สำรองเป็น `php.ini.bak-popstar` แล้วเปิด `pdo_pgsql pgsql intl zip gd bcmath fileinfo mbstring openssl curl sodium exif`
  - ปิดบรรทัด `extension=php_openssl.dll` ที่ซ้ำ (ทำให้มีคำเตือน "already loaded")
  - คัดลอก `libpq.dll` ไปไว้ที่ `apache\bin`
  - ผลบน PHP 8.5: composer platform check ผ่าน, **tests 23 / 376 ผ่าน**, backend `/api/crm/csrf` และหน้า CRM `/login` ได้ 200
- **โปรเจกต์อยู่ที่ `D:\xampp\htdocs\popstar-business-platform-v4`** ใช้การคัดลอก (robocopy ครบ 15,452 ไฟล์ / 158.1 MB รวม `.git`) เพราะย้ายตรงๆ ไม่ได้ (โฟลเดอร์ถูกเปิดค้างโดย process อื่น) **ต้นฉบับยังอยู่ที่ `D:\crm-member\popstar-business-platform-v4`** ถ้าตรวจแล้วว่าที่ใหม่ใช้งานได้ ลบหรือเปลี่ยนชื่อต้นฉบับเองได้
- **ความปลอดภัย:** Apache ของ XAMPP เสิร์ฟทุกไฟล์ใน htdocs ทำให้ดาวน์โหลด `backend/.env` ได้ (ทดสอบแล้วได้ 200) จึงเพิ่ม `.htaccess` (`Require all denied`) ที่ root โปรเจกต์ ตอนนี้ทั้งโฟลเดอร์ได้ 403 **ห้ามลบไฟล์นี้** ส่วนระบบยังรันผ่าน `start-popstar.bat` เหมือนเดิม ไม่ได้ผ่าน Apache
- `start-popstar.bat`, `tools\run-scheduler.vbs` และ Task Scheduler `POPSTAR schedule-run` ชี้ไปที่ PHP ของ XAMPP และพาธใหม่แล้ว

---

## สรุปผลแต่ละขั้น

| ขั้น | ผล |
|---|---|
| 1. โปรแกรม | ✅ PHP 8.4.27 (`C:\php84`), Composer, Node.js, Git, PostgreSQL 17 (service ทำงาน) — ❌ ยังไม่มี `.pgpass-setup.txt` |
| 2. วิเคราะห์ Docker | ✅ ดูหัวข้อ "สิ่งที่ทดแทน Docker" |
| 3. setup-windows.ps1 | ⚠️ ไม่ได้รันตัวสคริปต์เพราะไม่ตรงกับโปรเจกต์ (ดูหัวข้อ "ทำไมไม่ใช้ setup-windows.ps1") ทำทีละขั้นด้วยมือแทน ยกเว้นสร้าง DB และ migrate |
| 4. แก้ปัญหา | ✅ ดูหัวข้อ "ปัญหาที่เจอ" |
| 5. Redis | ✅ ไม่ต้องแก้ เพราะไม่มี Redis ใน compose และ `.env` ใช้ `file`/`database` อยู่แล้ว |
| 6. `php artisan test` | ✅ **23 tests / 376 assertions ผ่านหมด** (เกินเกณฑ์ 13 / 152) |
| 7. เปิดระบบ | ✅ backend `/api/crm/csrf` ได้ 200, หน้า CRM `/` และ `/login` ได้ 200 (title "Popstar CRM") และ proxy `/api` ทำงาน — ⚠️ ยังทดสอบ login จริงไม่ได้จนกว่า DB จะพร้อม |
| 8. ตัวเปิดระบบ + Scheduler | ✅ `start-popstar.bat` และ Task Scheduler `POPSTAR schedule-run` (ทุก 1 นาที) |
| 9. Git | ✅ `git init` + commit `12924d5 chore: migrate from Docker to XAMPP + PostgreSQL` และตรวจแล้วว่าไม่มี `.env` หรือรหัสผ่านติดไป |
| 10. รายงาน | ✅ ไฟล์นี้ |

## สิ่งที่ทำ

- **PHP 8.4.27 NTS x64** จาก windows.php.net (ตรวจ SHA256 แล้ว) ติดตั้งที่ `C:\php84` ส่วน `php.ini` เปิด `pdo_pgsql pgsql bcmath intl zip gd fileinfo mbstring openssl curl sodium exif pdo_sqlite sqlite3` + opcache, `memory_limit=512M`, `date.timezone=Asia/Bangkok`
- `composer install` (backend) และ `npm install && npm run build` ทั้งใน `backend\` และที่ root (build `web\crm` + `web\member`)
- `backend\.env`: สำรองเป็น `backend\.env.bak-docker` แล้วเปลี่ยน `DB_HOST=db → 127.0.0.1` และ `APP_URL=http://127.0.0.1:8000` **ส่วน `DB_USERNAME`/`DB_PASSWORD` ยังเป็นค่าเดิมของ Docker**
- **`tools\serve-spa.mjs`** (Node ล้วน ไม่ต้องติดตั้งอะไรเพิ่ม) ใช้แทน nginx ของ container `crm`/`member`: เสิร์ฟ `web\<module>\dist` + fallback แบบ SPA และ proxy `/api/*` ไป Laravel
- **`start-popstar.bat`**: start service PostgreSQL → `artisan serve` (:8000) → `queue:work` → CRM (:18081) → Member (:18082) แล้วเปิดเบราว์เซอร์ที่หน้า CRM
- **Task Scheduler `POPSTAR schedule-run`** (ระดับ user ทุก 1 นาที) เรียก `tools\run-scheduler.vbs` ซึ่งรัน `php artisan schedule:run` แบบไม่มีหน้าต่างเด้ง และเขียน log ไปที่ `backend\storage\logs\scheduler.log`
- ย้าย `compose.yaml`, `.dockerignore`, `docker\` ไปไว้ที่ `_docker_backup\` (ไม่ได้ลบ)
- `.gitignore` เพิ่ม `.pgpass-setup.txt`, `setup-log.txt`, `*.bak-docker`, `_docker_backup/`

## สิ่งที่ทดแทน Docker

| Docker service | บน Windows |
|---|---|
| `db` postgres:17 | PostgreSQL 17 service `postgresql-x64-17` |
| `app` php:8.4-fpm | `C:\php84\php.exe` + `artisan serve` :8000 |
| `backend_web` nginx :18080 | ไม่ต้องใช้แล้ว (`artisan serve`) |
| `crm` :18081 / `member` :18082 | `node tools\serve-spa.mjs crm 18081` / `member 18082` |
| queue / scheduler | `queue:work` ใน start-popstar.bat / Task Scheduler (ตอนนี้ `routes/console.php` ยังไม่มีงานตั้งเวลา) |

## ทำไมไม่ใช้ setup-windows.ps1 ตรงๆ

- สคริปต์หา PHP ที่ `<XamppPath>\php\php.exe` แต่โปรเจกต์ต้องการ PHP **^8.3** ซึ่ง XAMPP มีแค่ 8.2 จึงใช้ PHP 8.4 ที่ `C:\php84` แทน
- default `DbName=popstar` แต่โปรเจกต์ใช้ `popstar_business`
- ต้องรันใน `backend\` และไฟล์ Docker จริงคือ `compose.yaml` ที่ root ซึ่งสคริปต์ไม่รู้จัก
- ไม่เปิด `bcmath`, regex ไม่จับ `;extension=exif ; comment` และไม่ build `web\crm`/`web\member`

## ปัญหาที่เจอและวิธีแก้

1. **XAMPP มีแค่ PHP 8.2** → ใช้ PHP 8.4 แยกที่ `C:\php84` (ผู้ใช้เลือกเอง)
2. **`exif` ไม่โหลด** เพราะบรรทัดใน ini มีคอมเมนต์ต่อท้าย → แก้ regex
3. **Docker ยังรันอยู่** และใช้พอร์ต 18080/18081/18082 ทำให้ทดสอบ CRM ที่ 18081 ได้คำตอบจาก Docker แทน → ทดสอบที่พอร์ต 28081 แทน **ต้องปิด Docker ก่อนใช้ `start-popstar.bat`**
4. backend `/` ได้ **404** ซึ่งเป็นเรื่องปกติ เพราะ Laravel เป็น API ล้วน (ไม่มี route `/`) จึงตรวจด้วย `/api/crm/csrf` แทนแล้วได้ 200
5. **ทดสอบรหัสผ่าน DB จาก `.env` กับ PostgreSQL ในเครื่องไม่ได้** เพราะถูกระบบสิทธิ์ของ Claude Code ปฏิเสธ จึงยังไม่ได้สร้าง DB และ migrate

## สิ่งที่ผู้ใช้ต้องทำต่อ

1. **ปิด Docker stack เดิม** (ไฟล์ compose ย้ายไปแล้ว):
   ```
   docker compose -f _docker_backup\compose.yaml -p popstar-platform down
   ```
   ถ้าต้องการย้ายข้อมูลจาก Docker ให้ `pg_dump` ออกจาก container `db` ก่อน `down`
2. **ตั้ง Database**: วาง `backend\.pgpass-setup.txt` (รหัส postgres) แล้วสั่ง Claude Code ให้ทำต่อ หรือทำเองดังนี้
   - สร้าง DB `popstar_business` (และ user ถ้าจะใช้ `popstar_app`)
   - แก้ `DB_USERNAME`/`DB_PASSWORD` ใน `backend\.env`
   - `C:\php84\php.exe artisan migrate --seed --force` แล้ว `artisan storage:link`
3. ดับเบิลคลิก `start-popstar.bat` แล้วลอง login ที่ http://127.0.0.1:18081
4. (ไม่บังคับ) เพิ่ม `C:\php84` ลงใน PATH
5. ตรวจ commit แล้ว **push GitHub เอง** และเลือก VPS
