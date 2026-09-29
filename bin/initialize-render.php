<?php
declare(strict_types=1);
// Explicit opt-in initialization for a NEW Neon database. Never resets existing data.
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\DB;
use App\Services\Schools;
if(getenv('SCHOOLLEDGER_BOOTSTRAP')!=='1') exit(0);
if(!DB::postgres()) throw new RuntimeException('Render initialization requires PostgreSQL.');
DB::transaction(function () {
    DB::run('SELECT pg_advisory_xact_lock(736461901)');
    if(DB::tables()) {
        if(!DB::one('SELECT id FROM users WHERE is_super=1 LIMIT 1')) throw new RuntimeException('Existing database requires manual review; no data was changed.');
        echo "Existing SFMS database preserved.\n";
        return;
    }
    $email=getenv('SCHOOLLEDGER_ADMIN_EMAIL')?:'';
    $password=getenv('SCHOOLLEDGER_ADMIN_PASSWORD')?:'';
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) throw new RuntimeException('Set administrator email and a password of at least 12 characters for first initialization.');
    foreach(explode(';',file_get_contents(DB::schemaFile())) as $sql) if(trim($sql)) DB::connection()->exec($sql);
    foreach(Schools::PERMISSIONS as $code) DB::insert('permissions',['code'=>$code]);
    DB::insert('users',['name'=>'Platform Administrator','email'=>strtolower($email),'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'is_super'=>1]);
    echo "New SFMS database initialized.\n";
});
