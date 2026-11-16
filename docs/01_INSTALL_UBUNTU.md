# 01 — Install Ubuntu VPS (คำสั่งตั้งต้น)

> คำสั่งตัวอย่าง: ต้องตรวจชื่อ PHP-FPM socket, domain, firewall, service อื่นบน VPS ก่อน หาก VPS มี ERP/เว็บเดิม ห้ามทับ config / database โดยไม่สำรอง

## เตรียม runtime
```bash
php -v && php -m | grep -E 'pdo_pgsql|mbstring|xml|openssl'
node -v && npm -v
composer --version
sudo apt update
# ตัวอย่างสำหรับ VPS ที่มี PHP 8.3 อยู่แล้ว; หากใช้ PHP 8.4/8.5 ปรับชื่อ package ตามเครื่อง
sudo apt install -y nginx postgresql postgresql-client php-cli php-fpm php-pgsql php-mbstring php-xml php-curl php-zip unzip
```

## เตรียม Backend
```bash
# อัปโหลด/แตก ZIP ไว้ที่ /opt/popstar-business-platform-v2
cd /opt/popstar-business-platform-v2
bash scripts/prepare-backend.sh
cd backend
cp ../deploy/backend.env.example .env
# แก้ APP_URL DB_... และ SYNC_API_TOKEN แล้ว chmod 600 .env
php artisan key:generate
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan popstar:create-staff-admin admin@yourdomain.com
php artisan config:cache
php artisan route:cache
```

## PostgreSQL (ไม่ใช้ postgres superuser กับ Application)
```bash
sudo -u postgres psql
-- ใน psql ให้เปลี่ยนรหัสผ่านจริง:
CREATE ROLE popstar_app LOGIN PASSWORD 'CHANGE_ME_LONG_RANDOM';
CREATE DATABASE popstar_business OWNER popstar_app;
\q
```

## Build Web Frontends
```bash
cd /opt/popstar-business-platform-v2
npm install
npm run build
# เช็คไฟล์ web/crm/dist/index.html และ web/member/dist/index.html
```

## Nginx และ HTTPS
1. ใช้ template `deploy/nginx-crm.conf.example`, `deploy/nginx-member.conf.example`, `deploy/nginx-api.conf.example`
2. แทนทุก `CHANGE_DOMAIN`, `CHANGE_PHP_SOCKET`, `CHANGE_PROJECT_PATH` ด้วยค่าจริง
3. คำสั่ง `sudo nginx -t && sudo systemctl reload nginx`
4. ติดตั้ง Certbot ตามคู่มือ Ubuntu/Certbot ที่ตรง OS: `sudo certbot --nginx -d crm.example.com -d member.example.com -d api.example.com`
5. ทดสอบ `sudo certbot renew --dry-run`
6. ตรวจ HTTPS ใช้จริง: `curl -i https://api.example.com/api/health`

## การสร้าง Member ทดสอบ
- Member สมัครผ่านหน้า Member แล้วเข้าระบบได้ แต่ **จะไม่เห็นยอดซื้อ ERP** จนกว่ามีขั้นตอนยืนยันและ link กับรหัสลูกค้า
- คำสั่งผู้ดูแล `php artisan popstar:link-member {member_id} {source} {customer_code} --verified` ใช้เฉพาะเมื่อได้ตรวจหลักฐานเจ้าของบัญชีแล้ว

## การจัดการ Secret
- ห้าม commit `.env`, token, password, SQL credential ไป Git
- `SYNC_API_TOKEN` ต้องเป็น random 32 bytes หรือยาวกว่านั้น (เช่น `openssl rand -hex 32`)
- `SESSION_DOMAIN` **ปล่อยว่าง** เพื่อให้ Cookie host-only แยก CRM / Member
- ใช้ DB role สิทธิ์ตามจำเป็น; firewall PostgreSQL ไม่รับ Public Internet

## เช็คลิสต์ก่อนข้อมูลจริง
อ่าน `docs/04_SECURITY_TESTS.md`; โดยเฉพาะอัตรา Sync, ยอดบิลคืน/ยกเลิก, การตรวจจับข้อมูลซ้ำ, การแยกสิทธิ์ staff/member, PDPA, backups และการยืนยันการ link สมาชิก
