<?php
declare(strict_types=1);
namespace App\Core;

final class Navigation
{
    public const DESTINATIONS = [
        'dashboard'=>'Overview', 'students'=>'Students', 'academics'=>'Academic setup',
        'boarding'=>'Boarding', 'fees'=>'Fee structures', 'fee-types'=>'Fee types',
        'invoices'=>'Invoices', 'payments'=>'Payments & receipts', 'reports'=>'Reports',
        'users'=>'Users & permissions', 'settings'=>'School settings', 'audit'=>'Audit trail',
        'backups'=>'Backups', 'class-invoices'=>'Class invoices', 'class-promotion'=>'Promote a class',
    ];

    public static function destination(string $value): string
    {
        return isset(self::DESTINATIONS[$value]) ? $value : 'dashboard';
    }

    public static function target(string $value): array
    {
        return match(self::destination($value)) {
            'class-invoices'=>['batch',['kind'=>'billing']],
            'class-promotion'=>['batch',['kind'=>'promotion']],
            default=>[self::destination($value),[]],
        };
    }

    public static function currentDestination(array $query): string
    {
        if (($query['page'] ?? '') === 'batch') return ($query['kind'] ?? '') === 'promotion' ? 'class-promotion' : 'class-invoices';
        return self::destination((string)($query['page'] ?? 'dashboard'));
    }

    public static function parent(array $query): string
    {
        $page = (string)($query['page'] ?? 'dashboard');
        return match($page) {
            'student','student-form','statement'=>'students',
            'fee-types'=>'fees', 'invoice'=>'invoices', 'receipt'=>'payments',
            'batch'=>($query['kind'] ?? '') === 'promotion' ? 'students' : 'invoices',
            default=>$page,
        };
    }
}
