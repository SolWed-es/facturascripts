<?php

namespace FacturaScripts\Plugins\SolwedES\CronJob;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Lib\ClienteServiciosManager;

/**
 * Checks for services expiring in the next 7 days and logs warnings.
 * Runs daily at 8:00.
 */
class CheckExpiringServices
{
    public static function run(): void
    {
        $serviciosProximosVencer = ClienteServiciosManager::getServiciosProximosAVencer(7);

        foreach ($serviciosProximosVencer as $servicio) {
            Tools::log('solwed-cron')->info(sprintf(
                'Servicio próximo a vencer: %s (Cliente: %s, Vence: %s)',
                $servicio['nombre'] ?? 'N/A',
                $servicio['codcliente'] ?? 'N/A',
                $servicio['fecha_renovacion'] ?? 'N/A'
            ));
        }
    }
}
