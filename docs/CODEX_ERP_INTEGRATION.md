# ERP Integration Review — Popstar Business Platform V4

**Status:** PARTIALLY IMPLEMENTED / BLOCKED for live validation. Invoice paging and complete line reconciliation were improved. No SQL Server connection or query was attempted.

## Architecture observed

Windows `sync-agent/local_bridge.ps1` listens on `127.0.0.1:18900`, receives authenticated requests, and runs named query files. The Laravel sync monitor calls it using `host.docker.internal:18900` by default (`backend-overlay/app/Modules/ERPIntegration/ErpLocalSyncController.php:20-29`). SQL Server uses `System.Data.SqlClient` or ODBC, and connection strings reject the `sa` login (`sync-agent/local_bridge.ps1:51-74`). SQL password fields are sanitized in selected error paths. Query files are reviewed as SELECT-shaped and the bridge rejects write keywords (`local_bridge.ps1:95-107`). This is source-level evidence only; SQL permissions remain unverified.

The HTTP bridge exposes `/health` without a token; health discloses whether SQL is configured, driver mode, and source name. Data endpoints require `X-Bridge-Token` (`local_bridge.ps1:145-177`). The `Host` header is explicitly set by Laravel, and Docker config maps `host.docker.internal`, but Windows/Docker reachability was not tested.

## Table/query mapping observed

| ERP source | Query file | Output use | Status |
|---|---|---|---|
| `ARFILE` plus address/contact/terms/salesperson joins | `bplus_customers.sql` | customer mirror | PARTIALLY IMPLEMENTED; live column semantics unverified |
| `SKUMASTER`, `UOFQTY`, `ICCAT` | `bplus_products.sql` | product mirror | PARTIALLY IMPLEMENTED; live schema unverified |
| `DOCINFO`, `DOCTYPE`, `ARDETAIL` or `AROE`, `TRANSTKH`, `BRANCH`, `SALESMAN` | `bplus_invoices.sql` | invoice header mirror | PARTIALLY IMPLEMENTED; mappings and doc classification need DBA confirmation |
| `TRANSTKD`, `SKUMASTER`, `ICCAT`, document tables | `bplus_sales_items.sql` | invoice line mirror | PARTIALLY IMPLEMENTED; referential joins and line completeness unverified |
| `SALESMAN`, `SLLINE`, `SLTEAM`, `SLAREA` | `bplus_salespersons.sql` | salesperson mirror | PARTIALLY IMPLEMENTED |
| `MEMBER`, `MBTYPE`, `MBPOINT` | `bplus_members.sql`, `bplus_member_points.sql` | member fields/points source | PARTIALLY IMPLEMENTED; points history query is not wired into active bridge sync endpoint |
| `ARCONTACT`, `CONTACT`, `ARADDRESS`, `ARCAT`, `CONTACTSTATUS` | `bplus_customer_contacts.sql` | preview only | PARTIALLY IMPLEMENTED; no mirror ingestion route shown |

No verified mapping to `SKUMOVE` or `ARDL` appears in the V4 query set. The invoice SQL mentions `ARDETAIL`/`AROE`; their exact semantic use against the production Bplus schema must be verified from the actual catalog/DBA, not inferred from names. The V4 model uses `erp_sales_invoices` and `erp_sales_items`; it does not implement the complete requested movement history/source catalog.

## Findings

### PARTIALLY FIXED — Invoice history paging and late edits

- **Files/lines:** `sync-agent/local_bridge.ps1:217-232`; `sync-agent/queries/bplus_invoices.sql:2,125`; `sync-agent/queries/bplus_sales_items.sql:2,62`
- **Root cause:** Exports previously fetched bounded newest rows with no persisted cursor. Invoice exports now filter by `DI_KEY > after_key`, order ascending, and store a per-source invoice cursor in PostgreSQL, with a 50-key overlap on subsequent syncs.
- **Impact:** Initial mirror can omit historical invoices; updated/cancelled older ERP documents may never reach the mirror. Separate invoice and item top limits can yield selected invoices with missing lines.
- **Fix applied:** Invoice pages use stable keys; every header fetches its own line set by document key, and incomplete/oversized line sets fail rather than silently commit. The cursor rereads the latest 50 keys. Master-data paging and a source-authoritative changed-at signal remain unresolved pending live schema confirmation.
- **Retest:** In isolated ERP test data, sync more rows and lines than a page, mutate/cancel an old document, run repeated/retried syncs, and compare source versus mirror counts and values.

### FIXED — Local mirror upsert does not remove stale invoice lines

- **File/lines:** `backend-overlay/app/Modules/ERPIntegration/ErpMirrorUpserter.php:85-140`
- **Root cause:** Invoice items were iterated and upserted without reconciling absent old lines. The upserter now deletes absent rows only when a complete snapshot flag is supplied.
- **Impact:** Mirror totals/details can retain stale lines and diverge from ERP.
- **Fix applied:** Bridge fetches a complete per-document line set, sets `items_complete=true`, and the mirror upserter reconciles that complete set transactionally. Partial payloads preserve existing lines.
- **Retest:** Re-sync invoice after removing/changing a line in a test source; assert mirror exactly matches source and retries remain idempotent.

### FIXED — Independent table batches can produce partial invoices

- **File/lines:** `sync-agent/local_bridge.ps1:217-232`
- **Root cause:** Headers and lines were independently limited and grouped in memory. The bridge now fetches lines with a parameterized document key for each invoice.
- **Impact:** Items for a selected invoice may fall outside the independently sorted/limited line result.
- **Fix applied:** Each selected header requests lines by its ERP document key and refuses snapshots above the current 200-line limit; SQL execution against the live ERP remains unverified.
- **Retest:** Use test fixtures with high-line-count and older invoices, comparing exact line key sets.

### MEDIUM — Customer/membership mapping and acquisition workflow stop short of ERP creation

- **Files/lines:** `backend-overlay/app/Modules/CRM/FieldController.php:624-652`; `sync-agent/queries/bplus_members.sql`; `backend-overlay/app/Modules/ERPIntegration/ErpMirrorUpserter.php:244-280`
- **Root cause:** Lead review can associate an already mirrored customer, and member mapping reads member records; no approved ERP-side customer creation or robust duplicate resolution contract exists.
- **Impact:** “Convert new lead to ERP customer” is a manual/external process; duplicate or unmatched records can remain unresolved.
- **Fix:** Keep ERP writes prohibited until owner/DBA explicitly approves a separately controlled workflow; define matching and human reconciliation rules, then verify with read-only source data first.
- **Retest:** Staging cases for duplicate phone/name/tax ID, unmatched ERP link, and already-linked member; confirm no ERP mutation occurs.

## Verification status

- Local bridge port/SQL driver/authentication/read-only DB grants: **NOT TESTED** (source review only).
- Live connectivity and actual customer/product/invoice results: **BLOCKED** (no approved ERP credentials used).
- PostgreSQL mirror schema and sync history tables: present in migrations, but migration execution/status is **NOT TESTED**.
- Incremental sync and source/mirror reconciliation: **NOT TESTED** and implementation appears incomplete.
- ERP write paths: no SQL writes were found in the bridge path reviewed; only local PostgreSQL ingestion writes exist. Keep ERP SQL account SELECT-only and verify its actual grants with DBA.
