<?php
declare(strict_types=1);
$required = static function (string $key): string {
    $value = getenv($key);
    if ($value === false || $value === '') throw new RuntimeException('Missing deployment setting: '.$key);
    return $value;
};
$dsn=$required('SCHOOLLEDGER_DSN');
if(str_starts_with($dsn,'pgsql:')) {
    // Neon uses a publicly trusted certificate. Verify both the chain and hostname.
    $dsn=preg_replace('/;(sslmode|sslrootcert)=[^;]*/','',$dsn);
    $dsn.=';sslmode=verify-full;sslrootcert=/etc/ssl/certs/ca-certificates.crt';
}
return [
    'dsn' => $dsn,
    'user' => $required('SCHOOLLEDGER_DB_USER'),
    'password' => $required('SCHOOLLEDGER_DB_PASSWORD'),
    'ssl_ca' => str_starts_with($dsn,'mysql:')?$required('SCHOOLLEDGER_DB_SSL_CA'):null,
    'production' => true,
    'timezone' => 'Africa/Dar_es_Salaam',
];
