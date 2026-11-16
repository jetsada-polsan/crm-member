# Popstar Business Platform V4 — Modular Monolith + Docker

**สถานะ: Development starter / POC ไม่ใช่ Production-ready.** นำฐาน V2 มาพัฒนาต่อเป็น V4 สำหรับงานออกตลาดจริง (Sales Visit 6 ข้อ), GPS, ลูกค้าใหม่, รูปถ่าย, แผนออกตลาด, งานติดตาม และแยก Staff/Member Auth ใน Backend Laravel เดียว

## ภาพรวม

- 1 Laravel Backend, 1 PostgreSQL, 2 Vue frontends (`crm` / `member`) ใช้ Docker Compose บน Ubuntu VPS
- Windows Sync Gateway ต่อ VPN ไปยัง SQL Server ต้นฉบับด้วยบัญชี read-only; ส่งลูกค้า สินค้า บิล HTTPS ออก Cloud
- ERP Mirror เป็นข้อมูลจากต้นทาง ไม่มี Cloud-to-ERP writes
- CRM: ERP customer, Lead, 6-question Visit, GPS check-in, permanent pins, photos (private), Visit Plans, Follow-ups
- Member: แยก auth, confirmed ERP linkage only, verified invoice history and points ledger
- LINE: secure signature verification + safe non-sensitive welcome reply; LINE account linking and private answer flow **ยังไม่เสร็จ**

## โครงไฟล์

`backend-overlay/` คือโค้ด Laravel ที่ **ต้อง overlay** ลง Laravel project (`backend/`) ก่อน build Docker; ไม่ใช่แอป Laravel ที่รันได้โดยตรง

`sync-agent/` เป็น Windows Python Worker, ต้องแทน SQL query ด้วย schema ของ Bplus จริง

`docker/` Dockerfiles สำหรับ Backend PHP-FPM, Backend Nginx, CRM Vue, Member Vue

`compose.yaml` รัน PostgreSQL container **ใหม่** พร้อม Named Volume; ห้ามชี้ไปฐาน PostgreSQL เดิมโดยไม่วางแผน migration

`docs/` มี INSTALL, DATA_MODEL, SECURITY, API, ACCEPTANCE และ CODEX_PROMPT

## Quick start (เครื่องทดสอบ / VPS ใหม่)

1. สำรองระบบและตรวจ Nginx/DB/พอร์ตเดิมก่อน; ห้ามใช้คำสั่ง Docker prune/down -v กับข้อมูลจริง
2. `cp .env.example .env` แล้วสร้างรหัสผ่าน PostgreSQL ที่แข็งแรง
3. `bash scripts/bootstrap-docker.sh` (เครื่องต้องมี Docker/Compose); script สร้าง Laravel 13 `backend/` ผ่าน Composer container และลงไฟล์ overlay
4. แก้ `backend/.env` ให้ `DB_CONNECTION=pgsql`, `DB_HOST=db`, `DB_DATABASE` และ credentials ตรงกับ Compose, ตั้ง `APP_KEY=base64:` ตามด้วยผล `openssl rand -base64 32` ในไฟล์บน Host ก่อน `docker compose up`; ตั้ง `SESSION_DOMAIN=` ว่างเพื่อให้ Cookie เป็น Host-only และ `SESSION_SECURE_COOKIE=true`
5. `docker compose build && docker compose up -d`
6. ตรวจว่า `backend/.env` บน Host มี `APP_KEY=base64:<random base64 32 bytes>` แล้วจึงเริ่ม Compose (อย่าสร้าง APP_KEY เฉพาะภายใน Container เพราะจะไม่คงค่าในไฟล์ Host)
7. `docker compose exec app php artisan migrate --force`
8. `docker compose ps` และ `curl http://127.0.0.1:18080/api/health` บน VPS
9. สร้าง DNS `api`, `crm`, `member` ชี้ IP VPS; Nginx ที่ Host reverse proxy ไป `127.0.0.1:18080`, `18081`, `18082` พร้อม Certbot TLS (ดู `deploy/host-nginx-v4.conf.example`)
10. สร้างบัญชี Staff ด้วย Laravel tinker อย่างปลอดภัย แทนการเปิด endpoint signup พนักงานสาธารณะ

> **สำคัญ:** ไม่ได้รัน build/compose/integration จริงในสภาพแวดล้อมของผู้ใช้ ชุดนี้ผ่านเฉพาะ static checks ที่ระบุในรายงาน QA; ก่อน Production ต้องทดสอบบน VPS, Security Review, Backup/Restore, Privacy, LINE Login, Points engine และ SQL mapping.

## URL 

- `https://crm.YOURDOMAIN` CRM Staff
- `https://member.YOURDOMAIN` Member
- `https://api.YOURDOMAIN/api/sync/v1/*` Read-only ERP ingestion; มี Token เฉพาะ Worker
- `https://api.YOURDOMAIN/api/line/webhook` LINE webhook

## สิ่งที่เพิ่มจาก V2

| Capability | V4 |
|---|---|
| Modular Monolith | คง Laravel backend เดียว |
| Staff/Member logins | guard แยก + host-only cookie |
| Map | Leaflet + OpenStreetMap tiles, pin permanent + GPS check-in |
| Visit | 6 fields ตามฝ่ายขายจริง + followup |
| New customer lead | CRUD เบื้องต้น (create/list) |
| Visit plan | create/list plan + API plan stops |
| Photos | upload private storage + authorize download |
| Docker | Compose / php-fpm / nginx / Vue / PostgreSQL volume |
| LINE | Webhook signature verified only; personal answers pending identity linking |
| Loyalty | ledger schema exists; rule engine pending |

## งานที่ยังต้องทำก่อนใช้งานจริง

- ยืนยัน SQL Bplus schema, เอกสารขาย ยกเลิก คืนสินค้า บิลภาษี สถานะบัญชี และลูกค้า duplicate
- ปรับ reconciliation และ incremental sync สำหรับ 3 ชุด master + invoices, เพิ่ม invoice items และ stock หากจำเป็น
- member identification: LINE Login/OIDC, staff approval, anti-account takeover; rewards business rules approved by owner
- Map tile provider policy/capacity, GPS consent, server-side duplicate review, audit logs, data retention
- full RBAC, upload virus scanning, image re-encoding / EXIF policy, pagination, SQL load & performance tests
- UI field sales offline drafts / retries, plan stops editing, route optimization, financial charts & reports
