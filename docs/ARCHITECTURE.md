# SchoolLedger architecture

## Scope and delivery order

SchoolLedger is a server-rendered PHP 8.3+ / MySQL 8.0+ product. Its public document root is `public/`; credentials, uploaded files, logs, and database backups are outside that root. The first release implements manual fee administration; external messaging, gateway settlement, and subscription charging are extension points, not advertised as working integrations.

Implementation proceeds through identity and tenancy, academics and students, configurable fees and invoices, payments and statements, reporting and audit, then operational backup and integration interfaces.

## Table relationships (designed before implementation)

* A school owns users, roles, student types, academic years, classes, fee types, students, and financial documents. A global super administrator has no school. Tenant roles own permission assignments.
* Academic years own terms; classes own streams. A student's immutable academic-history rows reference year, class, stream, and student type. Promotion appends a row and preserves old invoice snapshots.
* Hostels own rooms and rooms own beds. A boarding assignment references one student and one bed. Day Scholars require no boarding assignment.
* Fee structures reference year, term, class, and student type. Their items reference configurable fee types. One structure exists per exact combination. No implicit fallback crosses categories.
* Invoices reference student and academic history. Invoice items snapshot descriptions and integer minor-unit amounts. Subsequent signed adjustments are separate records. Cancellation preserves the original document.
* Payments reference a student and have allocations to invoices. Each payment has one receipt. Receipt and invoice numbers derive from database IDs, avoiding race-prone counters. A request key prevents duplicate payment posting.
* Derived invoice balance = invoice items + signed adjustments − posted allocations. No editable balance exists. Overpayments and credits exceeding the unpaid amount are rejected in this release.
* Audit logs record the actor, school, action, affected record, timestamp, and IP. Application users cannot edit them. Outbox events are created in the financial transaction for later integration delivery.
* School subscriptions and settings retain future feature and plan metadata, without claiming to enforce paid licensing.

## Isolation and authorization

All tenant queries require an authenticated school context. Super administrators explicitly select a school before using tenant modules. Every write checks a database-backed permission, a CSRF token, and record ownership. Tenant-scoped foreign keys include `(school_id, id)` so references cannot cross schools even if a programming error bypasses an application check. Student users are additionally constrained to their linked student; teachers have directory-only access by default. Receptionists register students but cannot post financial records.

Authentication uses PHP password hashing, regenerated session IDs, secure/HttpOnly/SameSite cookies, inactivity expiry, generic login errors, and persistent rate limiting. Production requires HTTPS. Output is escaped and SQL values use PDO bindings. Uploads accept validated PNG/JPEG images only and are served through an authenticated route.

## Financial concurrency

Invoice generation snapshots the matching student's current academic enrollment and fee structure inside a transaction. A unique student/term constraint prevents duplicate routine billing, including after a mid-term enrollment change. Payment and adjustment posting lock the invoice before recomputing its balance. Payments and audit/outbox writes commit together. Historical payments cannot be edited or deleted; corrections use a recorded reversal with a mandatory reason. Reversals lock the same invoice and preserve the receipt history. Runtime transactions use READ COMMITTED so a waiter reads committed balances after acquiring the lock; tenant backups explicitly use a REPEATABLE READ snapshot.

Money uses integer minor units at the application boundary and BIGINT in MySQL. Decimal input is parsed as text, never multiplied as a binary floating-point number. Currency is school-specific and cannot change after financial posting. Dates are validated; billing stores due dates, payment dates, and timestamps separately.

## Reviewed batch operations

`Batches` reuses the existing financial and enrollment services. A preview contains a school/user-bound selection, its calculated amounts, a SHA-256 fingerprint of reviewed rows, and an expiry timestamp. The web controller stores this state in the server session; submitted amounts and student lists are never trusted. Commit revalidates permission and ownership, locks the school and selected students, locks matching fee structures for billing, then recomputes the preview. A mismatch fails before posting. All writes occur in one outer transaction; nested service calls use savepoints without committing the parent transaction. Successful tokens are removed from the session, and the invoice uniqueness constraint prevents repeat billing even across concurrent sessions. Batches are limited to 200 students for bounded synchronous processing.

## Application boundaries

`app/Core`: database, session, validation, tenancy, permissions. `app/Services`: financial transactions and student enrollment. `app/Controllers`: HTTP actions and reporting. `views`: escaped presentation. `database`: versioned schema and bootstrap permissions. `bin`: installation, backup, restore guidance. `tests`: monetary rules and database integration security/integrity checks.

## Operational requirements

Use a least-privilege database account, HTTPS, private storage permissions, encrypted off-machine backups with retention, and periodic restore drills. Deployment must run the automated tests against a dedicated test database, review access permissions, and load-test expected school sizes. Local implementation alone is not evidence of production certification.
