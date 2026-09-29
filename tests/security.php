<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Core\{Auth,DB};
use App\Services\Users;
$f=json_decode(file_get_contents(ROOT.'/tests/.runtime-fixture.json'),true); if(!str_contains($f['dsn'],'_test;')) throw new RuntimeException('Dedicated test database required.');
DB::configure(['dsn'=>$f['dsn'],'user'=>getenv('SCHOOLLEDGER_TEST_USER')?:'root','password'=>getenv('SCHOOLLEDGER_TEST_PASSWORD')?:'']);
session_start(); Auth::login('super@example.test',$f['password']); $_SESSION['school_id']=$f['school'];
$base=getenv('SCHOOLLEDGER_TEST_URL')?:'http://127.0.0.1:8080'; $cookie=ROOT.'/storage/security-cookie.txt'; file_put_contents($cookie,''); $checks=0;
function callHttp(string $path,array $post=[],bool $multipart=false): array { global $base,$cookie; $ch=curl_init($base.$path); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_HEADER=>true]); if($post) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$post:http_build_query($post)]); $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); $size=curl_getinfo($ch,CURLINFO_HEADER_SIZE); curl_close($ch); return [$status,substr($raw,$size),substr($raw,0,$size)]; }
function csrfToken(string $body): string { preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$m); return $m[1]??throw new RuntimeException('No token'); }
function verify(bool $value,string $label): void { global $checks; if(!$value) throw new RuntimeException('FAIL: '.$label); $checks++; }
[, $body]=callHttp('/?page=login'); callHttp('/?page=login',['csrf'=>csrfToken($body),'email'=>'student@example.test','password'=>$f['password']]);
[$status]=callHttp('/?page=statement&id='.$f['student']); verify($status===200,'Student session before reset');
$student=DB::one("SELECT * FROM users WHERE email='student@example.test'");
Users::save(['id'=>$student['id'],'role_id'=>$student['role_id'],'student_id'=>$student['student_id'],'name'=>$student['name'],'email'=>$student['email'],'password'=>$f['password']]);
[$status]=callHttp('/?page=statement&id='.$f['student']); verify($status===303,'Password reset invalidates existing student session');
[, $body]=callHttp('/?page=login'); callHttp('/?page=login',['csrf'=>csrfToken($body),'email'=>'super@example.test','password'=>$f['password']]); [, $body]=callHttp('/?page=schools'); callHttp('/?page=schools',['csrf'=>csrfToken($body),'action'=>'school.select','school_id'=>$f['school']]); [, $body]=callHttp('/?page=students'); $token=csrfToken($body);
$bad=ROOT.'/storage/bad-upload.png'; file_put_contents($bad,'<?php echo "unsafe";');
[$status]=callHttp('/?page=students',['csrf'=>$token,'action'=>'image.upload','kind'=>'student','id'=>$f['student'],'image'=>new CURLFile($bad,'image/png','photo.png')],true); verify($status===422,'Disguised executable upload rejected');
$good=ROOT.'/storage/test-image.png'; $im=imagecreatetruecolor(12,12); imagepng($im,$good); imagedestroy($im);
[$status]=callHttp('/?page=students',['csrf'=>$token,'action'=>'image.upload','kind'=>'student','id'=>$f['student'],'image'=>new CURLFile($good,'image/png','photo.png')],true); verify($status===303,'Valid image accepted');
[$status,$body,$headers]=callHttp('/?page=image&id='.$f['student']); verify($status===200 && str_contains($headers,'image/png'),'Authenticated image serving');
$photo=DB::one('SELECT photo_path FROM students WHERE id=?',[$f['student']])['photo_path']; verify((bool)preg_match('/^[a-f0-9]{40}\.png$/',$photo),'Random non-executable upload filename');
callHttp('/?page=schools',['csrf'=>$token,'action'=>'school.select','school_id'=>2]); [$status]=callHttp('/?page=image&id='.$f['student']); verify($status===422,'Foreign-school image rejected');
callHttp('/?page=schools',['csrf'=>$token,'action'=>'school.select','school_id'=>$f['school']]);
$original=DB::one('SELECT first_name FROM students WHERE id=?',[$f['student']])['first_name'];
DB::run('UPDATE students SET first_name=? WHERE id=?',['<script>alert("xss")</script>',$f['student']]);
try { [$status,$body]=callHttp('/?page=students'); verify($status===200 && str_contains($body,'&lt;script&gt;') && !str_contains($body,'<script>alert'),'Stored student text escaped'); }
finally { DB::run('UPDATE students SET first_name=? WHERE id=?',[$original,$f['student']]); }
callHttp('/',['action'=>'logout','csrf'=>$token]);
echo "PASS: $checks security checks — session revocation, upload validation, image isolation, XSS escaping\n";
