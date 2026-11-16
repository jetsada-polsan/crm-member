# V4 API Contract (after auth)

## ERP
`POST /api/sync/v1/customers`, `/products`, `/invoices`: token + UUID batch_id; idempotent on `(source,kind,batch_id)`; see V2 sync-agent.

## CRM (staff session)
- `GET /api/crm/dashboard`, `/customers`, `/visits`
- `POST /api/crm/leads` `{shop_name,phone?,district?,address?}`
- `GET /api/crm/leads`
- `GET /api/crm/pins`
- `POST /api/crm/pins` `{erp_customer_id | crm_lead_id,latitude,longitude,accuracy_m?,address?,source:'gps'|'manual'|'map_link'}`
- `POST /api/crm/field-visits`: target id and `{visited_at, shop_type, result, supplier_name?,product_types?,customer_issues?,customer_feedback?,follow_up_action?,follow_up_date?,checkin_latitude?,checkin_longitude?}`
- `POST /api/crm/field-visits/{visitId}/photos` multipart `photo` + category; max 5MB; stored private
- `GET /api/crm/photos/{photoId}` protected attachment
- `GET /api/crm/plans`, `POST /api/crm/plans` (manager), `POST /api/crm/plans/{planId}/stops`
- `GET /api/crm/tasks`, `POST /api/crm/tasks/{taskId}/complete`

## Member
- `/api/member/register`, `/login`, `/me`, `/invoices`, `/points`; account to ERP link must be verified before showing purchase data
- **LINE OA** `/api/line/webhook` verifies HMAC and only returns generic welcome until secure account linking delivered

## Import approach
Do not send all ERP data blindly. One source-specific mapping adapter; read-only SQL query to JSON. `source_system` and `customer_code` form stable identity; do not match by name alone. Sales invoice identity includes branch code + document type + invoice no. Compare cancellations and returns before points calculation.
