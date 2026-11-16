# QA and Safety Checklist

- [ ] No inbound SQL 1433 opened to Internet; home VPN only; SQL login SELECT-only on approved views
- [ ] Sync secrets never committed; HTTPS valid cert, token rotated, batch UUID stable on retries
- [ ] Separate Staff vs Member guards; test member cannot access CRM and staff cannot access member personal data
- [ ] Unassigned Sales cannot see other Sales customer pins/leads/photos
- [ ] Lead can be visited without ERP customer ID; exactly one customer target for every visit
- [ ] GPS pin survives subsequent visits; check-in geo stored as historical record
- [ ] Browser GPS denied? manual map pin still works; location_accuracy recorded; no GPS spoofing claim
- [ ] Photos are not directly public; access checked before download; rate limit + file size restriction
- [ ] Upload EXIF/geotags and malware mitigation policy implemented before production
- [ ] Sales totals reconcile for posted, cancelled, refund/return, discount, tax including doc types
- [ ] Member purchase history is empty before verified ERP account linking
- [ ] Points ledger balances, replay idempotency, returns reversal and expiry rules tested
- [ ] LINE webhook signature invalid => 401; private member data never returned without linked identity
- [ ] Host Nginx existing apps unaffected; Docker ports bind localhost only; PostgreSQL not exposed
- [ ] Database backup AND restore successfully tested; encrypted backup with retention; run recovery exercise
- [ ] PDPA lawful basis, role access, retention & member consent reviewed
- [ ] Pilot with 1 sales team, correct mappings and KPI before expand

## Static checks executed by build script
PHP lint (if available), Python syntax, YAML parse (if available), ZIP archive integrity. Docker services runtime not tested without Docker daemon.
