# API Contract v4 (Local & Production)

## Sync (Windows agent → Laravel)
`POST /api/sync/v1/customers`, `/api/sync/v1/products`, `/api/sync/v1/invoices`  
Header: `Authorization: Bearer <SYNC_API_TOKEN>` / `Content-Type: application/json`
- Customers accept optional `salesperson_code`, `salesperson_name`, `province`, `address`, `ar_enable`.
- Invoices accept optional `items[]` (`line_no`, `erp_product_code`, `product_name`, `qty`, `unit_name`, `unit_price`, `net_amount`).

## CRM (guard: `staff`)
- Auth: `GET /api/crm/csrf`, `POST /api/crm/login`, `POST /api/crm/logout`, `GET /api/crm/me`
- Dashboard & Customers:
  - `GET /api/crm/dashboard`
  - `GET /api/crm/salespersons` (distinct routes accessible to logged-in staff)
  - `GET /api/crm/customers?salesperson_code=&q=` (includes `invoice_count`, `total_sales_net`)
  - `GET /api/crm/customers/{id}` (customer info + `invoices` + `items`)
- Field Sales & Customer Acquisition:
  - `GET /api/crm/leads?stage=&approval_status=&q=&minimal_only=`
  - `POST /api/crm/leads` / `POST /api/crm/leads/quick` (multipart optional `photo`, GPS, duplicate guard `409`)
  - `POST /api/crm/leads/check-duplicate`
  - `GET /api/crm/leads/import-template`, `POST /api/crm/leads/import` (CSV upload with duplicate skip)
  - `GET /api/crm/leads/{id}` (includes location, photos, tasks, ERP purchase history, audit logs)
  - `PATCH /api/crm/leads/{id}`, `PATCH /api/crm/leads/{id}/stage`, `POST /api/crm/leads/{id}/review` (manager/admin)
  - `POST /api/crm/leads/{id}/photos`, `GET /api/crm/lead-photos/{photoId}`, `POST /api/crm/leads/{id}/tasks`
  - `GET/POST /api/crm/pins`, `GET/POST /api/crm/plans`, `POST /api/crm/plans/{planId}/stops`, `POST /api/crm/field-visits`, `POST /api/crm/field-visits/{visitId}/photos`, `GET /api/crm/photos/{photoId}`, `GET /api/crm/tasks`, `POST /api/crm/tasks/{taskId}/complete`
- Admin/Manager ERP Sync Monitor (server-side only):
  - `GET /api/crm/sync-monitor/status`
  - `POST /api/crm/sync-monitor/test-connection`
  - `POST /api/crm/sync-monitor/test-customers-10`
  - `POST /api/crm/sync-monitor/sync/{kind}` (`customers` | `products` | `invoices`)

## Member (guard: `member`)
- `GET /api/member/csrf`, `POST /api/member/register`, `POST /api/member/login`, `POST /api/member/logout`, `GET /api/member/me`, `GET /api/member/invoices`, `GET /api/member/points`

## LINE
- `POST /api/line/webhook` → raw-body HMAC verification. Generic welcome reply only until verified identity linking.
