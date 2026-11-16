# 04 — Security, Acceptance tests, Limits

## Tests ต้องผ่านก่อนขึ้นจริง
- [ ] GET /api/health ผ่าน HTTPS บน VPS
- [ ] Token ว่าง / Token ผิด → POST Sync 401
- [ ] HTTP จากเครื่องลูกข่าย redirect HTTPS และไม่ส่ง token บน HTTP
- [ ] batch ลูกค้าเดิมส่งซ้ำ → จำนวนแถวคงเดิม
- [ ] ส่ง payload เกินขนาดหรือเกินรายการ → 422/413
- [ ] ปิด VPN หรือเน็ตบ้านชั่วคราว → Agent ค้างใน SQLite แล้ว retry ได้
- [ ] Staff Login ใช้กับ Member ไม่ได้ และกลับกัน
- [ ] Member A ไม่เห็น Invoice ของ Member B
- [ ] Member ที่ยังไม่ verify link จะไม่เห็น ERP Invoice
- [ ] Staff role sales เห็นเฉพาะ customer ที่มี salesperson_code ของตน
- [ ] LINE webhook signature ผิด → 401, ไม่เรียก reply API
- [ ] LINE webhook ไม่มีการยืนยัน link → ห้ามเปิดเผยยอดซื้อ/คะแนน
- [ ] บิลยกเลิก/บิลคืน/แก้ไขบิล → ยอดตรงกับ ERP และคะแนนแก้ตาม event (หลัง implement Loyalty จริง)
- [ ] Log ไม่มี password/token/PII เต็ม ๆ
- [ ] Backup + Restore PostgreSQL ทดสอบจริง

## Production backlog (ไม่ได้ implement ครบใน starter)
- Verified linking ผ่าน LINE Login OAuth + ID token, e-mail/phone verification และ consent
- Password reset + email verification + MFA staff
- Queue worker retry และ schedule บน Windows เป็น service จริง
- Sync invoice details + return/reversal & reconciliation
- Loyalty rule engine/ledger posting ผ่าน idempotent event หลังอนุมัติ
- Audit trail การ link สมาชิก, point adjustments, staff permissions
- Automatic throttling, DB least privilege, WAF, alerting, metrics, retention policies
- การทำงานตาม PDPA และข้อตกลงประมวลผลข้อมูลส่วนบุคคล
