# Independent Project Audit — Popstar Business Platform V4

**Audit date:** 2026-11-15  
**Scope:** `D:\crm-member\popstar-business-platform-v4` plus workspace-level inventory note.  
**Method:** Initial source/config review followed by source remediation, local frontend builds, and browser review. Local app/CRM containers were rebuilt and restarted; the PostgreSQL volume was preserved. No migration/reset or ERP operation was run.  
**Overall status:** **PARTIALLY IMPLEMENTED / NOT READY FOR PRODUCTION**

## Executive summary

V4 is a Laravel 13/PostgreSQL and two Vue frontend development starter. The README itself calls it a POC and lists important production work as pending. Backend modules, migrations, UI routes, and sync code exist, but the ERP mapping and full/incremental sync are not validated against the live Bplus schema, production database state is inaccessible, and external LINE/ERP integration was not exercised. The supplied prior verification claims were not treated as evidence.

The most important issues found in this review:

1. **HIGH — Known fallback bridge token.** `sync-agent/local_bridge.ps1:26-28` uses a fixed development token when neither environment variable nor config file supplies a token. A copied/default deployment can therefore accept a publicly known bridge credential.
2. **HIGH — Loyalty balance race.** `backend-overlay/app/Modules/Member/MemberController.php:273-308` locks the reward row but not a member-scoped balance/ledger row. Concurrent redemptions of different rewards can each observe the same balance and spend it twice.
3. **HIGH — Default deployment config unsafe.** `backend/.env:4,20-25` has debug enabled, insecure session cookie, and a short known sync token; local `.env` files also hold development secrets. They are local configuration, not proof of production exposure, but must not be deployed or committed.
4. **HIGH — ERP sync incomplete for full history.** Invoice and item exports are independently top-limited (`sync-agent/local_bridge.ps1:217-232`, `sync-agent/queries/bplus_invoices.sql:2`, `bplus_sales_items.sql:2`), with no durable cursor/watermark. Older history may never be mirrored, and independently limited items may not cover every selected invoice.
5. **MEDIUM — Conversion to ERP customer is only a local link.** Lead review stores an existing mirror customer ID (`FieldController.php:635-649`); there is no ERP write/create operation, appropriately, so “lead becomes ERP customer” is not implemented.
6. **MEDIUM — Delivery/API idempotency only covers matching batch IDs.** Replaying the same event body under a new batch ID can reapply upserts; item rows are upserted but not reconciled/deleted when a source invoice later loses lines (`ErpMirrorUpserter.php:85-140`).

## Remediation since the initial audit

- The bridge now fails closed without a separate configured bridge token; local sync and bridge tokens were rotated. Loyalty redemption serializes debits by locking the member and reward. Complete invoice snapshots reconcile removed lines.
- Invoice exports now use a persisted `DI_KEY` cursor, reread a recent overlap, fetch lines per invoice, and reject incomplete/oversized snapshots. Live Bplus schema, grants, and older changed invoices still require DBA-approved validation.
- CRM now has CSV and print/PDF report views. The desktop dashboard and Reports/Export screen were rebuilt and inspected in the local browser. Mobile review and actual report download/print remain open.
- The local Docker build started the app and CRM containers and reported PostgreSQL healthy; no migration or database reset was performed. See [fix priority](CODEX_FIX_PRIORITY.md) and [test results](CODEX_TEST_RESULTS.md) for exact status.

## Inventory and project boundaries

Workspace root contains legacy PHP/report files and distinct `popstar-business-platform-v2`, `popstar-business-platform-v4`, and `popstar-platform` trees. This report audits V4 only; the root legacy PHP and the other projects were inventoried but not treated as V4 implementation. V4 contains Laravel source in `backend-overlay/`, an assembled Laravel tree in `backend/`, eight feature migrations plus three Laravel base migrations, CRM/member Vue apps, Docker files, deploy examples, docs, and a Windows/Python sync agent. A spot comparison of five critical overlay/assembled files showed byte-for-byte equality. The overlay is copied by `scripts/prepare-backend.sh` and Docker builds `backend/`.

The files named `sql.csv` and `รายงาน.csv` exist at the workspace root, not under the V4 project. V4 instead has `docs/SQL_QUERY_CATALOG.md` and SQL query files under `sync-agent/queries/`. No Modern UI mockup asset was found in the V4 inventory.

## Verification summary

| Area | Status | Evidence / limitation |
|---|---|---|
| Source and file inventory | VERIFIED PASS (static) | V4 paths listed above; separate workspace projects noted. |
| Docker Compose syntax | VERIFIED PASS | `docker compose config --quiet` exited 0. This validates config interpolation/syntax only. |
| Docker runtime/build | VERIFIED PASS (local) | CRM and app images built; local app/CRM containers started and PostgreSQL reported healthy. No volumes were removed. |
| PHPUnit/API tests | PASS (previous run), latest rerun blocked | 14 tests/181 assertions passed previously against SQLite `:memory:`. Latest host rerun could not open `backend/vendor/autoload.php`; no assertions ran in that attempt. |
| Python queue tests | NOT TESTED | Python launcher failed to execute (`python.exe` inaccessible). |
| Frontend build | VERIFIED PASS (CRM) | Docker Node 22 built the CRM bundle successfully. Member frontend build remains unverified. |
| Static analysis / PHP syntax | NOT TESTED | PHP unavailable. |
| Migration execution/status and table count | NOT TESTED | No database connection or migration command used. Eleven migration files are present (8 feature + 3 framework); this is not proof of 11 executed. |
| ERP TCP/SQL connectivity and mapping | BLOCKED | No ERP query was run. No production SQL account was used; live schema/results require ERP/DBA validation. |
| LINE live API | NOT TESTED | Signature/account-link code reviewed; external API not called. |
| UI responsive review | PARTIAL | Updated CRM desktop dashboard and report view were inspected in Chrome. Tablet/mobile rendering and full workflow acceptance remain unverified. |

## Feature summary

- **CRM/field sales:** lead create/list/update/review, duplicate checks, visits, GPS coordinates, photo storage/download, tasks, pins, plans/stops, and reports are represented in API/backend and CRM UI. Some business requirements remain partial: no ERP customer creation, full KPI/report export suite, offline/retry workflow, or full authorization/audit policy. A route/component existing is not treated as proof of end-to-end completion.
- **Member/loyalty:** separate staff/member session guards, member-scoped invoices/points/redemptions, ERP-link verification flow, ledger, rewards, redemption, reversal, and LINE link token flow are implemented. Live identity proof, concurrency assurance, and production rule approval remain outstanding.
- **LINE:** webhook HMAC and duplicate-event handling are implemented; outbound Reply API is optional/config dependent; LINE Login/OIDC is not implemented. No live request was made.
- **ERP:** SQL bridge is localhost-bound and query files are SELECT-oriented; bridge has a query keyword guard and SQL Server/ODBC connection options. Actual driver/auth/read-only permissions, schema correctness, mapping, and sync completeness remain unverified.
- **UI:** CRM and member screens call backend endpoints. CRM now exposes reconciliation and visit CSV exports plus print views that the browser can save as PDF. Native XLSX/PPTX generation, charts, mobile acceptance, and full map UX remain partial.

## Related audit documents

- [Security audit](CODEX_SECURITY_AUDIT.md)
- [ERP integration review](CODEX_ERP_INTEGRATION.md)
- [UI review](CODEX_UI_REVIEW.md)
- [Test results](CODEX_TEST_RESULTS.md)
- [Fix priority](CODEX_FIX_PRIORITY.md)

## Production gate

Do not use this POC as production-ready until DBA confirms live Bplus schema and SELECT-only grants, invoice/master sync completeness and production reconciliation are validated, PostgreSQL migration/backup/restore are checked in staging, remaining security and workflow gaps are closed, and end-to-end CRM/member/LINE/ERP acceptance passes with approved credentials. The local build and browser review are not production acceptance.
