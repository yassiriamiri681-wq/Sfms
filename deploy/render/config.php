<?php
declare(strict_types=1);
$required = static function (string $key): string {
    $value = getenv($key);
    if ($value === false || $value === '') throw new RuntimeException('Missing deployment setting: '.$key);
    return $value;
};
return [
    'dsn' => $required('SCHOOLLEDGER_DSN'),
    'user' => $required('SCHOOLLEDGER_DB_USER'),
    'password' => $required('SCHOOLLEDGER_DB_PASSWORD'),
    'ssl_ca' => $required('SCHOOLLEDGER_DB_SSL_CA'),
    'production' => true,
    'timezone' => 'Africa/Dar_es_Salaam',
];
