<?php
declare(strict_types=1);
$root=dirname(__DIR__); $f=json_decode(file_get_contents($root.'/tests/.runtime-fixture.json'),true);
$dsn=getenv('SCHOOLLEDGER_RECOVERY_DSN')?:'';
if(!preg_match('/dbname=([a-zA-Z0-9_]+_test)(?:;|$)/',$dsn)) throw new RuntimeException('Use a separate empty recovery database ending in _test.');
$user=getenv('SCHOOLLEDGER_TEST_USER')?:'root'; $password=getenv('SCHOOLLEDGER_TEST_PASSWORD')?:'';
$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($pdo->query('SHOW TABLES')->fetchAll()) throw new RuntimeException('Recovery test database must be empty.');
foreach(explode(';',file_get_contents($root.'/database/schema.sql')) as $sql) if(trim($sql)) $pdo->exec($sql);
$path=$root.'/storage/recovery-config.php'; file_put_contents($path,"<?php return ".var_export(['dsn'=>$dsn,'user'=>$user,'password'=>$password,'timezone'=>'Africa/Dar_es_Salaam','production'=>false],true).';');
putenv('SCHOOLLEDGER_CONFIG='.$path); $pipes=[];
$process=proc_open([PHP_BINARY,$root.'/bin/restore-tenant.php',$root.'/storage/'.$f['backup']],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
if(proc_close($process)!==0) throw new RuntimeException('Recovery failed: '.$err);
$balance=$pdo->query('SELECT balance FROM invoice_balances WHERE id='.(int)$f['invoice'])->fetchColumn();
if((int)$balance!==0) throw new RuntimeException('Restored balance does not reconcile.');
if((int)$pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn()!==1) throw new RuntimeException('Unexpected school count.');
if((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_super=1 AND active=1')->fetchColumn()!==0) throw new RuntimeException('Archived actors must be disabled.');
echo "PASS: tenant backup restored into a fresh database; balance, school isolation and archived identities verified\n";
