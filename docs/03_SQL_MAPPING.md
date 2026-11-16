# 03 — สำรวจ SQL จริง ก่อนต่อระบบ Production

จาก PC บ้านต่อ VPN ไป MSSQL ด้วยบัญชี SELECT-only. ห้ามใช้ sa และอย่าเปลี่ยน schema ERP.

## 1) ตรวจชื่อ Table/View ใน DB ที่เชื่อมได้
```sql
SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE
FROM INFORMATION_SCHEMA.TABLES ORDER BY TABLE_SCHEMA, TABLE_NAME;
```

## 2) ค้น Column ที่เกี่ยวข้อง
```sql
SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE COLUMN_NAME LIKE '%CUS%' OR COLUMN_NAME LIKE '%CODE%'
   OR COLUMN_NAME LIKE '%DATE%' OR COLUMN_NAME LIKE '%AMOUNT%'
ORDER BY TABLE_NAME, ORDINAL_POSITION;
```

## 3) Data Contract ที่ต้องแมป
`erp_customers`: source_system, erp_customer_code, customer_name, phone?, salesperson_code?  
`erp_products`: source_system, erp_product_code, product_name, unit?  
`erp_sales_invoices`: source_system, branch_code, doc_type, invoice_no, erp_customer_code, sold_at, net_amount, status

ต้องยืนยันว่า ERP มีรหัสลูกค้าที่ unique ภายใต้ source, เอกสารขายใช้ Branch+DocType+DocNo แยกครบ, บิลยกเลิก/คืนสินค้าเป็นอย่างไร, ภาษี/ส่วนลดสะท้อน `net_amount` อย่างไร

**อย่าเดาชื่อ Table ของ Bplus**: SQL Reader ใน `sync-agent/queries/customers.sql` อ้าง VIEW สมมติ `dbo.vw_crm_customers` ที่ DBA ต้องจัดเตรียม/เปลี่ยนให้ตรง. เปลี่ยน query ของ Reader ได้โดยไม่เปลี่ยน Cloud API

## 4) ตรวจยอดตรงกับ ERP
- เลือกช่วง 1 วัน / 1 สัปดาห์ / 1 เดือนเปรียบเทียบจำนวนบิล สถานะ และยอดสุทธิ
- เก็บเครื่องหมายและสถานะเอกสารคืน/ยกเลิกให้ถูกต้องก่อนคิด Loyalty
- ไม่ Sync ชื่อซ้ำเป็นคนละร้านโดยไม่มี `source_system + erp_customer_code`
- ห้าม link Member กับ Customer ด้วยชื่อ/เบอร์โทรอย่างเดียว
