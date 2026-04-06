<?php

namespace FacturaScripts\Plugins\SolwedES\CronJob;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;

/**
 * Dispatches domain sync to the DomainSyncWorker (background).
 * Runs every hour.
 */
class SyncDomains
{
    public static function run(): void
    {
        WorkQueue::send('solwed.sync.domains', 0, []);
        Tools::log('solwed-cron')->info('Domain sync dispatched to worker');
    }
}
