<?php
declare(strict_types=1);
/** Restore into an empty, schema-installed recovery database. Never overlays live records. */
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\DB;
use App\Services\Backup;
$file=$argv[1]??'';
if(!is_file($file)) { fwrite(STDERR,"Usage: php bin/restore-tenant.php /private/path/school-backup.json.gz\nUse a separate empty recovery database configured in config/local.php.\n"); exit(1); }
try {
    if(DB::one('SELECT id FROM schools LIMIT 1') || DB::one('SELECT id FROM users LIMIT 1')) throw new RuntimeException('Recovery database must have schema only, with no users or schools.');
    $raw=gzdecode(file_get_contents($file)); if($raw===false) throw new RuntimeException('Invalid gzip file.');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR); if(($data['format']??'')!=='schoolledger-tenant-v1') throw new RuntimeException('Unsupported backup format.');
    $sid=(int)$data['school']['id'];
    DB::transaction(function () use($data,$sid) {
        foreach($data['permissions'] as $p) DB::insert('permissions',$p);
        DB::insert('schools',$data['school']);
        // Global actors are represented by disabled, non-login archival identities.
        $tenantUsers=array_column($data['tables']['users'],'id'); $actors=[];
        foreach(['invoices'=>'created_by','adjustments'=>'created_by','payments'=>'received_by','audit_logs'=>'user_id'] as $table=>$column) foreach($data['tables'][$table] as $r) if($r[$column] && !in_array($r[$column],$tenantUsers)) $actors[$r[$column]]=true;
        foreach($data['tables']['payments'] as $r) if($r['reversed_by'] && !in_array($r['reversed_by'],$tenantUsers)) $actors[$r['reversed_by']]=true;
        foreach(array_keys($actors) as $id) DB::insert('users',['id'=>$id,'name'=>'Archived platform actor #'.$id,'email'=>'archived-'.$id.'@invalid.local','password_hash'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'is_super'=>1,'active'=>0]);
        foreach(Backup::TABLES as $table) foreach($data['tables'][$table] as $row) { if((int)$row['school_id']!==$sid) throw new RuntimeException('Backup contains a foreign school record.'); DB::insert($table,$row); }
        foreach($data['role_permissions'] as $r) DB::insert('role_permissions',$r);
        foreach($data['images'] as $name=>$image) { if(!preg_match('/^[a-f0-9]{40}\.png$/',$name)) throw new RuntimeException('Invalid image filename.'); $bytes=base64_decode($image,true); if($bytes===false) throw new RuntimeException('Image restore failed.'); \App\Services\ImageStorage::put($sid,$name,$bytes); }
    });
    echo "Tenant restored to the recovery database. Validate statements and receipts before any migration to production.\n";
} catch(Throwable $e) { fwrite(STDERR,"Restore failed: ".$e->getMessage()."\n"); exit(1); }
