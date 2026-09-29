# SchoolLedger

A PHP 8.3 school fee application supporting MySQL 8.0.16+ and PostgreSQL, with isolated school workspaces, database-backed permissions, category-specific billing, an immutable payment history, receipts, statements, reporting, and private tenant backups.

For Render Free with an external Neon PostgreSQL database, see [deployment instructions](deploy/render/README.md). PostgreSQL 18 is exercised in the automated workflow. Local MySQL installation continues to use the instructions below. Existing local data is not automatically transferred when creating a new Neon database.

Read [the architecture and relationships](docs/ARCHITECTURE.md) before extending modules. [The feature inventory](docs/FEATURES.md) distinguishes implemented workflows from future commercial integrations.

## Run locally

1. Enable PHP extensions `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, and `zlib`.
2. Create an **empty** MySQL database using `utf8mb4` and a database user. Copy `config/example.php` to `config/local.php`; set the connection details. Set `production` to `false` only for local HTTP development.
3. Set environment variables `SCHOOLLEDGER_ADMIN_EMAIL` and `SCHOOLLEDGER_ADMIN_PASSWORD` (12+ characters), then run `php bin/install.php`. Remove these environment variables afterward.
4. Run `php -S 127.0.0.1:8080 -t public public/router.php` and open http://127.0.0.1:8080.
5. Sign in with the administrator credentials you supplied. Create a school, then a school administrator in **Users & permissions**.

No sample school, financial transactions, or default passwords are inserted by the installer.

## First school setup

1. Save school identity, currency, document prefixes, and logo in Settings.
2. Add academic years, terms, classes, streams, and any additional student types in Academic setup. The two initial types are Day Scholar and Boarding Student.
3. Add hostels, rooms, and beds if the school offers boarding.
4. Register students with their current enrollment. Student promotions append history rows.
5. Add fee types, then create a fee structure for each year / term / class / student-type combination and enter the applicable charges.
6. Generate an invoice for a student and term. Missing or empty matching structures are rejected.
7. Open the invoice, record a payment, then print its receipt. Review the student's statement and financial reports.

## Class billing and promotion

From **Invoices → Generate class invoices**, select a source year/class, optional stream/type filters, billing term and dates. Review every student's category-specific charges before confirming. Existing invoices, including cancelled ones, are skipped. Missing fees block the batch until corrected. A review is valid for 15 minutes and is invalidated by changes to the selected enrollments or fees.

From **Students → Promote a class**, select the current enrollment and destination year/class/stream. Review and confirm the active students to move. Student types are retained; previous enrollment records and financial transactions remain intact. These changes apply immediately when confirmed; this is not a scheduled promotion job.

Each batch supports up to 200 students and uses one database transaction. A failure rolls back every invoice/enrollment change, audit entry and notification event belonging to that batch. Broader groups can be split by stream or type. Confirmation tokens are held in the server-side session and consumed after success.

## Financial rules

Money is stored in integer minor units (two decimal places). Invoice creation snapshots fee descriptions and amounts. Fee edits do not alter previously issued documents. Payments allocate to one invoice per entry; split payments can be entered as separate allocations with distinct references. Payment request keys prevent repeated form submission from double-posting. Overpayments are rejected. Credits/discounts cannot create a negative outstanding balance. Payment reversals preserve the original record and receipt. Cancelled invoices remain in history and cannot be regenerated for the same student/term; use adjustments for corrections.

Paid/partial/unpaid/overdue statuses derive from actual transactions and due dates. Cancelled invoices and reversed payments do not count in net collection totals. Receipts snapshot the invoice balance immediately after posting. Statements display all charges, payments, cancellations, adjustments, and reversals.

## Verification

```text
php tests/money.php
php tests/integration.php
php tests/http.php
php tests/security.php
php tests/concurrency.php
php tests/recovery.php
php tests/batches.php
php tests/batch-http.php
```

Integration tests require `SCHOOLLEDGER_TEST_DSN`, `SCHOOLLEDGER_TEST_USER`, and `SCHOOLLEDGER_TEST_PASSWORD`, pointing to a dedicated **empty database whose name ends in `_test`**. They never reset or drop existing databases. See `docs/VERIFICATION.md` for the checks actually executed in this workspace.

## Production deployment

* Serve **only `public/`** through Apache/Nginx + PHP-FPM. Never point a web root at the repository. The PHP development server is not a production server.
* Set `production=true`, terminate HTTPS at the PHP server, and configure the server's HTTPS flag correctly. The application refuses production HTTP; it does not blindly trust forwarded headers.
* Use a least-privilege database account. The installer needs DDL privileges; the runtime account does not. Deny UPDATE/DELETE on audit logs and INSERT/UPDATE/DELETE access to unrelated databases. Do not provide application users with database credentials.
* Grant PHP write access only to `storage/`, `uploads/`, and session storage. Keep `config/local.php`, logs, and backup files private. Encrypt backup media, rotate retention, and run restore drills.
* Set MySQL timezone to `+03:00` or run the database session in the configured timezone. Restrict database/network access, monitor error logs, rate-limit at the reverse proxy, configure request/file size limits, and monitor disk space.
* Review the role matrix with the school, test expected data volumes, and arrange operational monitoring and incident response before a commercial rollout.

## Backups and recovery

Authorized school users can create tenant JSON/gzip backups including uploaded images. To restore, create a **separate empty recovery database**, import `database/schema.sql` only (do not run the installer), point a separate recovery checkout's `config/local.php` at it, then run `php bin/restore-tenant.php path/to/backup.json.gz`. It refuses populated targets. Global actor references are restored as disabled archival identities, without exposing another school's users. Verify financial balances and access before migration. Files are restored outside the web root. Never upload a backup from an untrusted source.

For complete disaster recovery, the deployment administrator must also schedule encrypted full MySQL backups (for example, `mysqldump --single-transaction --routines --triggers` using a protected option file) plus configuration and uploads. The web application does not execute shell commands or store database passwords in command arguments.

## Integration boundaries and remaining commercial work

`app/Integrations/PaymentProvider.php` and `NotificationChannel.php` define extension contracts. Invoice/payment events are committed to `notification_outbox`; no SMS, email, WhatsApp provider, gateway callback endpoint, or delivery worker is enabled. Subscription metadata is present, but charging, licensing enforcement, feature quotas, and expiry enforcement are not implemented. These are future phase 7 work, not live product features.

This release uses manually recorded payments, one invoice per payment entry, browser print-to-PDF and CSV exports (Excel-compatible), and locally managed accounts. Production rollout still requires independent security review, scale testing, provider integrations where needed, and operational acceptance. No claim of production certification is made.
