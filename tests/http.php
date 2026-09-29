<?php
declare(strict_types=1);
$root=dirname(__DIR__); $fixture=json_decode(file_get_contents($root.'/tests/.runtime-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$base=getenv('SCHOOLLEDGER_TEST_URL')?:'http://127.0.0.1:8080'; $cookie=$root.'/storage/http-test-cookie.txt'; $count=0;
file_put_contents($cookie,'');
function request(string $path,array $post=[]): array { global $base,$cookie; $ch=curl_init($base.$path); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false]); if($post) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]); $raw=curl_exec($ch); if($raw===false) throw new RuntimeException(curl_error($ch)); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); $size=curl_getinfo($ch,CURLINFO_HEADER_SIZE); curl_close($ch); return [$status,substr($raw,$size),substr($raw,0,$size)]; }
function check(bool $ok,string $label): void { global $count; if(!$ok) throw new RuntimeException('FAIL: '.$label); $count++; }
function token(string $html): string { if(!preg_match('/name="csrf" value="([a-f0-9]+)"/',$html,$m)) throw new RuntimeException('Missing CSRF token'); return $m[1]; }
function login(string $email): void { global $fixture; [$s,$body]=request('/?page=login'); if($s===303) { [$s,$body]=request('/?page=schools'); if($s!==200) [$s,$body]=request('/?page=students'); request('/',['action'=>'logout','csrf'=>token($body)]); [$s,$body]=request('/?page=login'); } [$status]=request('/?page=login',['csrf'=>token($body),'email'=>$email,'password'=>$fixture['password']]); check($status===303,'Login '.$email); }
try {
    [$status,$html,$headers]=request('/?page=login'); check($status===200,'Login page renders'); check(str_contains($headers,'Content-Security-Policy:'),'CSP header present'); check(str_contains(strtolower($headers),'httponly'),'HttpOnly session cookie');
    [$status]=request('/?page=students'); check($status===303,'Unauthenticated directory denied');
    [$status]=request('/?page=login',['csrf'=>'wrong','email'=>'x@example.test','password'=>'bad']); check($status===419,'CSRF failure rejected');
    login('super@example.test'); [$s,$html]=request('/?page=schools'); check($s===200,'School administration page');
    [$s]=request('/?page=schools',['action'=>'school.select','school_id'=>$fixture['school'],'csrf'=>token($html)]); check($s===303,'Select school workspace');
    foreach(['dashboard','students','student-form','student&id='.$fixture['student'],'academics','academics&table=terms','academics&table=student_types','boarding','fee-types','fees','fees&id=1','invoices','invoice&id='.$fixture['invoice'],'payments','receipt&id='.$fixture['payment'],'statement&id='.$fixture['student'],'users','users&edit=2','settings','audit','backups'] as $route) { [$s,$html]=request('/?page='.$route); check($s===200,'Render '.$route.' (HTTP '.$s.')'); check(!str_contains($html,'Warning:') && !str_contains($html,'Fatal error:'),'No PHP output errors '.$route); }
    foreach(['outstanding','student-fees','paid','partial','daily','monthly','term','year','method','class','student-type','receipts','discounts','adjustments'] as $report) { [$s]=request('/?page=reports&type='.$report); check($s===200,'Report '.$report); }
    [$s,$csv,$headers]=request('/?page=reports&type=receipts&csv=1'); check($s===200 && str_contains($headers,'text/csv') && str_contains($csv,'BANK-CORRECT'),'CSV export has recorded payment');
    foreach(['platform','global-audit','academics&table=classes&edit=1','fee-types&edit=1'] as $route) { [$s]=request('/?page='.$route); check($s===200,'Administration '.$route); }
    [$s,$html]=request('/?page=dashboard'); $csrf=token($html);
    [$s]=request('/?page=invoices',['csrf'=>$csrf,'action'=>'invoice.create','student_id'=>999999,'term_id'=>1,'issued_on'=>date('Y-m-d'),'due_on'=>date('Y-m-d')]); check($s===422,'Forged student rejected over HTTP');
    [$s]=request('/?page=students&q=%27%20OR%201%3D1%20--'); check($s===200,'Search safely handles SQL-like input');
    [$s]=request('/?page=unknown'); check($s===404,'Unknown route returns 404');
    request('/',['action'=>'logout','csrf'=>$csrf]); login('teacher@example.test');
    [$s,$html]=request('/?page=students'); check($s===200,'Teacher directory access'); $csrf=token($html);
    foreach(['invoices','payments','reports','users','audit','settings','backups','statement&id='.$fixture['student']] as $route) { [$s]=request('/?page='.$route); check($s===403,'Teacher denied '.$route); }
    [$s]=request('/?page=students',['csrf'=>$csrf,'action'=>'payment.create','invoice_id'=>$fixture['invoice']]); check($s===403,'Teacher forged payment denied');
    request('/',['action'=>'logout','csrf'=>$csrf]); login('student@example.test');
    [$s,$html]=request('/?page=statement&id='.$fixture['student']); check($s===200,'Student own statement'); $csrf=token($html);
    [$s]=request('/?page=statement&id=2'); check($s===403,'Student foreign statement denied');
    [$s]=request('/?page=students'); check($s===403,'Student directory denied');
    request('/',['action'=>'logout','csrf'=>$csrf]);
    echo "PASS: $count HTTP checks\n";
} catch(Throwable $e) { fwrite(STDERR,$e."\n"); exit(1); }
