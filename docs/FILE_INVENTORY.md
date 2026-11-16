# FILE INVENTORY — `d:\crm-member\`

**Audit Date:** 2026-11-15  
**Scope:** สำรวจไฟล์แบบ Recursive ทั้งหมดใน `d:\crm-member\` (ไม่รวมเนื้อหาภายใน `vendor/`, `node_modules/`, `.git/`, `storage/framework/`, `storage/logs/`, `bootstrap/cache/`)

---

## 1. สรุปจำนวนไฟล์ทั้งหมดที่ตรวจพบใน Workspace

| กลุ่มโฟลเดอร์ / โปรเจกต์ | จำนวนไฟล์ | หมายเหตุสำคัญ |
|---|---:|---|
| Legacy Root (`d:\crm-member\*.php`, `*.csv`) | 174 | PHP 172 ไฟล์ + CSV รายงาน Bplus 2 ไฟล์ (`sql.csv`, `รายงาน.csv`) |
| Legacy `d:\crm-member\db\` | 8 | Config JSON, ODBC/MySQL connectors, Helpers, Web Reserve SQL schema |
| Legacy `d:\crm-member\database\` | 1 | `install_web_reserve_mysql.php` |
| `popstar-platform\` (V1 Skeleton) | 35 | ต้นแบบเริ่มต้น (`docker-compose.yml`, Laravel Module stubs, Vite placeholders) |
| `popstar-business-platform-v2\` (V2 Overlay & Python Agent) | 58 | มี `PopstarSecurityTest.php` และ `sync-agent/*.py` (ใช้ `pyodbc`) |
| `popstar-business-platform-v4\` (V4 Active Docker + Laravel 13 + Vue 3) | 154 | ไม่รวม `vendor/`, `node_modules/`, `bootstrap/cache/` (+3 ไฟล์ใน `bootstrap/cache`) |
| ไฟล์บีบอัด / Shortcut / Office (`*.zip`, `*.rar`, `*.7z`, `*.lnk`, `*.xlsx`, `*.pptx`) | **0** | ตรวจแบบ Recursive แล้วไม่พบไฟล์กลุ่มนี้เลย |
| ไฟล์สำคัญที่ถูกอ้างถึงแต่ **ไม่พบใน Workspace** | **4 จุด** | `popstar-modern-mockup/index.html`, `storage/private/secrets.php`, `assets/*`, `storage/reports/*` |

---

## 2. Project File Inventory (จำแนกไฟล์จริงตามองค์ประกอบสำคัญ)

### 2.1 ไฟล์ SQL, Database Configuration และไฟล์ที่ขาดหาย (Root, `db/`, `database/`)

| File / Path | ประเภท | ข้อมูลสำคัญ | นำไปใช้กับโมดูล | สถานะ |
|---|---|---|---|---|
| `sql.csv` | SQL / Bplus CSV | 1,145,303 B, 23,400 lines, UTF-8 BOM, Comma `,` + RFC-4180 Quotes, 1,000 รายงาน (`404` มี SQL) | Reference ทั้งหมด | Unused in V4 Runtime (Analyzed) |
| `รายงาน.csv` | SQL / Bplus TSV | 1,143,299 B, 23,400 lines, UTF-8 BOM, Tab `\t` **ไม่มี Quote** (Multiline SQL แตกหากใช้ Quoted parser), ข้อมูลตรงกับ `sql.csv` ทั้ง 1,000 แถว (`0` non-whitespace SQL diffs) | Reference ทั้งหมด | Unused / Duplicate Data of `sql.csv` |
| `security.php` | Auth / Secrets Loader | 103 lines; โหลด `storage/private/secrets.php` ผ่าน `popstar_secrets()`, จัดการ Session & CSRF | Core / Auth | Legacy Broken (Missing `storage/private/secrets.php`) |
| `db/connect.php` | DB Config (ODBC) | 31 lines; ใช้ `odbc_connect($database, $username, $password)` จาก `popstar_secrets()['erp']` | ERP ODBC Legacy | Legacy Broken (Missing `secrets.php` + ไม่มี DSN) |
| `db/helpers.php` | Helper Functions | 97 lines; แปลง encoding TIS-620/UTF-8 และ query helpers (`odbc_*`) | ERP Legacy | Unused in V4 |
| `db/mysql_connect.php` | DB Config (MySQL) | 75 lines; เชื่อม MySQL สำหรับ Web Reserve | Member / Reserve | Unused in V4 |
| `db/web_reserve_schema.sql` | SQL Schema (MySQL) | 81 lines; ตาราง `WEB_RESERVE_HEADER`, `WEB_RESERVE_ITEMS`, `WEB_RESERVE_LOGS` | Reserve / Order | Unused in V4 (V4 ใช้ PostgreSQL) |
| `database/install_web_reserve_mysql.php` | DB Installer | 31 lines; รัน `db/web_reserve_schema.sql` ลง MySQL | Reserve / Order | Unused in V4 |
| `db/menu_config.json` | UI / Menu Config | 41 lines; เปิด/ปิดเมนู 40 รายการ (เช่น `p_reportZ: false`) | Reporting Shell | Unused in V4 |
| `db/roles.json` | RBAC Roles | 97 lines; ระบุ user codes จริง (`role_admin` 7, `role_account` 8, `role_sales` 21) + เมนูตามสิทธิ์ | Auth / Staff | Reference Only |
| `db/cron_config.json` | Scheduler Config | 82 lines; งาน cron 4 ตัว (`kpi_daily`, `damage_alert`, `voucher_notify`, `alert_rg`), เคยรันบน MAMP/Windows Apache (`secret: ""`) | Reporting / Notify | Unused in V4 |
| `db/notify_config.json` | Alert Channels | 24 lines; Telegram 3 กลุ่ม & LINE (`bot_token: ""`, `channel_access_token: ""`) | Notification | Unused in V4 |
| *(Missing)* `storage/private/secrets.php` | Secrets PHP | ถูก `require` โดย `security.php`, `db/connect.php`, `api_erp_lookup.php`, `p_sales_dashboard.php` | ERP / Notify | **Missing from Workspace** |
| *(Missing)* `assets/*` | Frontend Assets | CSS/JS (`popstar-vue-ui.css`, `popstar-vue-ui.js`, AdminLTE, FontAwesome, DataTables, Select2) ถูกอ้างโดย `head.php`, `login.php`, `menu_l.php`, `footer.php` | Legacy UI | **Missing from Workspace** |
| *(Missing)* `storage/reports/*` | Saved SQL/Reports | ถูกอ้างโดย `p_db.php`, `report_list.php`, `sql_viewer.php`, `view_all_sql.php` | Reporting | **Missing from Workspace** |
| *(Missing)* `popstar-modern-mockup/index.html` | UI Mockup Baseline | ถูกระบุในคำสั่งแก้ไข UI แต่ค้นหาแบบ Recursive แล้วไม่พบ | UI / Frontend | **Missing from Workspace** |

### 2.2 ไฟล์ Legacy PHP สำคัญใน Root (`d:\crm-member\*.php` — คัดกลุ่มจากทั้งหมด 172 ไฟล์ PHP)

> ภาพรวมจากการสแกนสคริปต์: **136 ไฟล์** ใช้ `odbc_*`, **3 ไฟล์** ใช้ MySQL, **113 ไฟล์** ใช้ `TIS-620`/`iconv`, **126 ไฟล์** `include("header.php")`, **77 ไฟล์** มี `auth_guard.php` หรือ `popstar_require_login()`, **51 ไฟล์** ใช้ DataTables, **16 ไฟล์** ใช้ Chart.js, **2 ไฟล์** มี `INSERT`/`DELETE` (`p_sales_by_category_top_branch.php` ใช้ temp table `#L`, และ `view_current_user.php` ใช้ `DELETE FROM BPLUSCURRENTUSER`). **ทุกไฟล์แยกขาดจาก V4 (ไม่ได้เชื่อม runtime)**

| File / Path | ประเภท | ข้อมูลสำคัญ | นำไปใช้กับโมดูล | สถานะ |
|---|---|---|---|---|
| `login.php`, `check_login.php`, `logout.php` | Auth / Legacy UI | `check_login.php` เช็ค `USERTAB` (`USER_CODE`, `USER_PASSWORD`) ผ่าน ODBC + แปลง `TIS-620` | Auth | Legacy Standalone / Broken (Missing `assets/*` & DSN) |
| `auth_guard.php`, `auth_roles.php` | RBAC Legacy | 413 lines + 55 lines; คุมสิทธิ์ตาม `$menu` กับ `$restrictedMenus` | Auth / CRM | Unused Reference |
| `head.php`, `header.php`, `navbar.php`, `menu_l.php`, `footer.php` | AdminLTE + Vue 3 CDN Shell | `head.php` ใช้ AdminLTE + Bootstrap 4 + Vue 3.5.13 CDN + `popstar-vue-ui.css/js`; `menu_l.php` Sidebar 634 lines | UI Shell | Legacy Broken (Missing `assets/*`) |
| `index.php` | Legacy Entry / POS Dash | 495 lines; `DT_DOCCODE IN ('PS1'..'PS5') OR DT_PROPERTIES = 207`, map สาขา ดอนกลาง/วาริน/ปลาดุก/เจริญศรี-1/เจริญศรี-2 | Dashboard / POS | Unused in V4 |
| `p_executive_dashboard.php`, `p_sales_dashboard.php`, `p_warroom.php`, `p_warroom2.php`, `p_dashboard.php` | BI / KPI Dashboards | Query จาก `SKUMOVE`/`DOCINFO`/`DOCTYPE`/`SLDETAIL`/`ARFILE`/`ICCAT`; `p_warroom.php` แยกทุน-กำไร และตัดหมวด `'CC','CO','AS','PK'` | Reporting / Dashboard | Legacy Standalone (มี Logic SQL สูงมากที่ V4 ยังไม่ใช้) |
| `p_sale-BI.php`, `p_sale-BI2..9.php`, `p_sale-BI-YOY.php` | Portfolio & Sales BI | ยอดขายแยกสายส่ง (`ARLINE`), พนักงานขาย, YOY Comparison, Sticky matrix table | Reporting / CRM | Legacy Standalone |
| `p_contact.php`, `p_contact2.php`, `p_contactproduct.php`, `p_customer_calendar.php`, `p_customer_calendar2..4.php`, `p_customer_rank.php`, `p_customer_purchase_detail.php`, `get_customers.php` | CRM / Customer History | **แหล่ง Logic หลัก**: `p_contact2.php` (ลูกหนี้+เครดิต), `p_customer_calendar4.php` (`AR_SLMN`/`AR_SLMNCODE`/`ARA_SLMN` + `DT_PROPERTIES IN (207,302,307,308,337)`), `p_customer_rank.php` | CRM / Customer 360 | Legacy Standalone (ควรพอร์ตมาช่วย CRM V4) |
| `api_erp_lookup.php`, `get_doc_details.php`, `get_daily_sales.php` | Legacy Lookup API | `api_erp_lookup.php` ชี้ตรง DSN `'BPLUSERP_POPSTAR_2021'`, user `'jetsada'`, แปลง `TIS-620` ค้นหา `SKUMASTER`/`ARFILE`/`GOODSMASTER`/`ARPLU` | ERP / Product Lookup | Legacy Broken (Missing `secrets.php` & DSN) |
| `api_web_reserve.php`, `p_web_reserve.php`, `p_reserve_cover.php`, `p_reserve_list2.php`, `p_reconciliation_reserve_vs_credit.php` | Reserve / Order & Reconcile | จองสินค้าผ่านเว็บ MySQL + เทียบใบจอง (`DT_PROPERTIES=207`, `DI_REF LIKE 'B%'`, `AROE`) กับใบขายจริง (`TRD_REFER_TRD`) | Sales / Reconcile | Legacy Standalone |
| `p_sales_integrity_report.php`, `p_sales_integrity_report2.php`, `reconcile_b_vs_sales.php`, `reconcile_b_vs_sales1.php`, `p_ds-dsn-1.php`, `p_ds-dsn-2.php` | Sales Audit & Integrity | ตรวจบิลขาย vs ใบจอง, DS/DSN, ตัดหมวด `'901 AS','CC','CO','PK'`, เทียบ `TRD_G_AMT` vs `TRD_B_AMT` | Reporting / Audit | Reference / Reconciliation Baseline |
| `p_product.php`, `p_product1.php`, `p_stock.php`, `p_stock_planning.php`, `p_ai_stock_recommend.php`, `p_pricePercent.php`, `p_saleLoss.php` | Product / Stock / Pricing | สต็อกคงเหลือ, ราคา `ARPRICETAB`/`ARPLU`, จุดสั่งซื้อ, ขายต่ำกว่าทุน | Product / Stock | Unused in V4 |
| `sql_viewer.php`, `view_all_sql.php`, `report_list.php`, `p_db.php` | Legacy SQL Admin | ตรวจ query ใน `storage/reports` | Core / Admin | Legacy Broken (`storage/reports` missing) |
| `view_current_user.php` | Bplus Session Manager | อ่านและลบ session ใน `BPLUSCURRENTUSER` (`DELETE FROM BPLUSCURRENTUSER WHERE BCU_KEY = ?`) | ERP Admin Only | **Write-capable on Bplus** (ห้ามพอร์ตลง Sync Bridge) |

### 2.3 โปรเจกต์ยุคเก่า (`popstar-platform` & `popstar-business-platform-v2`)

| File / Path | ประเภท | ข้อมูลสำคัญ | นำไปใช้กับโมดูล | สถานะ |
|---|---|---|---|---|
| `popstar-platform/` (35 files) | V1 Prototype | `docker-compose.yml`, Laravel Module stubs (`Routes`, `ModuleBoundary.php`), `frontend/crm` & `member` placeholder, Windows `.cmd/.ps1` launcher | Reference History | Superseded by V2/V4 (Unused) |
| `popstar-business-platform-v2/` (58 files) | V2 Architecture | Laravel overlay + Python Sync Agent (`main.py`, `agent/sql_reader.py`, `agent/queue.py`) + Vue Prototype (`App.vue`) | Reference History | Superseded by V4 (Unused) |
| `popstar-business-platform-v2/backend-overlay/tests/Feature/PopstarSecurityTest.php` | PHPUnit Feature Tests | 254 lines; ทดสอบ Health, Sync Token 401, Idempotency 409, Staff/Member guard isolation, Member invoice scoping, Sales customer scoping, LINE HMAC 401 | Testing / Security | **Missing in V4** (สำคัญ ควรพอร์ตกลับมา V4) |

### 2.4 โปรเจกต์ที่กำลังรันจริง (`popstar-business-platform-v4` — 154 Tracked Files)

| File / Path | ประเภท | ข้อมูลสำคัญ | นำไปใช้กับโมดูล | สถานะ |
|---|---|---|---|---|
| `compose.yaml`, `.env` | Docker Orchestration | 5 services (`db`, `app`, `backend_web:18080`, `crm:18081`, `member:18082`), `extra_hosts: host.docker.internal:host-gateway` | Infrastructure | **Used / Running** (Up 5/5) |
| `docker/php/Dockerfile`, `docker/web/site.conf`, `docker/frontend/*` | Container Config | PHP-FPM (`USER www-data` -> `/var/www/.config/psysh` not writable), Nginx proxy `/api` & Vue SPA fallback | Infrastructure | **Used / Running** (มีประเด็น PsySH permission) |
| `backend/` vs `backend-overlay/` (23 overlay files) | Sync Check | Hash SHA256 ตรงกัน 100% ทุกไฟล์ (`Diffs: 0`); `backend/` เป็น Laravel 13 full tree (74 tracked files + `vendor/` แบบ `--no-dev`) | Backend Core | **Used / Running** |
| `backend/routes/web.php`, `routes/console.php`, `bootstrap/app.php`, `config/auth.php`, `config/popstar.php` | Routing, Auth & Config | 49 API routes; แยก session guards `staff` (`StaffUser`) และ `member` (`MemberAccount`); `VerifySyncToken` (>=32 chars); CLI `popstar:create-staff-admin`, `popstar:link-member` | Core / Security | **Used / Running** |
| `backend/app/Modules/Core/HealthController.php` | Health Check | คืน `{"status":"ok","database":"ok"}` | Core | **Used / Running** (ไม่ตรงกับ test spec V2 ที่คาด `ok: true`) |
| `backend/app/Modules/CRM/StaffAuthController.php`, `CrmController.php`, `FieldController.php` | CRM & Field Backend | ครบ Auth, Salespersons, Customers/Detail, Dashboard KPI, Quick Lead, Dedup, Pipeline Stage, Approval, Photos (`storage/app/private`), Pins, Plans/Stops, Visits 6 ข้อ, Follow-up Tasks | CRM / Lead / Field / Map | **Used / Code Ready** (`0` rows ใน DB ยังไม่เชื่อม ERP จริง) |
| `backend/app/Modules/ERPIntegration/ErpLocalSyncController.php`, `SyncController.php` | ERP Bridge & Ingestion | `ErpLocalSyncController` เรียก `http://host.docker.internal:18900`; `SyncController` รับ batch idempotent max 250 records/req (customers, products, invoices+items) | ERP Sync | **Used / Blocked** (`SQL_CONNECTION_STRING` ว่างใน `config.env`) |
| `backend/app/Modules/Member/MemberAuthController.php`, `MemberController.php` | Member Backend | Register/Login/Me/Logout + `invoices()` (เฉพาะ `verified_at IS NOT NULL`) + `points()` (sum ledger) | Member | **Used / Partial** (ขาด Rewards, Profile update, Verification/Link API) |
| `backend/app/Modules/LINE/LineWebhookController.php` | LINE Webhook | ตรวจ `X-Line-Signature` HMAC SHA-256 + ตอบข้อความคงที่ | LINE OA | **Used / Stub** (ยังไม่มี LINE Login / Token ใน `.env`) |
| `backend/database/migrations/*` (9 migrations) | DB Schema | `000100` ERP Mirror, `000200` CRM, `000300` Member Loyalty, `000400` Field Ops, `000500` ERP Phase 1, `000600` Lead Acquisition | Database | **Used / Ran `[1]..[3]`** (`31` tables ใน Postgres; มี `database.sqlite` เก่าค้างอยู่ 98KB ไม่ได้ใช้) |
| `backend/tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | Tests | มีแค่ Laravel default `ExampleTest` (`$this->get('/')` -> จะ fail เพราะไม่มี route `/`) + `vendor/bin/phpunit` ไม่มี | Testing | **Broken / Incomplete** |
| `sync-agent/local_bridge.ps1`, `start-local-bridge.cmd`, `config.env` | Windows Local Bridge | `.NET SqlClient` HTTP listener บน `127.0.0.1:18900`, SELECT-only enforcement, แปลง UTF-8 | ERP Bridge | **Running (`sql_configured: false`)** — `config.env` บรรทัด 7 ว่าง |
| `sync-agent/queries/bplus_*.sql` (5 files) + `customers.sql` | Bridge SQL Queries | `bplus_customers_10.sql`, `bplus_customers.sql`, `bplus_products.sql`, `bplus_invoices.sql`, `bplus_sales_items.sql` (และ placeholder `customers.sql`) | ERP Sync | **Needs Revision** (ขาด `DT_PROPERTIES=207`, Member queries) |
| `sync-agent/main.py`, `agent/*.py`, `tests/test_queue.py` | Python Batch Sync Agent (V2 leftover) | ใช้งานไม่ได้บนเครื่องที่ไม่มี Python (`pyodbc`); ถูกแทนที่ด้วย `local_bridge.ps1` | ERP Sync (Alt) | Unused in V4 Docker |
| `web/crm/src/App.vue`, `CustomerMap.vue`, `web/member/src/App.vue`, `web/shared/base.css` | Frontend Vue 3 | CRM `App.vue` 1,075 บรรทัด (เมนู 10 ปุ่มแถวบน), `CustomerMap.vue` 67 บรรทัด, Member `App.vue` 20 บรรทัด, `base.css` 17 บรรทัด | CRM / Member UI | **Used / UI Refactor Needed** (Prototype, ไม่มี Mockup baseline) |
| `docs/*` (13 files) | V4 & V2 Documentation | คู่มือติดตั้ง, Architecture, API Contract, SQL Mapping ปัจจุบัน (+8 Audit docs ใหม่ในรอบนี้) | Docs | Reference / Updated |
