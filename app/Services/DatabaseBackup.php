<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
use Throwable;
final class DatabaseBackup {
public static function create(array $config): void {
$directory=ROOT.'/storage/backups';
if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('Cannot create backup directory.');
$lock=fopen($directory.'/daily.lock','c');
if (!$lock) throw new RuntimeException('Cannot open database backup lock.');
if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); return; }
$options=null; $temporary=null;
try {
    $postgres=str_starts_with($config['dsn'],'pgsql:');
    if (!$postgres && !str_starts_with($config['dsn'],'mysql:')) throw new RuntimeException('Unsupported backup database driver.');
    $parts=[];
    foreach(explode(';',substr($config['dsn'],6)) as $part) {
        if(str_contains($part,'=')) { [$key,$value]=explode('=',$part,2); $parts[$key]=$value; }
    }
    $database=$parts['dbname']??'';
    if(!preg_match('/^[a-zA-Z0-9_]+$/',$database)) throw new RuntimeException('Unsupported database name.');
    $binary=getenv('SCHOOLLEDGER_MYSQLDUMP') ?: ($config['mysqldump_path']??'mysqldump');
    $options=tempnam($directory,'credentials-');
    if($options===false) throw new RuntimeException('Cannot create private options file.');
    $quote=static fn(string $s): string => '"'.str_replace(["\\","\"","\n","\r"],["\\\\","\\\"","\\n","\\r"],$s).'"';
    $settings="[client]\n";
    foreach(['host'=>$parts['host']??'127.0.0.1','port'=>$parts['port']??'3306','user'=>$config['user'],'password'=>$config['password']] as $key=>$value) $settings.=$key.'='.$quote((string)$value)."\n";
    if(file_put_contents($options,$settings)===false) throw new RuntimeException('Cannot write options file.');
    chmod($options,0600);
    $temporary=$directory.'/database-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
    $command=[$binary,'--defaults-extra-file='.$options,'--single-transaction','--quick','--routines','--triggers','--events','--no-tablespaces','--set-gtid-purged=OFF','--hex-blob','--result-file='.$temporary,$database];
    $environment=null;
    if($postgres) {
        $escape=static fn(string $s): string => str_replace(['\\',':'],['\\\\','\\:'],$s);
        $values=[$parts['host']??'localhost',$parts['port']??'5432',$database,$config['user'],$config['password']];
        if(file_put_contents($options,implode(':',array_map($escape,$values))."\n")===false) throw new RuntimeException('Cannot write PostgreSQL credentials file.');
        $environment=getenv();
        unset($environment['PGPASSWORD'],$environment['PGSERVICE'],$environment['PGSERVICEFILE']);
        $environment['PGPASSFILE']=$options;
        if(isset($parts['sslmode'])) $environment['PGSSLMODE']=$parts['sslmode'];
        if(isset($parts['sslrootcert'])) $environment['PGSSLROOTCERT']=$parts['sslrootcert'];
        $command=[$config['pg_dump_path']??'pg_dump','--no-password','--host='.$values[0],'--port='.$values[1],'--username='.$config['user'],'--dbname='.$database,'--format=plain','--no-owner','--no-privileges','--file='.$temporary];
    } elseif(!empty($config['ssl_ca'])) {
        $command[]='--ssl-mode=VERIFY_IDENTITY';
        $command[]='--ssl-ca='.$config['ssl_ca'];
    }
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
    if(!is_resource($process)) throw new RuntimeException('Cannot start database dump utility.');
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
    if(proc_close($process)!==0) throw new RuntimeException('Database dump failed: '.$error);
    if(!is_file($temporary) || filesize($temporary)===0) throw new RuntimeException('Empty backup refused.');
    $input=fopen($temporary,'rb'); $output=gzopen($temporary.'.gz.partial','wb9');
    if(!$input || !$output) throw new RuntimeException('Cannot compress backup.');
    while(!feof($input)) {
        $bytes=fread($input,1048576);
        if($bytes===false || gzwrite($output,$bytes)!==strlen($bytes)) throw new RuntimeException('Backup compression failed.');
    }
    fclose($input); gzclose($output);
    if(!rename($temporary.'.gz.partial',$temporary.'.gz')) throw new RuntimeException('Cannot finalize backup.');
    file_put_contents($directory.'/last-success.json',json_encode(['completed_at'=>date(DATE_ATOM),'file'=>basename($temporary).'.gz'],JSON_THROW_ON_ERROR),LOCK_EX);

} catch(Throwable $error) {
    file_put_contents($directory.'/errors.log',date(DATE_ATOM).' '.$error->getMessage()."\n",FILE_APPEND|LOCK_EX);
    throw $error;
} finally {
    if($options && is_file($options)) unlink($options);
    if($temporary && is_file($temporary)) unlink($temporary);
    if($temporary && is_file($temporary.'.gz.partial')) unlink($temporary.'.gz.partial');
    flock($lock,LOCK_UN); fclose($lock);
}


}
}
