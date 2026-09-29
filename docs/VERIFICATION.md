# Verification performed — 27 September 2026

Environment: Windows, Laragon PHP 8.3.30, MySQL 8.4.3. All test schools, students and payments were synthetic and stored in dedicated `_test` databases, separate from the clean local installation.

## Automated results

* **18 money assertions passed:** exact decimal parsing, positive/signed amounts, maximum supported amounts, negative minor-unit display, malformed amounts and precision rejection.
* **58 MySQL integration assertions passed:** two-school isolation, composite tenant foreign keys, role authorization, initial categories, registration rollback, enrollment constraints, fee matching, immutable charge snapshots, duplicate invoices, payment idempotency, overpayment prevention, unique references, discounts, reversals, receipts, statement reconciliation, bed occupancy, promotion history, scoped backup, transactional outbox and persistent login throttling.
* **87 HTTP assertions passed:** sign-in, CSRF rejection, authenticated pages, all report routes, CSV output, invalid record handling, SQL-like search input, unknown routes, teacher restrictions, student self-access, configuration edit views and global administration.
* **8 additional security assertions passed:** active session invalidation after password reset, executable disguised as PNG rejected, valid PNG accepted and re-encoded, random upload filenames, authenticated image serving, cross-school image denial, and stored HTML escaped.
* **Concurrent process test passed:** two independent PHP processes attempted payments of TZS 1,000,000 against a TZS 1,500,000 invoice. One posted and one was rejected; the resulting balance was TZS 500,000.
* **Recovery drill passed:** tenant backup restored into a fresh schema; exactly one school restored, historical invoice balance reconciled, and foreign platform actors remained disabled archival identities.
* All application PHP files passed syntax validation.

Total: **171 automated assertions**, plus the concurrent-process test, the recovery drill and syntax validation.

## Browser inspection

The login, school selection and populated dashboard were inspected in the Codex browser. Desktop and phone viewport checks showed no horizontal document overflow. The mobile dashboard stacks its content and exposes collapsible navigation. The interface uses only local CSS/JavaScript; no third-party fonts or asset requests are required.

## Continuation: reviewed class workflows

After adding class billing and promotion, the 58 existing database checks and 87 HTTP checks passed again against `schoolledger_batches_20260927_test`, served separately on port 8081. The user's port 8080 configuration was not changed.

* **25 batch service checks passed:** read-only previews, student-type-specific totals, skipped invoices, missing fee blockers, school ownership, expiry, changed fee rejection, whole-batch rollback including audit/outbox records, successful posting, replay protection, preserved academic/financial history and teacher authorization denial.
* **12 batch HTTP checks passed:** both forms, stored preview redirects, reviewed totals, CSRF enforcement, ignoring forged posted amounts, exact persisted amounts, consumed-token rejection and unauthorized GET/POST denial.

Run `tests/batches.php` against a fresh `_test` database; it installs the existing integration fixture first. Then start a separate PHP development server with `SCHOOLLEDGER_CONFIG` pointing to a private configuration for that same test database, set `SCHOOLLEDGER_TEST_URL`, and run `tests/batch-http.php`. The environment override is supported only by CLI and PHP's development server, not production web SAPIs. The HTTP batch test seeds a dedicated term and should be run once per fixture.

## Limits

## Navigation continuation

75 navigation checks passed, alongside the existing 87 HTTP checks, against the isolated test server. Each of the 15 school destinations preserves its intended route through school selection, then renders the expected page. Tests cover sidebar child links, parent menu selection, and rejection of external redirect targets. Menus for fee types, class invoicing and class promotion are now directly available in the sidebar. Platform settings and platform audit also display their selected state.

## Deployment limits

This is targeted development verification, not a penetration test, a broad device lab, a load test or production certification. Print layouts are implemented; physical printer/PDF-driver output varies and should be accepted by the deploying school. The notification and payment-provider interfaces were not connected to external services. Full disaster recovery scheduling and encrypted offsite storage remain deployment responsibilities.

## Running again

Use a new empty `_test` database for each integration run; the suite deliberately refuses to delete or reset existing data. The integration test writes a private fixture descriptor in `tests/.runtime-fixture.json` (ignored by Git). Point a separate local HTTP instance at that same test database for `http.php` and `security.php`. Run `concurrency.php` once after integration. Provide another fresh `_test` database through `SCHOOLLEDGER_RECOVERY_DSN` for `recovery.php`. Tests require a database user able to install schemas; production runtime users should not have those privileges.
