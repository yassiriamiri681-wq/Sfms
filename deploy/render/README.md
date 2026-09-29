# SFMS on Render (free trial deployment)

Use a separate Docker web service for this repository. Do not change the Joyas service.
Select the Free instance. Leave Docker Command empty; Dockerfile supplies it.
The public document root is public/. TLS terminates at Render; this Apache configuration
is only for that trusted proxy deployment, not a directly exposed server.

## Neon PostgreSQL (requested deployment)

Create a separate Neon Free project for SFMS. Do not reuse or edit the Joyas database.
Do not add a payment card or upgrade a plan. Use the direct connection for installation
and initial validation. Set these variables on the SFMS Docker service:

* SCHOOLLEDGER_DSN: pgsql:host=YOUR-NEON-HOST;port=5432;dbname=sfms
* SCHOOLLEDGER_DB_USER: the database user
* SCHOOLLEDGER_DB_PASSWORD: the database password (secret)

The container enforces sslmode=verify-full using the system certificate bundle.
Never commit credentials. PostgreSQL support must pass the integration, HTTP, security,
concurrency and recovery checks against isolated test databases before publication.
Initialize a new empty database once using bin/install.php from a trusted local CLI
configured for that external database, with administrator credentials supplied privately.
Alternatively, Free Render services can set SCHOOLLEDGER_BOOTSTRAP=1 plus
SCHOOLLEDGER_ADMIN_EMAIL and SCHOOLLEDGER_ADMIN_PASSWORD (12+ characters).
The entrypoint initializes only an empty PostgreSQL database inside one transaction
and an advisory lock. Subsequent starts preserve all existing accounts and records.
After successful first deployment, remove the administrator password environment variable.
This flag never imports local data or resets an existing database.

## Durable images and backups

The Docker image sets SCHOOLLEDGER_IMAGE_STORAGE=database. Private PNG images are
stored in the external database and served through existing authorization checks.
The PostgreSQL schema includes stored_images. For MySQL databases, apply
database/image-storage.sql before enabling this mode. Existing local image files
are not automatically migrated. Each processed image is limited to 4 MB; use small photos
to stay within the provider's current free storage allowance.
Render Free disks are ephemeral. Download backups to a separate device;
do not rely on copies inside the web container. Sessions may expire after a restart.

This deployment has not yet been built on Render or connected to an external database.
Never upload config/local.php, storage/, uploads/, or tests/.runtime* to GitHub.

The existing local MySQL installation remains supported. This change installs a new
PostgreSQL database; it does not automatically migrate existing local financial records.
