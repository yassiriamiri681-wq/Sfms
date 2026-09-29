<?php
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }
});
$configFile = in_array(PHP_SAPI, ['cli', 'cli-server'], true) && getenv('SCHOOLLEDGER_CONFIG') ? getenv('SCHOOLLEDGER_CONFIG') : ROOT . '/config/local.php';
$config = is_file($configFile) ? require $configFile : require ROOT . '/config/example.php';
date_default_timezone_set($config['timezone']);
if (!is_dir(ROOT . '/storage/sessions')) mkdir(ROOT . '/storage/sessions', 0700, true);
session_save_path(ROOT . '/storage/sessions');
App\Core\DB::configure($config);
function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(int|string $value): string { return App\Core\Money::format((int)$value); }
function url(string $page, array $params = []): string { return '/?' . http_build_query(['page' => $page] + $params); }
function redirect(string $page, array $params = []): never { header('Location: ' . url($page, $params), true, 303); exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function view(string $__template, array $__variables = []): void { extract($__variables, EXTR_SKIP); require ROOT . '/views/' . $__template . '.php'; }
