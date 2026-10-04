<?php
declare(strict_types=1);
// Run only against the synthetic PostgreSQL fixture in the container CI job.
require dirname(__DIR__).'/app/bootstrap.php';
$dsn=getenv('SCHOOLLEDGER_TEST_DSN')?:'';
if(!preg_match('/dbname=([a-zA-Z0-9_]+_test)(?:;|$)/',$dsn,$match) || !str_starts_with($dsn,'pgsql:')) throw new RuntimeException('Dedicated PostgreSQL _test database required.');
$backupConfig=['dsn'=>$dsn,'user'=>getenv('SCHOOLLEDGER_TEST_USER'),'password'=>getenv('SCHOOLLEDGER_TEST_PASSWORD'),'pg_dump_path'=>'/usr/lib/postgresql/18/bin/pg_dump'];
App\Services\DatabaseBackup::create($backupConfig);
$status=App\Services\DailyBackup::status();
$sql=gzdecode(file_get_contents(ROOT.'/storage/backups/'.$status['file']));
if($sql===false || !str_contains($sql,'CREATE TABLE public.backup_fixture') || !str_contains($sql,'PostgreSQL database dump complete')) throw new RuntimeException('Incomplete PostgreSQL dump.');
// A second dump verifies that the first call releases its lock.
App\Services\DatabaseBackup::create($backupConfig);
$parts=[]; foreach(explode(';',substr($dsn,6)) as $part) if(str_contains($part,'=')) { [$key,$value]=explode('=',$part,2); $parts[$key]=$value; }
$env=getenv(); $env['PGPASSWORD']=$backupConfig['password'];
$command=['/usr/lib/postgresql/18/bin/psql','--no-password','--host='.$parts['host'],'--port='.$parts['port'],'--username='.$backupConfig['user'],'--dbname=sfms_backup_recovery_test','--set=ON_ERROR_STOP=1'];
$process=proc_open($command,[0=>['pipe','r'],1=>['file',ROOT.'/storage/backup-restore.log','a'],2=>['file',ROOT.'/storage/backup-restore.log','a']],$pipes,null,$env);
if(!is_resource($process)) throw new RuntimeException('Cannot start test restore.');
fwrite($pipes[0],$sql); fclose($pipes[0]);
if(proc_close($process)!==0) throw new RuntimeException('SQL recovery failed.');
$pdo=new PDO(str_replace('dbname='.$match[1],'dbname=sfms_backup_recovery_test',$dsn),$backupConfig['user'],$backupConfig['password']);
$row=$pdo->query('SELECT amount, description FROM backup_fixture')->fetch(PDO::FETCH_ASSOC);
if($row['amount']!=='123456789' || $row['description']!=='Synthetic backup fixture') throw new RuntimeException('Restored financial data mismatch.');
echo "PostgreSQL compressed backup, repeated dump and SQL recovery checks passed.\n";
