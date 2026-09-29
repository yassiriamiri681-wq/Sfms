<?php
declare(strict_types=1);
ini_set('display_errors','0');
require dirname(__DIR__).'/app/bootstrap.php';
ini_set('display_errors','0'); ini_set('log_errors','1'); ini_set('error_log',ROOT.'/storage/php-error.log');
header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
$secure=!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off';
if($config['production'] && !$secure) { http_response_code(503); echo 'HTTPS is required. Configure TLS before using this installation.'; exit; }
if($secure) header('Strict-Transport-Security: max-age=31536000');
session_name('schoolledger_'.substr(hash('sha256',$config['dsn']),0,10)); ini_set('session.use_strict_mode','1'); session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']); session_start();
if(isset($_SESSION['last_seen']) && $_SESSION['last_seen']<time()-1800) { $_SESSION=[]; session_regenerate_id(true); }
$_SESSION['last_seen']=time(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
$app=new App\Controllers\Application();
ob_start();
try { $app->run(); }
catch(\Throwable $error) {
    ob_clean();
    if($error instanceof \DomainException) { if(http_response_code()<400) http_response_code(422); $message=$error->getMessage(); }
    elseif($error instanceof \PDOException && str_starts_with((string)$error->getCode(),'23')) { http_response_code(409); $message='This record already exists, is already assigned, or references an invalid record. No changes were saved.'; }
    else { http_response_code(500); $ref=bin2hex(random_bytes(4)); error_log($ref.' '.$error); $message='Unable to complete this request. Contact your administrator with reference '.$ref.'.'; }
    view('error',['message'=>$message]);
}
