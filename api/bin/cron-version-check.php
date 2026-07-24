<?php

declare(strict_types=1);

/**
 * MyInvoice.cz — cron-version-check
 *
 * Denně volá GitHub Releases API, ukládá poslední verzi + release notes
 * do tabulky `app_meta`. UI / footer pak zobrazují cached hodnoty bez
 * blocking síťového callu při každém page loadu.
 *
 * Plánování:
 *   - Linux/cron:        0 6 * * *  cd /path && php api/bin/cron-version-check.php
 *   - Docker:            stejně, ale `docker compose exec app php api/bin/cron-version-check.php`
 *   - Windows/Scheduler: denně, akce: php.exe api\bin\cron-version-check.php
 *
 * Idempotentní — opakované spuštění neuškodí, jen aktualizuje timestamp.
 */

require __DIR__ . '/../vendor/autoload.php';

// STDOUT/STDERR existují jen v CLI SAPI. Tento skript lze spustit i z admin UI
// („Plánované úlohy → spustit teď") a na sdíleném hostingu může spawn skončit
// pod php-cgi/FastCGI, kde konstanty chybí — bez polyfillu by fwrite(STDERR) fataloval.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Update\VersionService;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);

$run = CronRun::start($conn->pdo(), 'cron-version-check');

$svc    = new VersionService($conn);
$status = $svc->refreshLatestVersion();

$current = $status['current'] ?? '?';
$latest  = $status['latest']  ?? '?';
$err     = $status['last_check_error'] ?? '';

if ($err !== '') {
    fwrite(STDERR, "[cron-version-check] FAILED: {$err}\n");
    $run->finish('error', ['current' => $current, 'error' => $err], $err, 1);
    exit(1);
}

$marker = $status['has_update'] ? ' (UPDATE AVAILABLE)' : '';
echo "[cron-version-check] OK current={$current} latest={$latest}{$marker}\n";

$run->finish('ok', ['current' => $current, 'latest' => $latest, 'has_update' => (bool) ($status['has_update'] ?? false)]);
