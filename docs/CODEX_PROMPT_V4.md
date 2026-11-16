# Prompt for Codex / Claude

You are a senior Laravel/Vue/Docker architect. Continue **Popstar Business Platform V4** without replacing its modular monolith with microservices. Read README.md and docs first. Keep backend as one Laravel app and DB as PostgreSQL; CRM and Member have separate authentication guards and frontends.

Non-negotiables:
1. Company Bplus SQL Server is SELECT-only via VPN from Windows Sync Agent. No writes/DDL to ERP, no exposing SQL on Internet.
2. Cloud receives only approved customers/products/invoices by idempotent HTTPS batches. A source/customer code and full invoice business key map ERP records; no blind name matching.
3. CRM must implement the six original survey items, visit history, GPS permanent pin + check-in, private images, lead, plan, follow-up.
4. Sales has scope only to assigned customers/leads; managers/admin can manage plans and targets. Harden server-side authorization and input validation.
5. Member account link requires verified proof. No public member data via LINE before LINE Login + verified binding.
6. Docker runs only on VPS; leave Host Nginx TLS reverse proxy existing sites intact, container ports host loopback only. Keep database backups and recovery tested.

Tasks in order:
- Run static analysis and architecture review; fix all issues in starter, especially strict model scopes, CSRF guards, session cookie host-only, photo security, transaction rollback.
- Complete Docker provisioning and reproducible dev/test workflow with integration tests.
- Build authenticated 6-field Visit on mobile with pin selection/accuracy and upload retry/offline draft.
- Build map/customer list, leads, plans stops, quotation generation/PDF, export reports, full-manager dashboard.
- Determine Bplus schema interactively from read-only query results; implement invoice items, sales incremental sync, cancellations, returns, reconciliation.
- Implement member tiers, approved points engine with reversal ledger, account verification, LINE Login + webhook reply, audit logs.
- Provide migrations, seeded dummy testing (not production customer data), API schema, docs, automated tests and rollback notes.

Never claim deployed or tested without command output. Never overwrite host VPS current Nginx or PostgreSQL without backup and permission. Ask for actual SQL mapping and domain only when necessary.
