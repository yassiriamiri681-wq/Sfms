# SFMS on Render (free trial deployment)

Use a separate Docker web service for this repository. Do not change the Joyas service.
Select the Free instance. Leave Docker Command empty; Dockerfile supplies it.
The public document root is public/. TLS terminates at Render; this Apache configuration
is only for that trusted proxy deployment, not a directly exposed server.

## External MySQL

Create an Aiven MySQL **Free** service, not a paid trial. Do not add a payment card.
Upload its CA certificate as a Render secret file named mysql-ca.pem, and set:

* SCHOOLLEDGER_DSN: mysql:host=HOST;port=PORT;dbname=defaultdb;charset=utf8mb4
* SCHOOLLEDGER_DB_USER: the database user
* SCHOOLLEDGER_DB_PASSWORD: the database password (secret)
* SCHOOLLEDGER_DB_SSL_CA: /etc/secrets/mysql-ca.pem

The connection verifies the database certificate. Never commit credentials or CA private keys.
Initialize a new empty database once using bin/install.php from a trusted local CLI
configured for that external database, with administrator credentials supplied privately.
Do not run the installer on an existing database or on every deployment.

## Durable images and backups

The Docker image sets SCHOOLLEDGER_IMAGE_STORAGE=database. Private PNG images are
stored in the external MySQL database and served through existing authorization checks.
The installer creates stored_images. For an existing database or a recovery schema,
apply database/image-storage.sql before enabling this mode. Existing local image files
are not automatically migrated. Each processed image is limited to 4 MB; use small photos
to stay within the free database's 1 GB total storage limit.
Render Free disks are ephemeral. Download backups to a separate device;
do not rely on copies inside the web container. Sessions may expire after a restart.

This deployment has not yet been built on Render or connected to an external database.
Never upload config/local.php, storage/, uploads/, or tests/.runtime* to GitHub.
