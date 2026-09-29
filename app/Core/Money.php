<?php
declare(strict_types=1);
namespace App\Core;
final class Money {
    public static function parse(string $value, bool $signed = false): int {
        $value = trim($value);
        if (!preg_match($signed ? '/^-?\d{1,10}(?:\.\d{1,2})?$/' : '/^\d{1,10}(?:\.\d{1,2})?$/', $value)) throw new \DomainException('Enter an amount with at most two decimal places.');
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));
        $amount = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
        return $negative ? -$amount : $amount;
    }
    public static function format(int $minor): string { return ($minor < 0 ? '-' : '') . number_format(intdiv(abs($minor), 100)) . '.' . str_pad((string)(abs($minor) % 100), 2, '0', STR_PAD_LEFT); }
}
