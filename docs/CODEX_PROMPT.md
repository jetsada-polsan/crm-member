# PROMPT สำหรับ Codex / Claude (นำไปแปะได้ทันที)

คุณเป็น Senior Full-stack Engineer + Security Architect. จงพัฒนาโปรเจกต์ `Popstar Business Platform v2` ที่แนบมาให้เป็นระบบ Production จริง โดย **รักษาสถาปัตยกรรม Modular Monolith**: Laravel Backend เดียว PostgreSQL DB เดียว สอง Vue frontends (`crm` กับ `member`) และแยก session guard/staff และ member โดย host-only cookies. ห้ามแตกเป็น microservices หรือ 2 backend แยกโดยไม่ขออนุมัติ

**ข้อจำกัดธุรกิจสำคัญ**
1. Bplus ERP MSSQL เป็นแหล่งข้อมูลต้นฉบับ (READ ONLY); Windows Sync Agent ผ่าน VPN อ่านด้วย SELECT-only และ POST ไป API ผ่าน HTTPS 443; ห้ามเขียนกลับ ERP / ห้ามเปิด MSSQL 1433 สู่ internet
2. ERP Mirror ประกอบลูกค้า สินค้า บิลขาย รายการบิล สถานะยกเลิก คืนสินค้า สาขา และพนักงานขาย ใช้คีย์ที่ยืนยันจาก Schema จริง ไม่เดาชื่อตาราง
3. CRM มีทะเบียนลูกค้า Visit Plan เช็กอินร้าน กิจกรรม ติดตามปัญหา Dashboard วิเคราะห์ยอดขาย เทียบ Week สายขาย/ผู้จัดการ และ RBAC
4. Member มีสมัคร/ยืนยันตัวตน, ผูก ERP customer หลัง verify ownership/consent, ประวัติซื้อ, คะแนน, ระดับสมาชิก; ห้ามเห็นข้อมูลลูกค้าคนอื่น
5. Loyalty ใช้ append-only points ledger แยกคะแนนที่อนุมัติจากคะแนนประเมินลูกค้า, implement idempotent earning/reversal on refund/cancel, auditable manual adjustment
6. LINE OA webhook ต้อง validate raw HMAC SHA256; ใช้ LINE Login OAuth2 + state/nonce/PKCE ตามข้อกำหนด แล้ว link LINE user ID กับ Member ที่ยืนยันแล้ว; ไม่ใช้การเดาจากเบอร์โทร/ชื่อ
7. PostgreSQL: migrations, indexes, transactional upsert, reconciliation job, unit tests, security tests, metrics, backups, queue worker, error alerts
8. ระบบต้องใช้ไทยเป็นหลัก ปรับเป็น mobile-first; แยกธีม/เมนู CRM และ Member แต่ reuse shared design tokens

**วิธีทำงาน**
- ตรวจ README และเอกสาร docs ใน ZIP นี้ก่อน
- เริ่มจากสำรวจโครงสร้างและรัน tests; แจ้ง gap ที่พบตามจริง
- ทำการ migrate/provision DB ใน environment ใหม่ ไม่แตะ production data เดิม
- ให้ผลลัพธ์เป็น commit แยก phase, พร้อม migrations, tests, screenshot และคำสั่ง deploy/rollback
- ทุกครั้งที่แก้ API ต้องอัปเดต docs/API_CONTRACT.md
- ห้าม hardcode token/รหัสผ่านหรือข้อมูลส่วนบุคคล และห้ามใช้ `TrustServerCertificate=yes` กับ Production โดยไม่ผ่าน security review
- เมื่อ Query MSSQL ของจริงยังไม่ทราบ ให้กำหนด integration contract + mock; รอ mapping และ SQL read-only จากผู้ใช้ก่อนสร้าง Adapter จริง

**เกณฑ์ผ่าน**: รับ demo sync, idempotent upsert, login CRM/Member แยก session, Member A อ่านข้อมูล Member B ไม่ได้, LINE signature wrong reject, ปิด VPN แล้ว queue retry, reconcile ยอดขายอย่างน้อย 3 ช่วงเวลา
