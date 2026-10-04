<?php
declare(strict_types=1);
namespace App\Services {
    final class DatabaseBackup {
        public static int $calls=0;
        public static bool $fail=false;
        public static function create(array $config): void {
            self::$calls++;
            if(self::$fail) throw new \RuntimeException('Synthetic failure');
            file_put_contents(ROOT.'/storage/backups/last-success.json',json_encode(['completed_at'=>date(DATE_ATOM),'file'=>'fixture.sql.gz']));
        }
    }
}
namespace {
    define('ROOT',dirname(__DIR__).'/storage/daily-test-'.bin2hex(random_bytes(6)));
    date_default_timezone_set('Africa/Dar_es_Salaam');
    require dirname(__DIR__).'/app/Services/DailyBackup.php';
    $check=static function(bool $condition): void { if(!$condition) throw new \RuntimeException('Daily backup assertion failed.'); };
    try {
        \App\Services\DailyBackup::run(['automatic_database_backup'=>false]);
        $check(\App\Services\DatabaseBackup::$calls===0);
        \App\Services\DailyBackup::run([]);
        \App\Services\DailyBackup::run([]);
        $check(\App\Services\DatabaseBackup::$calls===1);
        file_put_contents(ROOT.'/storage/backups/last-success.json',json_encode(['completed_at'=>'2000-01-01T00:00:00+03:00']));
        file_put_contents(ROOT.'/storage/backups/last-attempt',(string)(time()-1000));
        \App\Services\DatabaseBackup::$fail=true;
        \App\Services\DailyBackup::run([]);
        \App\Services\DailyBackup::run([]);
        $check(\App\Services\DatabaseBackup::$calls===2);
        $check(substr(\App\Services\DailyBackup::status()['completed_at'],0,10)==='2000-01-01');
        file_put_contents(ROOT.'/storage/backups/last-attempt',(string)(time()-1000));
        \App\Services\DatabaseBackup::$fail=false;
        \App\Services\DailyBackup::run([]);
        $check(\App\Services\DatabaseBackup::$calls===3);
        echo "Daily backup checks passed: disabled, once daily, nonfatal failure, retry throttling, retry success.\n";
    } finally {
        foreach(glob(ROOT.'/storage/backups/*')?:[] as $file) unlink($file);
        if(is_dir(ROOT.'/storage/backups')) rmdir(ROOT.'/storage/backups');
        if(is_dir(ROOT.'/storage')) rmdir(ROOT.'/storage');
        if(is_dir(ROOT)) rmdir(ROOT);
    }
}
