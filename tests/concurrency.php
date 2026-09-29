<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\{DB,Auth};
use App\Services\Finance;
$f=json_decode(file_get_contents(ROOT.'/tests/.runtime-fixture.json'),true);
if(!str_contains($f['dsn'],'_test;')) throw new RuntimeException('Dedicated test database required.');
DB::configure(['dsn'=>$f['dsn'],'user'=>getenv('SCHOOLLEDGER_TEST_USER')?:'root','password'=>getenv('SCHOOLLEDGER_TEST_PASSWORD')?:'']);
session_start(); Auth::login('super@example.test',$f['password']); $_SESSION['school_id']=$f['school'];
if(($argv[1]??'')==='worker') {
    try { Finance::payment(['invoice_id'=>(int)$argv[2],'amount'=>'1000000','method'=>'Cash','paid_on'=>date('Y-m-d'),'request_key'=>$argv[3]]); echo 'posted'; }
    catch(DomainException $e) { echo 'rejected'; } exit;
}
$student=(int)DB::one("SELECT id FROM students WHERE school_id=? AND admission_number='A-003'",[$f['school']])['id'];
$invoice=Finance::invoice(['student_id'=>$student,'term_id'=>1,'issued_on'=>date('Y-m-d'),'due_on'=>date('Y-m-d')]);
$workers=[];
for($n=0;$n<2;$n++) { $pipes=[]; $proc=proc_open([PHP_BINARY,__FILE__,'worker',(string)$invoice,bin2hex(random_bytes(24))],[1=>['pipe','w'],2=>['pipe','w']],$pipes); $workers[]=[$proc,$pipes]; }
$results=[];
foreach($workers as [$proc,$pipes]) { $results[]=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($proc)!==0 || $err) throw new RuntimeException('Worker failed: '.$err); }
sort($results); $b=Finance::balance($invoice);
if($results!==['posted','rejected'] || (int)$b['paid']!==100000000 || (int)$b['balance']!==50000000) throw new RuntimeException('Concurrent payment integrity failed.');
echo "PASS: two concurrent payment processes — one posts, one rejects, no over-allocation\n";
