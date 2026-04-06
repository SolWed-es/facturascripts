<?php

/**
 * Plugin SolwedES - Tareas programadas
 *
 * Each job delegates to its CronJob/ class. Heavy work runs in Workers (background queue).
 *
 * @author Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\SolwedES\CronJob\SyncDomains;
use FacturaScripts\Plugins\SolwedES\CronJob\CheckExpiringServices;
use FacturaScripts\Plugins\SolwedES\CronJob\SyncRedis;

class Cron extends CronClass
{
    public function run(): void
    {
        // Sync domains from Redis → FS model (dispatches to DomainSyncWorker)
        $this->job('solwed-sync-domains')
            ->every('1 hour')
            ->run(fn() => SyncDomains::run());

        // Check services expiring in the next 7 days
        $this->job('solwed-check-expiring')
            ->everyDayAt(8)
            ->run(fn() => CheckExpiringServices::run());

        // Full FS → Redis sync (safety net, workers handle real-time)
        $this->job('solwed-sync-redis')
            ->every('30 minutes')
            ->run(fn() => SyncRedis::run());
    }
}
