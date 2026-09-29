<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
final class DB {
    private static array $config;
    private static ?PDO $pdo = null;
    private static int $savepoint = 0;
    public static function configure(array $config): void { self::$config = $config; self::$pdo = null; }
    public static function postgres(): bool { return str_starts_with(self::$config['dsn'], 'pgsql:'); }
    public static function schemaFile(): string { return ROOT.'/database/'.(self::postgres()?'schema.pgsql.sql':'schema.sql'); }
    public static function tables(): array { return self::all(self::postgres()?"SELECT tablename FROM pg_tables WHERE schemaname=current_schema()":'SHOW TABLES'); }
    public static function upsert(string $table, array $data, array $conflict, string $column): void {
        foreach ([$table,$column,...array_keys($data),...$conflict] as $name) if(!preg_match('/^[a-z_]+$/',$name)) throw new \LogicException('Invalid identifier');
        $sql='INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')';
        $sql.=self::postgres()?' ON CONFLICT ('.implode(',',$conflict).') DO UPDATE SET '.$column.'=EXCLUDED.'.$column:' ON DUPLICATE KEY UPDATE '.$column.'=VALUES('.$column.')';
        self::run($sql,array_values($data));
    }
    public static function connection(): PDO {
        if (!self::$pdo) {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
            if (!empty(self::$config['ssl_ca'])) {
                if (!is_readable(self::$config['ssl_ca'])) throw new \RuntimeException('Database CA certificate is not readable.');
                $options[PDO::MYSQL_ATTR_SSL_CA] = self::$config['ssl_ca'];
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
            self::$pdo = new PDO(self::$config['dsn'], self::$config['user'], self::$config['password'], $options);
            self::$pdo->exec(self::postgres()?"SET TIME ZONE 'Africa/Dar_es_Salaam'":"SET time_zone = '+03:00'");
            self::$pdo->exec(self::postgres()?"SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED":'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        return self::$pdo;
    }
    public static function run(string $sql, array $params = []): \PDOStatement { $s = self::connection()->prepare($sql); $s->execute($params); return $s; }
    public static function all(string $sql, array $params = []): array { return self::run($sql, $params)->fetchAll(); }
    public static function one(string $sql, array $params = []): ?array { return self::run($sql, $params)->fetch() ?: null; }
    public static function insert(string $table, array $data): int {
        if (!preg_match('/^[a-z_]+$/', $table)) throw new \LogicException('Invalid table');
        $keys = array_keys($data);
        foreach($keys as $key) if(!preg_match('/^[a-z_]+$/',$key)) throw new \LogicException('Invalid column');
        if(self::postgres()) {
            $hasId=!in_array($table,['role_permissions','login_attempts','platform_settings','stored_images'],true);
            $result=self::run('INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')'.($hasId?' RETURNING id':''),array_values($data));
            return $hasId?(int)$result->fetchColumn():0;
        }
        self::run('INSERT INTO `' . $table . '` (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')', array_values($data));
        return (int)self::connection()->lastInsertId();
    }
    public static function transaction(callable $fn): mixed {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            $savepoint = 'schoolledger_' . (++self::$savepoint);
            $pdo->exec('SAVEPOINT ' . $savepoint);
            try { $result = $fn(); $pdo->exec('RELEASE SAVEPOINT ' . $savepoint); return $result; }
            catch (\Throwable $error) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint); $pdo->exec('RELEASE SAVEPOINT ' . $savepoint); } throw $error; }
        }
        $pdo->beginTransaction();
        try { $result = $fn(); $pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
