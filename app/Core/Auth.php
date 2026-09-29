<?php
declare(strict_types=1);
namespace App\Core;
final class Auth {
    private static ?array $user = null;
    public static function user(): ?array {
        if (self::$user) return self::$user;
        if (empty($_SESSION['user_id'])) return null;
        $user=DB::one('SELECT u.*, s.active school_active FROM users u LEFT JOIN schools s ON s.id=u.school_id WHERE u.id=? AND u.active=1', [$_SESSION['user_id']]);
        if(!$user || (int)($_SESSION['session_version']??0)!==(int)$user['session_version']) return null;
        return self::$user=$user;
    }
    public static function login(string $email, string $password): bool {
        $key = hash('sha256', strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
        $result = DB::transaction(function () use ($email, $password, $key) {
            DB::run('INSERT IGNORE INTO login_attempts (attempt_key, failures, started_at) VALUES (?,0,NOW())', [$key]);
            $attempt = DB::one('SELECT * FROM login_attempts WHERE attempt_key=? FOR UPDATE', [$key]);
            if (strtotime($attempt['started_at']) < time()-900) { DB::run('UPDATE login_attempts SET failures=0, started_at=NOW() WHERE attempt_key=?', [$key]); $attempt['failures']=0; }
            if ((int)$attempt['failures'] >= 5) return null;
            $u = DB::one('SELECT u.*,s.active school_active FROM users u LEFT JOIN schools s ON s.id=u.school_id WHERE u.email=?', [strtolower(trim($email))]);
            $valid = password_verify($password, $u['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$valid || !$u || !$u['active'] || ($u['school_id'] && !$u['school_active'])) { DB::run('UPDATE login_attempts SET failures=failures+1 WHERE attempt_key=?', [$key]); return null; }
            DB::run('DELETE FROM login_attempts WHERE attempt_key=?', [$key]); return $u;
        });
        if (!$result) return false;
        session_regenerate_id(true); $_SESSION = ['user_id' => $result['id'], 'session_version'=>(int)$result['session_version'], 'csrf' => bin2hex(random_bytes(32)), 'last_seen' => time()]; self::$user=$result;
        self::audit('login', 'users', (int)$result['id']); return true;
    }
    public static function schoolId(): int {
        $u=self::user(); if (!$u) throw new \DomainException('Please sign in.');
        $id = $u['is_super'] ? (int)($_SESSION['school_id'] ?? 0) : (int)$u['school_id'];
        if (!$id || !DB::one('SELECT id FROM schools WHERE id=? AND active=1', [$id])) throw new \DomainException('Select an active school first.');
        return $id;
    }
    public static function can(string $permission): bool {
        $u=self::user(); if (!$u || ($u['school_id'] && !$u['school_active'])) return false;
        if ($u['is_super']) return true;
        return (bool) DB::one('SELECT rp.role_id FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id JOIN roles r ON r.id=rp.role_id WHERE r.school_id=? AND r.id=? AND p.code=?', [$u['school_id'],$u['role_id'],$permission]);
    }
    public static function require(string $permission): void { if (!self::can($permission)) { http_response_code(403); throw new \DomainException('You do not have permission for this action.'); } }
    public static function owned(string $table, int $id): array {
        if (!in_array($table, ['students','academic_years','terms','classes','streams','student_types','hostels','rooms','beds','fee_types','fee_structures','invoices','payments','users','roles','student_academic_history'],true)) throw new \LogicException('Invalid resource');
        $row=DB::one("SELECT * FROM $table WHERE school_id=? AND id=?", [self::schoolId(),$id]);
        if (!$row) throw new \DomainException('Record not found in this school.'); return $row;
    }
    public static function studentScope(int $studentId): void {
        self::owned('students', $studentId);
        if (!self::can('finance.read')) self::require('statement.self');
        if (!self::can('finance.read') && (int)(self::user()['student_id'] ?? 0) !== $studentId) { http_response_code(403); throw new \DomainException('You may only view your own statement.'); }
    }
    public static function audit(string $action, string $entity, int $id, string $description = ''): void {
        $u=self::user(); DB::insert('audit_logs', ['school_id' => $u && !$u['is_super'] ? $u['school_id'] : ($_SESSION['school_id'] ?? null), 'user_id'=>$u['id'] ?? null, 'action'=>$action,'entity'=>$entity,'record_id'=>$id,'description'=>$description,'ip_address'=>substr($_SERVER['REMOTE_ADDR'] ?? 'cli',0,45)]);
    }
}
