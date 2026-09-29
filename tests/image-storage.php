<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\DB;
use App\Services\ImageStorage;
$fixture=json_decode(file_get_contents(ROOT.'/tests/.runtime-fixture.json'),true);
if(!preg_match('/dbname=[a-zA-Z0-9_]+_test;/', $fixture['dsn'])) throw new RuntimeException('Test database required.');
DB::configure(['dsn'=>$fixture['dsn'],'user'=>getenv('SCHOOLLEDGER_TEST_USER')?:'root','password'=>getenv('SCHOOLLEDGER_TEST_PASSWORD')?:'']);
DB::connection()->exec(file_get_contents(ROOT.'/database/image-storage.sql'));
putenv('SCHOOLLEDGER_IMAGE_STORAGE=database');
$sid=(int)$fixture['school']; $name=bin2hex(random_bytes(20)).'.png';
$bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
$count=0;
function checkImage(bool $ok): void { global $count; if(!$ok) throw new RuntimeException('Image storage check failed.'); $count++; }
try {
    ImageStorage::put($sid,$name,$bytes);
    checkImage(ImageStorage::get($sid,$name)===$bytes);
    checkImage(ImageStorage::get($sid+100000,$name)===null);
    checkImage(!is_file(ROOT.'/uploads/'.$name));
    checkImage(ImageStorage::get($sid,'../config/local.php')===null);
    $rolled=bin2hex(random_bytes(20)).'.png';
    try { DB::transaction(function () use($sid,$rolled,$bytes) { ImageStorage::put($sid,$rolled,$bytes); throw new DomainException('rollback'); }); } catch(DomainException) {}
    checkImage(ImageStorage::get($sid,$rolled)===null);
    try { ImageStorage::put($sid,bin2hex(random_bytes(20)).'.png',str_repeat('x',4*1024*1024+1)); throw new RuntimeException('Oversize accepted'); } catch(DomainException) { $count++; }
    ImageStorage::remove($sid,$name); checkImage(ImageStorage::get($sid,$name)===null);
} finally { ImageStorage::remove($sid,$name); }
echo "PASS: $count image storage checks (round trip, tenant isolation, no local file, traversal, rollback, size, removal)\n";
