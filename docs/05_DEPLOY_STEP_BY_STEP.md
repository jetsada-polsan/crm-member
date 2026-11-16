# ติดตั้ง V4 บน Ubuntu VPS ทีละขั้น

## 0. ตรวจระบบเดิม ห้ามเขียนทับ

```bash
hostnamectl
sudo nginx -t
sudo ss -lntp | grep -E ':80|:443|:5432|:18080|:18081|:18082' || true
df -h
```

สำรองเว็บและฐานข้อมูลเดิมก่อนเสมอ Compose V4 จะสร้าง PostgreSQL ใหม่ใน Volume ของตัวเอง **ไม่ทับ PostgreSQL ที่ติดตั้งบน Host** (แต่กิน RAM/Storage เพิ่ม)

## 1. ติดตั้ง Docker Engine + Compose plugin

ทำตามคู่มือ official Docker Ubuntu สำหรับ Ubuntu version นั้น ๆ และตรวจ `docker version` / `docker compose version` ไม่ใช้ installer สุ่มจากอินเทอร์เน็ตโดยไม่ตรวจสอบ

## 2. คัดลอกและเตรียมไฟล์

```bash
unzip popstar-business-platform-v4.zip
cd popstar-business-platform-v4
cp .env.example .env
# แก้ POSTGRES_PASSWORD ใน .env เป็นรหัสสุ่มยาว
bash scripts/bootstrap-docker.sh
# แก้ backend/.env ให้ตรงกับ .env (DB_CONNECTION=pgsql, DB_HOST=db, DB_PORT=5432)
# APP_DEBUG=false, SESSION_DOMAIN=, SESSION_SECURE_COOKIE=true
# ใส่ SYNC_API_TOKEN แบบสุ่มยาว และ LINE secrets เฉพาะถ้าใช้งานจริง
```

**ห้าม commit `.env`, `backend/.env` หรือไฟล์ SQL connection string** ลง Git.

## 3. สร้าง/เริ่ม Services

```bash
# สร้าง APP_KEY ใน backend/.env บน Host ก่อน start (หากยังว่าง)
KEY=$(openssl rand -base64 32)
sed -i "s|^APP_KEY=.*|APP_KEY=base64:${KEY}|" backend/.env

docker compose build
docker compose up -d
docker compose ps
docker compose exec app php artisan migrate --force
curl -i http://127.0.0.1:18080/api/health
```

ถ้าแก้ไฟล์ overlay หลังสร้าง `backend` ต้อง sync overlay ลง `backend` แล้ว build image ใหม่; เก็บโค้ด overlay เป็น source of truth ระหว่างช่วง Starter.

## 4. ตั้ง Subdomain

กำหนด DNS A records `api`, `crm`, `member` ให้ชี้ Public IP **VPS** ไม่ใช่ IP บ้าน จากนั้นให้ Host Nginx route ไป `127.0.0.1:18080`, `18081`, `18082` ตาม config ตัวอย่าง เปิด TCP 80/443 ที่ VPS พร้อมออก TLS certificate ด้วย Certbot โดยอย่าเปิด SSL config ที่อ้าง certificate file ซึ่งยังไม่มี.

ใช้ HTTPS ทดสอบ:

```bash
curl -i https://api.YOURDOMAIN/api/health
curl -I https://crm.YOURDOMAIN
curl -I https://member.YOURDOMAIN
```

## 5. บน Windows PC บ้าน

- ต่อ VPN บริษัทที่ได้รับอนุญาต
- ใช้บัญชี SQL Server ที่อ่านได้เฉพาะ View ที่ได้รับอนุญาต
- ตั้ง `SYNC_BASE_URL=https://api.YOURDOMAIN/api/sync/v1` และ Token ยาวที่ตรงกับ VPS
- สั่ง `python main.py demo` เพื่อทดสอบขาออกก่อน ต่อด้วย `python main.py customers` หลังตรวจ Query จริง
- เปิด Windows Task Scheduler เฉพาะเมื่อทดสอบ Queue และสิทธิ์เรียบร้อย

## 6. ข้อควรระวัง

- `docker compose down -v` ลบข้อมูล Volume: **ห้ามใช้กับระบบจริง**
- `docker compose up -d --build` ไม่ได้แทน Backup และ rollback
- Docker container ในชุดนี้ไม่มีการเปิด PostgreSQL port สู่สาธารณะ
- Photo uploaded stored in app_storage Volume; ต้อง Backup คู่ PostgreSQL
- ยังไม่มีระบบ LINE Login ผูกสมาชิก, คะแนนคำนวณอัตโนมัติ และ reconciliation บิลยกเลิก/คืนสินค้า; อย่าเปิดใช้คะแนนกับลูกค้าจริงก่อนเพิ่มส่วนนี้
