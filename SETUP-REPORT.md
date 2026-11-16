# SETUP-REPORT — POPSTAR Platform: Docker → Windows (PHP 8.4 + PostgreSQL)

วันที่: 2026-11-16
โปรเจกต์: `D:\crm-member\popstar-business-platform-v4` (Laravel อยู่ใน `backend\`)
สถานะ: **เสร็จทุกขั้น ยกเว้นตั้งค่า Database (ขั้น 3 / migrate) ที่ยังรอไฟล์ `.pgpass-setup.txt`**

ไม่ได้แตะ Bplus ERP / SQL Server · ไม่ได้ลบไฟล์ใด · ไม่ได้ push ขึ้น GitHub

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
