<?php
declare(strict_types=1);
namespace App\Services;
final class DailyBackup {
    public static function status(): ?array {
        $file=ROOT.'/storage/backups/last-success.json';
        return is_file($file)?json_decode((string)file_get_contents($file),true):null;
    }
    public static function run(array $config): void {
        if (($config['automatic_database_backup']??true)!==true) return;
        $directory=ROOT.'/storage/backups';
        try {
            if(!is_dir($directory) && !mkdir($directory,0700,true)) throw new \RuntimeException('Cannot create backup directory.');
            $lock=fopen($directory.'/automatic.lock','c');
            if(!$lock) throw new \RuntimeException('Cannot open automatic backup lock.');
            if(!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); return; }
            try {
                $status=self::status();
                if(substr($status['completed_at']??'',0,10)===date('Y-m-d')) return;
                $attempt=$directory.'/last-attempt';
                if(is_file($attempt) && (int)file_get_contents($attempt)>time()-900) return;
                file_put_contents($attempt,(string)time(),LOCK_EX);
                DatabaseBackup::create($config);
            } finally { flock($lock,LOCK_UN); fclose($lock); }
        } catch(\Throwable $error) {
            // A backup failure must not interrupt school operations or expose credentials.
            error_log('Automatic database backup failed; administrator should check storage/backups/errors.log.');
        }
    }
}
