<?php
return [
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=schoolledger;charset=utf8mb4',
    'user' => 'schoolledger', 'password' => '',
    'production' => true, 'timezone' => 'Africa/Dar_es_Salaam',
    'automatic_database_backup' => true,
    'mysqldump_path' => 'mysqldump', // Install MySQL client on the application server.
];
