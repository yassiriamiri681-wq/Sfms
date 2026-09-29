<?php
declare(strict_types=1);
namespace App\Core;
final class Input {
    public static function text(array $input, string $key, int $max = 190, bool $required = true): string {
        $v = trim((string)($input[$key] ?? ''));
        if (($required && $v === '') || strlen($v) > $max) throw new \DomainException('Check ' . str_replace('_', ' ', $key) . ' (maximum ' . $max . ' characters).');
        return $v;
    }
    public static function id(array $input, string $key): int { $v = filter_var($input[$key] ?? '', FILTER_VALIDATE_INT); if (!$v || $v < 1) throw new \DomainException('Select a valid ' . str_replace('_', ' ', $key) . '.'); return $v; }
    public static function date(array $input, string $key): string { $v = self::text($input, $key, 10); $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v); if (!$d || $d->format('Y-m-d') !== $v) throw new \DomainException('Enter a valid ' . str_replace('_', ' ', $key) . '.'); return $v; }
    public static function choice(array $input, string $key, array $values): string { $v = self::text($input, $key); if (!in_array($v, $values, true)) throw new \DomainException('Invalid ' . $key); return $v; }
}
