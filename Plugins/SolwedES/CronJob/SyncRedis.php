<?php

namespace FacturaScripts\Plugins\SolwedES\CronJob;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Lib\FacturaScriptsSync;

/**
 * Full FS → Redis sync.
 * Runs every 30 minutes as safety net (workers handle real-time updates).
 */
class SyncRedis
{
    public static function run(): void
    {
        try {
            $stats = FacturaScriptsSync::syncAll();
            Tools::log('solwed-cron')->info(sprintf(
                'FS → Redis sync: %d subs, %d pagos, %d facturas, %d clientes, %d dominios, %d servicios, MRR: %.2f€',
                $stats['suscripciones'], $stats['pagos'], $stats['facturas'],
                $stats['clientes'], $stats['dominios'], $stats['servicios'],
                $stats['stats']['mrr'] ?? 0
            ));
        } catch (\Exception $e) {
            Tools::log('solwed-cron')->error('FS → Redis sync failed: ' . $e->getMessage());
        }
    }
}
