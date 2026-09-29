$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if ($phpCommand) { $phpExecutable = $phpCommand.Source }
else {
    $phpExecutable = Get-ChildItem -LiteralPath 'C:\laragon\bin\php' -Filter php.exe -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $phpExecutable) { throw 'PHP 8.3+ was not found. Install PHP or add it to PATH.' }
Set-Location -LiteralPath $projectRoot
Write-Host 'SchoolLedger: http://127.0.0.1:8080 — press Ctrl+C to stop.'
& $phpExecutable -S 127.0.0.1:8080 -t public public/router.php
