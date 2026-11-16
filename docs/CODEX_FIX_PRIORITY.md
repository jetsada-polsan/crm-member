# Fix Priority — Popstar Business Platform V4

This is the remediation queue from the read-only audit, updated after implementation work. ERP live-schema validation and unsupported exports remain open.

## P0 — Before any production exposure

### P0.1 Remove known default bridge credential

**Status: FIXED in source and local sync configuration.** Bridge now requires an explicit 32+ character `BRIDGE_API_TOKEN`, rejects example placeholders and token reuse, and compares credentials without early character exit. Backend bridge calls use a separate setting. The local cloud/bridge tokens were rotated without printing their values.

- **Severity:** HIGH
- **File:** `sync-agent/local_bridge.ps1:26-28`
- **Root cause / impact:** Fixed development token fallback authenticates bridge requests when secrets are not configured.
- **Fix:** Fail closed without an explicit strong per-environment token; rotate development secrets; separate bridge/cloud tokens.
- **Retest:** Missing/default token denied; unique token accepted; test from Docker and local host.

### P0.2 Secure production runtime configuration

**Status: PARTIALLY FIXED.** Production example config requires replacement secrets, disables debug and enables secure cookies. Local sync credentials were rotated and env files remain ignored. Existing local database credentials and local debug/cookie settings were not changed because they may be tied to a running development database and HTTP environment; they must not be copied to production.

- **Severity:** HIGH
- **File:** `backend/.env:4,20-25`; `.env`; `sync-agent/config.env`
- **Root cause / impact:** Current local runtime values are explicitly development configuration, including debug and insecure cookie settings. Reuse in production risks credential exposure and session compromise.
- **Fix:** Use secret store, rotate values that were shared, set debug off and secure cookies, confirm env files are excluded from source/image. Git status could not be checked because Git is unavailable.
- **Retest:** Deployment secret/config audit without printing values; repository/image secret scan.

### P0.3 Serialize member point debits

**Status: FIXED in source.** Redemption locks the active member row before reading the ledger and locks the reward row in the same transaction. Sequential and member-isolation tests pass; database-level concurrent redemption has not been stress-tested against PostgreSQL.

- **Severity:** HIGH (data integrity)
- **File:** `backend-overlay/app/Modules/Member/MemberController.php:273-308`
- **Root cause / impact:** Redemption transactions do not lock the member balance across different rewards; concurrent requests can overspend.
- **Fix:** Add member-level transactional serialization and an invariant that prevents negative balances; add retry idempotency.
- **Retest:** Concurrent redemption test in isolated PostgreSQL/SQLite test DB; exactly one debit can consume the available balance.

## P1 — Before relying on ERP totals or loyalty statements

### P1.1 Implement paginated incremental ERP sync and reconciliation

**Status: PARTIALLY FIXED.** Invoice export now pages by stable `DI_KEY`, persists a PostgreSQL cursor, rereads the most recent 50 keys for late edits, fetches lines by document key, and refuses line sets above the supported complete-snapshot limit. Customer/product/member/salesperson paging and edits older than the overlap window still need source change-column confirmation and broader reconciliation.

- **Severity:** HIGH
- **Files:** `sync-agent/local_bridge.ps1:217-232`; `sync-agent/queries/bplus_invoices.sql:2,125`; `bplus_sales_items.sql:2,62`
- **Root cause / impact:** Bounded top-N exports lack a durable cursor; historical and changed older documents can be missed, and independently capped items can be incomplete.
- **Fix:** Add stable keyset/watermark paging, complete per-document line retrieval, overlap/reconciliation for edits/cancellations, and completeness indicators.
- **Retest:** More-than-one-page source fixture, older invoice change/cancel, retry, and exact source/mirror comparison.

### P1.2 Reconcile changed/deleted invoice lines on upsert

**Status: FIXED for explicitly complete snapshots.** `items_complete=true` triggers stale-line removal within the transaction; partial/legacy payloads do not delete data. Complete snapshots must include an `items` array. Regression tests cover complete and partial updates.

- **Severity:** MEDIUM
- **File:** `backend-overlay/app/Modules/ERPIntegration/ErpMirrorUpserter.php:85-140`
- **Root cause / impact:** Current line loop upserts received rows without reconciling absent old lines.
- **Fix:** Distinguish complete document snapshots from partial batches and reconcile complete line sets transactionally.
- **Retest:** Remove/update a source invoice line and re-sync; mirror line keys/amounts must match exactly.

### P1.3 Validate ERP schema, document classification, and SQL grants

**Status: BLOCKED for live validation.** Source code and migration mapping were reviewed, but no authorized SQL login/catalog access was available. The SELECT-only grant and actual table/column semantics must be verified by the ERP DBA before production use.

- **Severity:** HIGH if mapping or permissions differ from assumptions
- **Files:** `sync-agent/queries/*.sql`; `backend-overlay/app/Modules/ERPIntegration/SalesRules.php`; `docs/DATABASE_MAPPING.md`
- **Root cause / impact:** SQL mapping is source-authored and not checked against live Bplus catalog/results; SELECT text is not proof the login is actually read-only.
- **Fix:** DBA confirms columns, joins, document type/status semantics (including 308), VAT/return/cancellation behavior, and SELECT-only grants. Use approved limited SELECT previews only.
- **Retest:** Compare approved sample docs with ERP UI/accounting reports and verify SQL writes are denied by the DB login.

## P2 — Complete workflows and authorization proof

### P2.1 Define lead-to-customer handoff

**Status: PARTIAL.** Staff can review a lead and link it to an already-mirrored ERP customer. Automatic ERP customer creation remains intentionally unavailable until a separately approved process exists; no SQL Server writes were introduced.

- **Severity:** MEDIUM
- **File:** `backend-overlay/app/Modules/CRM/FieldController.php:624-652`
- **Root cause / impact:** Review links to an existing mirrored customer; it does not create an ERP customer. New acquisition cannot complete automatically.
- **Fix:** Define a safe manual or separately approved integration process; do not add ERP writes without authorization.
- **Retest:** Lead approval states and duplicate matching in staging; confirm no unauthorized ERP mutation.

### P2.2 Verify complete staff/member authorization matrix

**Status: PARTIALLY VERIFIED.** Existing staff role tests pass; a cross-member isolation case was added. Full per-resource role matrix and concurrency verification remain advisable.

- **Severity:** HIGH if a route misses an ownership check
- **Files:** `backend-overlay/routes/web.php`; `FieldController.php`; `CrmController.php`; `MemberController.php`
- **Root cause / impact:** Authorization is distributed among controller checks; existing test intent is promising but tests were not run.
- **Fix:** Add policies and negative tests for cross-staff/cross-member IDs and every sensitive action.
- **Retest:** Run actor/resource matrix in isolated test DB; verify all unauthorized cases return 401/403/404.

### P2.3 Finish reporting/UI acceptance

**Status: PARTIALLY FIXED.** Added a Reports tab with reconciliation and visit CSV downloads and browser print/PDF views. PowerPoint export and rendered desktop/tablet/mobile acceptance remain outstanding; Node/npm are not available in this session.

- **Severity:** LOW/MEDIUM
- **Files:** `web/crm/src/App.vue`; `web/member/src/App.vue`; `web/shared/base.css`
- **Root cause / impact:** CSV import exists but PDF/Excel/PowerPoint reporting, full chart coverage and responsive behavior are not verified.
- **Fix:** Implement approved report outputs and render against mockup; cover desktop/tablet/mobile and error/empty states.
- **Retest:** Browser screenshot/functional acceptance plus export file validation.

## P3 — Quality and operations

- Add image re-encoding/EXIF policy, malware scanning and retention controls for CRM photos.
- Add structured operational alerts for sync failures, stale cursor, partial line batches, and failed webhooks.
- Add pagination and query performance baselines for large ERP mirrors.
- Reduce duplicate source drift by making overlay generation reproducible and checking parity in CI.
