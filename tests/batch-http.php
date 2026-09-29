<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\{DB,Auth};
use App\Services\Catalog;
$f=json_decode(file_get_contents(ROOT.'/tests/.runtime-fixture.json'),true);
if(!str_contains($f['dsn'],'_test;')) throw new RuntimeException('Test database required.');
DB::configure(['dsn'=>$f['dsn'],'user'=>getenv('SCHOOLLEDGER_TEST_USER')?:'root','password'=>getenv('SCHOOLLEDGER_TEST_PASSWORD')?:'']);
session_start(); Auth::login('super@example.test',$f['password']); $_SESSION['school_id']=$f['school'];
$year=DB::one("SELECT * FROM academic_years WHERE name='Next batch year'");
$class=DB::one("SELECT id FROM classes WHERE school_id=? AND name='Form 2'",[$f['school']]);
Catalog::save(['table'=>'terms','name'=>'Web batch term','academic_year_id'=>$year['id'],'starts_on'=>$year['starts_on'],'ends_on'=>$year['ends_on']]);
$term=(int)DB::one('SELECT MAX(id) id FROM terms')['id'];
$fee=(int)DB::one('SELECT id FROM fee_types WHERE school_id=? ORDER BY id LIMIT 1',[$f['school']])['id'];
foreach(DB::all('SELECT * FROM student_types WHERE school_id=? AND name<>?',[$f['school'],'Unconfigured category']) as $type) {
    $structure=Catalog::structure(['academic_year_id'=>$year['id'],'term_id'=>$term,'class_id'=>$class['id'],'student_type_id'=>$type['id']]);
    Catalog::item(['fee_structure_id'=>$structure,'fee_type_id'=>$fee,'amount'=>$type['requires_boarding']?'200':'100']);
}
$base=getenv('SCHOOLLEDGER_TEST_URL')?:'http://127.0.0.1:8081';
$cookie=ROOT.'/storage/batch-http-cookie.txt'; file_put_contents($cookie,''); $checks=0;
function req(string $path,array $post=[]): array { global $base,$cookie; $ch=curl_init($base.$path); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_HEADER=>true]); if($post) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]); $raw=curl_exec($ch); if($raw===false) throw new RuntimeException(curl_error($ch)); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); $size=curl_getinfo($ch,CURLINFO_HEADER_SIZE); curl_close($ch); return [$status,substr($raw,$size),substr($raw,0,$size)]; }
function tok(string $html): string { preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m); return $m[1]??throw new RuntimeException('Missing CSRF'); }
function verifyBatch(bool $condition,string $label): void { global $checks; if(!$condition) throw new RuntimeException('FAIL: '.$label); $checks++; }
[, $html]=req('/?page=login'); req('/?page=login',['csrf'=>tok($html),'email'=>'super@example.test','password'=>$f['password']]); [, $html]=req('/?page=schools'); req('/?page=schools',['csrf'=>tok($html),'action'=>'school.select','school_id'=>$f['school']]);
[$status,$html]=req('/?page=batch&kind=billing'); verifyBatch($status===200 && str_contains($html,'Preview students'),'Billing form renders'); $csrf=tok($html);
[$status]=req('/?page=batch&kind=promotion'); verifyBatch($status===200,'Promotion form renders');
$request=['action'=>'batch.preview','kind'=>'billing','csrf'=>$csrf,'source_year_id'=>$year['id'],'source_class_id'=>$class['id'],'term_id'=>$term,'issued_on'=>date('Y-m-d'),'due_on'=>date('Y-m-d')];
[$status,,$headers]=req('/?page=batch&kind=billing',$request); verifyBatch($status===303,'Preview redirects to stored review'); preg_match('/Location: ([^\r\n]+)/i',$headers,$location); $path=$location[1];
[$status,$html]=req($path); verifyBatch($status===200 && str_contains($html,'500.00'),'Review shows category-specific total');
preg_match('/name="review" value="([a-f0-9]+)"/',$html,$match); $review=$match[1];
[$status]=req('/?page=batch&kind=billing',['action'=>'batch.apply','csrf'=>'invalid','review'=>$review]); verifyBatch($status===419,'Apply requires CSRF');
[$status]=req('/?page=batch&kind=billing',['action'=>'batch.apply','csrf'=>$csrf,'review'=>$review,'amount'=>'0.01']); verifyBatch($status===303,'Apply uses server-owned review instead of posted amount');
$totals=DB::one('SELECT COUNT(*) n,SUM(total) total FROM invoice_balances WHERE school_id=? AND term_id=?',[$f['school'],$term]); verifyBatch((int)$totals['n']===3 && (int)$totals['total']===50000,'Three invoices posted with reviewed amounts');
[$status]=req('/?page=batch&kind=billing',['action'=>'batch.apply','csrf'=>$csrf,'review'=>$review]); verifyBatch($status===422,'Consumed review cannot replay');
[$status]=req($path); verifyBatch($status===422,'Consumed review unavailable');
req('/',['action'=>'logout','csrf'=>$csrf]); [, $html]=req('/?page=login'); req('/?page=login',['csrf'=>tok($html),'email'=>'teacher@example.test','password'=>$f['password']]);
foreach(['billing','promotion'] as $kind) { [$status]=req('/?page=batch&kind='.$kind); verifyBatch($status===403,'Teacher cannot open '.$kind); }
[, $html]=req('/?page=students'); $csrf=tok($html);
[$status]=req('/?page=students',array_merge($request,['csrf'=>$csrf])); verifyBatch($status===403,'Forged preview action denied'); req('/',['action'=>'logout','csrf'=>$csrf]);
echo "PASS: $checks batch HTTP checks\n";
