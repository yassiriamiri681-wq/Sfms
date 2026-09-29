<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sfms_bootstrap_test;';
$user=getenv('SCHOOLLEDGER_TEST_USER'); $password=getenv('SCHOOLLEDGER_TEST_PASSWORD');
$p=new PDO(getenv('SCHOOLLEDGER_TEST_DSN'),$user,$password);
$p->exec('CREATE DATABASE sfms_bootstrap_test');
$path=$root.'/storage/bootstrap-test.php';
file_put_contents($path,'<?php return '.var_export(['dsn'=>$dsn,'user'=>$user,'password'=>$password,'production'=>false,'timezone'=>'Africa/Dar_es_Salaam'],true).';');
putenv('SCHOOLLEDGER_CONFIG='.$path); putenv('SCHOOLLEDGER_BOOTSTRAP=1');
putenv('SCHOOLLEDGER_ADMIN_EMAIL=bootstrap@example.test'); putenv('SCHOOLLEDGER_ADMIN_PASSWORD=Test-only-'.bin2hex(random_bytes(16)));
for($i=0;$i<2;$i++) {
    $process=proc_open([PHP_BINARY,$root.'/bin/initialize-render.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if(proc_close($process)!==0) throw new RuntimeException($err);
    $db=new PDO($dsn,$user,$password);
    $hash=$db->query('SELECT password_hash FROM users WHERE is_super=1')->fetchColumn();
    if($i===0) { $original=$hash; putenv('SCHOOLLEDGER_ADMIN_PASSWORD=Different-password-on-restart'); }
    elseif($hash!==$original || (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()!==1) throw new RuntimeException('Restart changed existing account.');
}
echo "PASS: initial setup and restart preserve administrator credentials\n";
