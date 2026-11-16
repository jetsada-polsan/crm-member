# SETUP-REPORT — POPSTAR Platform: Docker → XAMPP + PostgreSQL

วันที่: 2026-11-16
โปรเจกต์: `D:\crm-member\popstar-business-platform-v4` (Laravel อยู่ใน `backend\`)
สถานะ: **หยุดที่ขั้นที่ 1** ตามเงื่อนไขใน CLAUDE-TASK.md (ไม่พบ `.pgpass-setup.txt`)
ไม่ได้แก้ไข / ย้าย / ลบไฟล์ใดในโปรเจกต์ (นอกจากสร้างไฟล์รายงานนี้) ไม่ได้แตะ Bplus ERP / SQL Server

---

## 1. ผลตรวจโปรแกรม (ขั้นที่ 1)

| รายการ | สถานะ |
|---|---|
| XAMPP `C:\xampp\php\php.exe` | ❌ ไม่มี |
| PHP อื่นในเครื่อง | Herd Lite PHP 8.5.12 (`C:\Users\User\.config\herd-lite\bin\php.exe`) — **ไม่มี `pdo_pgsql`/`pgsql`/`intl`** |
| Composer | ✅ มี (Herd Lite) |
| Node.js / npm | ❌ ไม่มี |
| Git | ❌ ไม่มี |
| PostgreSQL (`C:\Program Files\PostgreSQL`, service) | ❌ ไม่มี |
| `.pgpass-setup.txt` | ❌ ไม่พบ (ค้นใน `C:\Users\User` และ `D:\` แล้ว) |
| PowerShell สิทธิ์ Administrator | ❌ session นี้ไม่ได้รันแบบ Admin |

**ทำไมไม่ติดตั้งด้วย winget ต่อ:** XAMPP, PostgreSQL, Node.js MSI และ Git ติดตั้งระดับเครื่อง ซึ่งต้องกดยืนยัน UAC ตอนที่ไม่มีใครอยู่หน้าเครื่องจะค้าง ส่วน PostgreSQL ต้องตั้งรหัส superuser ตอนติดตั้ง ซึ่งรหัสนี้ควรมาจากไฟล์ `.pgpass-setup.txt` ที่ยังไม่มี

## 2. ⚠️ ปัญหาสำคัญที่ต้องตัดสินใจก่อน: เวอร์ชัน PHP

`backend\composer.json` ต้องการ **`php: ^8.3`** และ **`laravel/framework: ^13.17`**
แต่ XAMPP สำหรับ Windows เวอร์ชันล่าสุดมีแค่ **PHP 8.2.x** ซึ่ง composer install จะไม่ผ่าน และ `setup-windows.ps1` เช็กแค่ 8.2+ เลยจะไม่จับปัญหานี้

> **อัปเดต 2026-11-16 — ผู้ใช้เลือกข้อ ก (PHP 8.4) และติดตั้งเสร็จแล้ว**
> - ดาวน์โหลด `php-8.4.27-1-nts-Win32-vs17-x64.zip` จาก windows.php.net ตรวจ SHA256 ตรงกับค่าที่เว็บประกาศ แล้วแตกไว้ที่ `C:\php84`
> - สร้าง `C:\php84\php.ini` จาก `php.ini-development` เปิด `pdo_pgsql pgsql bcmath intl zip gd fileinfo mbstring openssl curl sodium exif pdo_sqlite sqlite3` + opcache, `memory_limit=512M`, `date.timezone=Asia/Bangkok`
> - `composer check-platform-reqs` ใน `backend\` ผ่านทุกรายการ
> - **ต้องแก้ `setup-windows.ps1`:** สคริปต์หา PHP ที่ `<XamppPath>\php\php.exe` แต่ PHP อยู่ที่ `C:\php84\php.exe` (ไม่มีโฟลเดอร์ย่อย `php`) และ step 2 จะไปแก้ `C:\php84\php\php.ini` ซึ่งไม่มี ต้องเพิ่มพารามิเตอร์ `-PhpDir` นอกจากนี้ regex ของสคริปต์จะไม่เจอบรรทัด `;extension=exif` เพราะมีคอมเมนต์ต่อท้าย
> - ยังไม่ได้เพิ่ม `C:\php84` ลงใน PATH ของระบบ

ทางเลือก (แนะนำข้อ ก):
- **ก.** ใช้ PHP 8.4 NTS x64 จาก windows.php.net (ตรงกับ Docker ที่ใช้ `php:8.4-fpm`) แตกไฟล์ไปไว้ที่ `C:\xampp\php` หรือ `C:\php84` แล้วรันสคริปต์ด้วย `-XamppPath` ที่ตรงกัน เพราะสคริปต์ใช้แค่ `<XamppPath>\php\php.exe`
- **ข.** ใช้ Herd Lite PHP 8.5 ที่มีอยู่แล้ว แต่ต้องเพิ่ม `php_pdo_pgsql.dll`, `php_pgsql.dll`, `php_intl.dll` เอง และ Laravel 13 กับ PHP 8.5 ยังไม่ได้ทดสอบกับโปรเจกต์นี้

## 3. วิเคราะห์ Docker (ขั้นที่ 2) — สิ่งที่ต้องทดแทนบน Windows

`compose.yaml` (ที่ root ของ v4 **ไม่ใช่** `docker-compose.yml`) มี service:

| Service | หน้าที่ | ทดแทนบน Windows |
|---|---|---|
| `db` | postgres:17-alpine | PostgreSQL 17 (`winget install PostgreSQL.PostgreSQL.17`) |
| `app` | php:8.4-fpm + ext `pdo_pgsql bcmath intl zip opcache` | PHP 8.4 + เปิด ext เดียวกัน (**สคริปต์ไม่ได้เปิด `bcmath` ต้องเพิ่ม**) |
| `backend_web` | nginx → Laravel `public` ที่พอร์ต 18080 | `php artisan serve` (8000) หรือ Apache ของ XAMPP |
| `crm` | build `web/crm` ด้วย Node 22 (`npm run build:crm`) แล้วเสิร์ฟด้วย nginx ที่ 18081 | `npm install` + `npm run build:crm` **ที่ root v4** แล้วเสิร์ฟ `web\crm\dist` |
| `member` | เหมือน crm ที่ 18082 | `npm run build:member` |

- **ไม่มี** Redis, queue worker, scheduler หรือ mailpit ใน compose
- `.env` ใช้ `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database` อยู่แล้ว และไม่มี config ใดบังคับใช้ Redis **ข้อ 5 จึงไม่ต้องแก้อะไร**
- `routes/console.php` ไม่มี `Schedule::` จึงยังไม่มีงาน scheduler (ตั้ง Task Scheduler ไว้ได้ แต่ตอนนี้ยังไม่มีงานให้รัน)

## 4. จุดที่ `setup-windows.ps1` ไม่ตรงกับโปรเจกต์ (ต้องแก้ก่อนรัน)

1. **ต้องรันในโฟลเดอร์ `backend\`** ไม่ใช่ root v4 เพราะ `artisan`, `.env`, `composer.json` อยู่ใน `backend\`
2. **ชื่อ DB:** `.env` ใช้ `DB_DATABASE=popstar_business` แต่สคริปต์ default เป็น `popstar` ให้ส่ง `-DbName popstar_business`
3. **ไฟล์ Docker:** สคริปต์ย้ายเฉพาะ `docker-compose.yml`/`Dockerfile`/`docker` ในโฟลเดอร์ปัจจุบัน แต่ของจริงคือ `compose.yaml`, `docker\`, `.dockerignore` ที่ **root v4** จึงจะไม่ถูกย้าย
4. **เช็ก PHP:** สคริปต์รับ 8.2 แต่โปรเจกต์ต้องการ 8.3+ (ดูข้อ 2)
5. **Extension:** ไม่มี `bcmath` ในรายการ ต้องเพิ่ม
6. **Frontend CRM:** สคริปต์ build แค่ `backend` แต่หน้า login CRM อยู่ใน `web\crm` (build จาก `package.json` ที่ root v4)
7. **Git:** ทั้ง v4 ยังไม่มี `.git` และเครื่องยังไม่มี Git

## 5. สิ่งที่ผู้ใช้ต้องทำต่อ

1. สร้าง `D:\crm-member\popstar-business-platform-v4\backend\.pgpass-setup.txt` ใส่รหัส postgres (บรรทัดเดียว) ไฟล์นี้ต้องอยู่ใน `.gitignore`
2. เลือก PHP 8.3/8.4 ตามข้อ 2
3. เปิด PowerShell **Run as Administrator** แล้วติดตั้ง:
   ```
   winget install PostgreSQL.PostgreSQL.17
   winget install OpenJS.NodeJS.LTS
   winget install Git.Git
   ```
4. สั่ง Claude Code ให้ทำต่อจากขั้นที่ 3 (จะปรับสคริปต์ตามข้อ 4 แล้วรัน, test, `start-popstar.bat`, Task Scheduler, git commit)
5. หลังตรวจผลแล้ว: push ขึ้น GitHub และเลือก VPS
