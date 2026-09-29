# Release scope

## Implemented

* Multiple schools, explicit super-administrator school selection, school activation and deactivation.
* Database-backed school roles and configurable permissions, custom roles, user creation/editing/deactivation, password changes, session invalidation after password changes.
* Platform settings and all-school audit view; isolated school settings and audit logs.
* Academic years, terms, classes, streams, configurable student categories, naming corrections, and current academic defaults.
* Student profile, guardian/contact details, photo, status, search, filters and pagination; immutable enrollment history and promotions.
* Configurable hostels/rooms/beds, assignment/release history and occupancy constraints; day scholars cannot receive a boarding assignment.
* Configurable fee types and fee structure items selected by school/year/term/class/student type. Fee amounts can be changed without source changes.
* Student invoice generation, immutable charge snapshots, unique student/term billing, discounts, credits, extra charges, penalties, signed adjustments and cancellation.
* Reviewed class billing and class promotion, with optional stream/type filters, up to 200 students per batch. Previews expire after 15 minutes, changed data invalidates a review, existing invoices are skipped, and the entire batch commits or rolls back together.
* Manual payments, exact minor-unit arithmetic, transactional allocations, unique references, idempotency, receipts and authorized reversals.
* Printable receipts, invoices, all-time student statements and financial reports; browser PDF and spreadsheet-compatible CSV.
* Dashboard with category counts, collections chart, billed/collected/outstanding totals, paid/partial/overdue counts.
* Invoice, outstanding, paid, partial, daily, monthly, term, year, method, class, student-type, receipt, discount and adjustment reports.
* Private tenant backups, uploaded-image inclusion and CLI recovery to a fresh database.

## Deliberate v1 boundaries

* Each manual payment is allocated to one invoice. Multi-invoice remittance allocation and unapplied credit/refund workflows are not implemented.
* Student bulk import is not included. Billing and promotion batches are limited to 200 students; narrow larger classes by stream or student type.
* Financial exports are CSV and print-to-PDF, not native XLSX or a server-side PDF engine.
* A student's statement is all-time with an initial zero balance. Opening liabilities must be entered as auditable invoice charges. Date-range carry-forward statements are not included.
* Academic configuration names are editable; relationships, dates and boarding flags are intentionally immutable after creation. New configurations preserve historical meaning.
* The default deployment timezone is Africa/Dar_es_Salaam for the installation. Per-school timezone switching is not implemented.
* Notification provider contracts, transactional outbox, payment gateway contract and subscription metadata are prepared. Delivery workers, real gateway connections, subscription billing, license enforcement and plan quotas are future integrations.
* Tenant backup recovery is an administrator CLI operation against a fresh recovery database. Scheduling, encryption, retention and full database disaster recovery belong to deployment operations.

## Commercial acceptance still required

Independent security review; production TLS and reverse proxy setup; encrypted backup scheduling and offsite storage; school-specific role validation; volume and concurrency load testing; accessibility review with assistive technology; and operational monitoring. The included automated checks validate core behavior but do not certify production readiness.
