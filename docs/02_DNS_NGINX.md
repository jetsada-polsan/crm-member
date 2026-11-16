# 02 — DNS / HTTPS / Login ที่แยกกันจริง

| Record | IP |
|---|---|
| crm.example.com A | VPS_PUBLIC_IP |
| member.example.com A | VPS_PUBLIC_IP |
| api.example.com A | VPS_PUBLIC_IP |

**ไม่ต้องผูก IP บ้านเข้ากับ DNS และไม่เปิด Inbound SQL** PC บ้านเป็น outbound sender ผ่าน HTTPS/443 เท่านั้น

Nginx 3 host ชี้ VPS เดียวกัน: 2 host ทำหน้าที่เสิร์ฟ Vue SPA และ proxy `/api` ไป **Laravel public/index.php**; `api` host ใช้ API/Webhook/Sync เท่านั้น

- CRM ใช้ session cookie host-only สำหรับ `crm.example.com` และ Laravel guard `staff`
- Member ใช้ session cookie host-only สำหรับ `member.example.com` และ Laravel guard `member`
- Frontend ส่ง `credentials:'same-origin'` + `X-CSRF-TOKEN` ที่ GET จาก `/api/{crm|member}/csrf`
- ใช้ HTTPS + `SESSION_SECURE_COOKIE=true` เท่านั้น
- `/api/sync/v1/*` ใช้ token ตรวจสอบฝั่ง Backend; ไม่ใช้ Cookie/CSRF
- `/api/line/webhook` ตรวจ HMAC SHA256 ของ raw body ด้วย LINE channel secret; ไม่ใช้ CSRF

หากเปลี่ยนชื่อ subdomain ไม่ต้องเปลี่ยน PHP source; แก้ DNS, Nginx, SSL เท่านั้น
