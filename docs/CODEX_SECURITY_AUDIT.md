# Security Audit — Popstar Business Platform V4

**Status:** PARTIALLY IMPLEMENTED; source fixes applied and regression suite passed. No penetration test or production service was contacted.

## Findings

### FIXED — Fixed development credential fallback for local bridge

- **File/lines:** `sync-agent/local_bridge.ps1:26-28`
- **Root cause:** A hardcoded development token was selected if environment/config values were absent. This fallback has been removed.
- **Impact:** Anyone who knows the source/default can authenticate to a running local bridge. The bridge is loopback-bound, which limits remote reachability in the normal setup, but it still exposes ERP preview/export data to local processes/users and creates a shared credential risk. If the same token is reused for cloud ingestion, the blast radius increases.
- **Fix applied:** Startup fails closed without an explicit 32+ character bridge token, example placeholders are rejected, the bridge/cloud tokens must differ, backend calls use the bridge-specific config, and the comparison uses a constant-work character loop compatible with Windows PowerShell 5.1. Local sync/bridge token values were rotated and not printed.
- **Retest:** Start bridge with all token sources absent and expect startup or authenticated routes to fail closed; verify wrong/default token gets 401 and a unique configured token works.

### FIXED IN SOURCE — Concurrent redemptions may overspend one member's points

- **File/lines:** `backend-overlay/app/Modules/Member/MemberController.php:273-308`
- **Root cause:** Transaction locks a reward row, but reads the member's ledger sum without serializing concurrent redemptions for that member. Two concurrent redemptions for different rewards can both observe the same balance.
- **Impact:** Ledger balance can become negative and reward points can be spent beyond earned balance.
- **Fix applied:** The redemption transaction locks the active member row before summing points and retains the reward row lock, serializing separate reward redemptions by the same member. Full PostgreSQL concurrency stress testing remains open.
- **Retest:** With a test database and one member whose balance covers only one reward, issue concurrent redemptions for two different rewards; exactly one must commit and balance must not go negative.

### MEDIUM — Local runtime config is unsafe for deployment

- **File/lines:** `backend/.env:4,20-25`; `.env:1-3`; `sync-agent/config.env:1-2`
- **Root cause:** Workspace-local configuration contains development-only database/app settings. Sync/bridge tokens have been rotated. Local `APP_DEBUG=true` and insecure cookies support local HTTP development; production env example sets debug off and secure cookies on. `.gitignore` excludes env files; Git is unavailable here, so tracked state could not be verified.
- **Impact:** If reused or committed, credentials could enable unauthorized database/API access; debug output and non-secure cookies increase exposure over HTTP.
- **Fix:** Production templates were checked for safe runtime settings, and sync/bridge tokens were rotated. Keep DB credentials out of source and set them from secret management; do not deploy local development env files.
- **Retest:** Inspect deployment environment without printing values; assert required secrets are unique/non-placeholder, debug false, secure cookies true, and repository scan finds no live credentials.

### FIXED — Weak bridge token comparison

- **File/lines:** `sync-agent/local_bridge.ps1:161-163`
- **Root cause:** Token was compared with ordinary PowerShell string inequality.
- **Impact:** Timing leakage is a limited local threat given loopback scope, but secure comparison is preferable for authentication material.
- **Fix applied:** Equal-length tokens are compared with an XOR accumulator, and missing/short/placeholders are rejected at startup.
- **Retest:** Unit-test missing, short, wrong, and correct values; verify no token is returned in responses/logs.

### MEDIUM — CRM roles are coarse and authorization coverage is not yet demonstrated

- **File/lines:** route group in `backend-overlay/routes/web.php:26-81`; role checks are distributed across `FieldController.php` and `CrmController.php`.
- **Root cause:** All authenticated staff share a broad route group; resource ownership checks are implemented in controller helpers, with manager/admin exceptions. There is no centralized permission matrix, and the feature tests were not executable here.
- **Impact:** Future endpoints can accidentally omit ownership/role checks; a static review cannot certify every object-level authorization path.
- **Fix:** Centralize policies and add negative tests for every resource mutation/read, especially lead/visit/photo/task/plan/customer/member verification and sync monitor routes.
- **Retest:** Run a role-by-resource matrix using sales rep A, rep B, manager, and admin; assert cross-owner reads/writes are denied and manager-only operations are denied to reps.

## Controls reviewed

- SQL parameters are generally bound by Laravel query builder; sync inputs have field validation. No specific SQL injection exploit was confirmed in inspected API code.
- LINE webhook uses HMAC SHA-256 and `hash_equals`; event IDs are persisted for replay deduplication (`LineWebhookController.php:12-55`). LINE Login is not present.
- Laravel CSRF exclusion is limited to machine sync and LINE webhook in `backend-overlay/bootstrap/app.php`; browser write routes remain in web middleware. Runtime behavior was not tested.
- Member queries inspected scope invoices, profile, points, and redemptions to the authenticated member ID. Full isolation assurance awaits executable tests.
- Photo uploads restrict image formats/size and store on private local disk. Virus scanning, image re-encoding/EXIF removal, retention policy, and deployment storage permissions are not evidenced.
- Docker Compose binds web ports to `127.0.0.1`; PostgreSQL has no published host port. Runtime firewall/network exposure is not tested.
- Audit tables exist, but complete immutable audit coverage for sensitive staff actions is not demonstrated.

## Severity status

No confirmed Critical finding in this static pass. High findings: known credential fallback, redemption race, unsafe local configuration reuse. Penetration testing was not performed.
