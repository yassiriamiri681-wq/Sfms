#!/bin/sh
set -eu
# Explicit first-use setup is atomic and never resets an existing database.
if [ "${SCHOOLLEDGER_BOOTSTRAP:-0}" = "1" ]; then
    php /var/www/html/bin/initialize-render.php
fi
unset SCHOOLLEDGER_ADMIN_PASSWORD
exec apache2-foreground
