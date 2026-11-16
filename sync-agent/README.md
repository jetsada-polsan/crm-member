# Windows PC → ERP MSSQL via VPN → Cloud HTTPS

## POC demo ก่อนต่อ SQL จริง
1. ติดตั้ง Python 3.11+ บน Windows PC
2. เปิด PowerShell, `cd sync-agent`
3. ตั้งค่า Environment Variables หรือใช้ `config.env.example` เป็นรายการอ้างอิง (โค้ด **ไม่อ่าน .env อัตโนมัติ**)
```powershell
$env:SYNC_BASE_URL = 'https://api.YOURDOMAIN.COM'
$env:SYNC_API_TOKEN = 'YOUR_LONG_RANDOM_TOKEN'
python main.py demo
```
4. โปรแกรมจะสร้างไฟล์ Queue ใน `%LOCALAPPDATA%/PopstarSync/queue.sqlite3` (หรือ `SYNC_QUEUE_PATH`)
5. หากส่งไม่ได้ จะเก็บคิวแล้วทดสอบใหม่ `python main.py flush`
6. ดูข้อมูล Cloud จาก CRM หรือ SQL query ตรวจสอบ

## เชื่อม MSSQL จริง (หลังตรวจ Schema ERP และ VPN)

For the local Windows bridge, the preferred credential setup is `powershell -File .\configure_sql_connection.ps1`. It requests the password through hidden terminal input and stores it in `config.env` protected with Windows current-user DPAPI. The bridge must run as the same Windows user that configured the password. `sync-agent/config.env` is excluded from Git. Never use `sa`; obtain a SELECT-only ERP account from the DBA.

```powershell
py -m pip install pyodbc
$env:SQL_CONNECTION_STRING = 'DRIVER={ODBC Driver 18 for SQL Server};SERVER=10.0.0.10,1433;DATABASE=ERP_DB;UID=read_only;PWD=...;Encrypt=yes;TrustServerCertificate=no;'
python main.py customers
```

`queries/customers.sql` เป็น **VIEW สมมติ** ต้องปรับให้ตรงกับ Query ที่ผ่านการตรวจของ ERP จริง โดย account SQL มี SELECT-only และติดตั้ง ODBC Driver 18. ชื่อ Server/IP ของ MSSQL ผ่าน VPN เท่านั้น

## Schedule
สร้าง Windows Task Scheduler ให้ทำ `python main.py customers` ทุก 15–30 นาทีหลังทดลองครบ ไม่ควรใช้ Task ที่รันเป็น Admin, ต้องกำหนด Secret Store/credential อย่างปลอดภัยและตรวจ Log/Alert ก่อนเปิด Production

## ไม่ต้องใช้ Public IP บ้าน
Agent เป็นฝ่าย POST ไป API บน VPS TCP/443 (Outbound). ไม่ต้องเปิดพอร์ต SQL, ไม่ต้อง Port Forward บ้าน, ไม่ต้องชี้ DNS ไป IP บ้าน

## คำเตือน
- Demo ส่ง sample customers เท่านั้น; invoices / products ให้เลือก method เพิ่มด้วย query ที่ DBA ยืนยันก่อน
- Environment Variable ใส่ Password ใน Console มีความเสี่ยงถูกเห็นใน History/Process ให้ใช้ Windows Credential Manager/DPAPI ใน Production
- SQL Server ODBC Driver18 กำหนด `Encrypt=yes;TrustServerCertificate=no`; ต้องมี Certificate ที่ Windows เชื่อถือ (ห้าม disable verification โดยพลการ)
