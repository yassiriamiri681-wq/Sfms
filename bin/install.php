<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\DB;
use App\Services\Schools;
if(!is_file(ROOT.'/config/local.php')) { fwrite(STDERR,"Copy config/example.php to config/local.php and configure an EMPTY MySQL database first.\n"); exit(1); }
$email=getenv('SCHOOLLEDGER_ADMIN_EMAIL')?:''; $password=getenv('SCHOOLLEDGER_ADMIN_PASSWORD')?:'';
if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) { fwrite(STDERR,"Set SCHOOLLEDGER_ADMIN_EMAIL and SCHOOLLEDGER_ADMIN_PASSWORD (12+ characters). No default password is installed.\n"); exit(1); }
try {
    if(DB::all('SHOW TABLES')) throw new RuntimeException('Installation requires an empty database.');
    $sql=file_get_contents(ROOT.'/database/schema.sql');
    foreach(explode(';',$sql) as $statement) if(trim($statement)) DB::connection()->exec($statement);
    DB::connection()->exec(file_get_contents(ROOT.'/database/image-storage.sql'));
    DB::transaction(function () use($email,$password) {
        foreach(Schools::PERMISSIONS as $code) DB::insert('permissions',['code'=>$code]);
        DB::insert('users',['name'=>'Platform Administrator','email'=>strtolower($email),'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'is_super'=>1]);
    });
    echo "SchoolLedger installed. Sign in, create a school, then create its administrator.\n";
} catch(Throwable $e) { fwrite(STDERR,"Installation failed: ".$e->getMessage()."\n"); exit(1); }
