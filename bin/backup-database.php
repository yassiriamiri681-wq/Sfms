<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
try {
    App\Services\DatabaseBackup::create($config);
    echo "Database backup completed.\n";
} catch (Throwable $error) {
    fwrite(STDERR,"Database backup failed; check storage/backups/errors.log.\n");
    exit(1);
}
