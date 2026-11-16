# งานสำหรับ Antigravity: ทำให้ POPSTAR Connect ดึงข้อมูลจาก Bplus ERP อัตโนมัติ

> วางทั้งไฟล์นี้ให้ Antigravity แล้วสั่ง: "ทำตามไฟล์ ANTIGRAVITY-ERP-SYNC.md ทีละขั้น แล้วรายงานผลแต่ละขั้น"

## บริบทของระบบ (ตอนนี้)

- โปรเจกต์: `D:\xampp\htdocs\popstar-business-platform-v4` (Laravel 13 ใน `backend\`, Vue ใน `web\`)
- PHP: `D:\xampp\php\php.exe` (8.5) · DB: PostgreSQL 17 ในเครื่อง DB `popstar_business`
- เว็บ: CRM `http://popstar.localhost` · API `http://127.0.0.1:18090` (Apache vhost)
- **วิธีดึงข้อมูลจาก Bplus ที่ใช้อยู่ (แบบดึง / pull):**
  1. `sync-agent\local_bridge.ps1` คือ HTTP bridge ที่ฟังอยู่ที่ `http://127.0.0.1:18900` และอ่าน SQL Server `BPLUSERP_POPSTAR_2021` **แบบอ่านอย่างเดียว** (บล็อก INSERT/UPDATE/DELETE/EXEC และบล็อกบัญชี `sa`) เปิดด้วย `sync-agent\start-local-bridge.cmd` และใช้ค่าตั้งใน `sync-agent\config.env` (รหัสถูกเข้ารหัสด้วย DPAPI ของ Windows user ที่ตั้งค่า)
  2. Laravel `App\Modules\ERPIntegration\ErpLocalSyncController` เรียก bridge ตาม `LOCAL_ERP_BRIDGE_URL` / `LOCAL_ERP_BRIDGE_TOKEN` ใน `backend\.env` แล้ว upsert ลงตาราง `erp_*` ผ่าน `ErpMirrorUpserter`
  3. ตอนนี้ต้องกดเองในหน้า CRM: เมนู → ข้อมูล ERP (Bplus) → **สถานะดึงข้อมูล ERP** (route `POST /api/crm/sync-monitor/sync/{kind}`, kind = `customers | products | invoices | salespersons | members`, invoices ใช้ cursor ใน `erp_sync_cursors`)
- มีอีกทาง (push) คือ `sync-agent\main.py` → `POST /api/sync/v1/*` ด้วย `SYNC_API_TOKEN` **ห้ามใช้ในงานนี้** เพราะเครื่องนี้ยังไม่ได้ติดตั้ง Python จริง

## กฎที่ห้ามละเมิด

1. **Bplus ต้องอ่านอย่างเดียว** ห้ามเขียน แก้ หรือลบข้อมูลใน SQL Server ห้ามแก้ `local_bridge.ps1` ให้รับคำสั่งเขียน และห้ามใช้บัญชี `sa`
2. ห้ามแสดง commit หรือ log รหัสผ่านและ token (`backend\.env`, `sync-agent\config.env`, `backend\.pgpass-setup.txt`)
3. **Tests ห้ามยิงไปที่ bridge จริง:** ทุก test ที่เกี่ยวกับ bridge ต้องใช้ `Http::fake()` + `Http::preventStrayRequests()` (`phpunit.xml` บังคับ `LOCAL_ERP_BRIDGE_URL=http://host.docker.internal:18900` และ token ปลอมไว้แล้ว ห้ามลบ)
4. ห้ามแก้ schema ตาราง `erp_*` เดิม ถ้าจำเป็นให้เพิ่ม migration ใหม่
5. ห้าม push ขึ้น GitHub ให้ commit ในเครื่องเท่านั้น

## งานที่ต้องทำ

### ขั้น 1 — แยก logic sync ออกเป็น Service
- สร้าง `App\Modules\ERPIntegration\ErpBridgeSyncService` ย้าย logic จาก `ErpLocalSyncController::syncKind()` (เรียก bridge, cursor, `startRun`/บันทึก `sync_runs` / `sync_errors`, upsert) มาไว้ที่นี่
- ให้ Controller เรียก Service ตัวนี้แทน **พฤติกรรมหน้า CRM ต้องเหมือนเดิมทุกอย่าง**
- `php artisan test` ต้องผ่านทั้งหมด (ตอนนี้ผ่าน 23 tests / 376 assertions)

### ขั้น 2 — คำสั่ง Artisan
- สร้าง `php artisan popstar:erp-sync {kind?} {--limit=} {--full}` (ไม่ใส่ kind = ทำทุกชนิดตามลำดับ salespersons → customers → products → members → invoices)
- invoices ให้วนดึงทีละหน้าจนหมด หรือจนครบเวลา 50 วินาที (ห้ามรันค้างยาว)
- ถ้า bridge ไม่ตอบ (เช่นยังไม่เปิด) ให้จบด้วยข้อความชัดเจนและ exit code ≠ 0 โดยไม่ทำให้ระบบล่ม
- เพิ่ม feature test ด้วย `Http::fake` + `preventStrayRequests`

### ขั้น 3 — ตั้งเวลาอัตโนมัติ
- ใน `backend\routes\console.php` เพิ่ม schedule แบบ `->withoutOverlapping()->runInBackground()`
  - `invoices` ทุก 15 นาที (เฉพาะเวลา 07:00–21:00)
  - `customers`, `products`, `salespersons`, `members` ทุกวันเวลา 06:30
- ตัวรัน schedule มีอยู่แล้ว: Task Scheduler `POPSTAR schedule-run` เรียก `tools\run-scheduler.vbs` ทุก 1 นาที (log: `backend\storage\logs\scheduler.log`)

### ขั้น 4 — ให้ bridge เปิดเองตอนเปิดเครื่อง
- สร้าง Task Scheduler ชื่อ `POPSTAR ERP bridge` แบบ **At log on** ของ user ปัจจุบัน (ไม่ใช่ SYSTEM เพราะ DPAPI ผูกกับ user) ให้รัน `sync-agent\start-local-bridge.cmd` แบบซ่อนหน้าต่าง (ทำ `.vbs` wrapper แบบเดียวกับ `tools\run-scheduler.vbs`)
- ถ้า bridge รันอยู่แล้ว (พอร์ต 18900 ถูกใช้) ห้ามเปิดซ้ำ

### ขั้น 5 — ตรวจผล
1. `php artisan test` ผ่านทั้งหมด
2. `php artisan popstar:erp-sync salespersons --limit=5` ดึงได้จริง และ `sync_runs` มีแถวใหม่สถานะสำเร็จ
3. หน้า CRM → สถานะดึงข้อมูล ERP แสดงเวลาซิงก์ล่าสุดตรงกัน
4. รอ 15 นาที แล้วดู `scheduler.log` ว่า invoices ถูกเรียกอัตโนมัติ
5. commit: `feat(erp): scheduled Bplus bridge sync via artisan command`

## วิธีดึงข้อมูลเองระหว่างนี้ (ยังไม่ต้องให้ Antigravity ทำ)
1. ดับเบิลคลิก `sync-agent\start-local-bridge.cmd` (ถ้ายังไม่ได้เปิด)
2. เปิด `http://popstar.localhost` → login แอดมิน → เมนู → ข้อมูล ERP (Bplus) → **สถานะดึงข้อมูล ERP**
3. กด "ทดสอบการเชื่อมต่อ" ก่อน แล้วกดซิงก์ทีละชนิด (พนักงานขาย → ลูกค้า → สินค้า → สมาชิก → ใบขาย)
