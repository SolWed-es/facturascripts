<?php

/**
 * Plugin SolwedES - Worker for real-time FS → Redis sync
 *
 * Listens to model Save/Delete events and updates Redis immediately.
 * This complements the Cron-based full sync (every 30 min) with
 * real-time updates when data changes.
 *
 * Events handled:
 *   Model.Suscripcion.*  → updates fs:suscripciones, fs:suscripcion:{id}
 *   Model.PagoStripe.*   → updates fs:pagos:recientes
 *   Model.Dominio.*      → updates fs:dominios
 *   Model.Servicio.*     → updates fs:servicios, fs:servicio:{id}
 *   Model.Cliente.*      → updates fs:clientes, fs:cliente:{cod}
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Worker;

use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Lib\FacturaScriptsSync;

class RedisSyncWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        $name = $event->name();

        try {
            if (str_starts_with($name, 'Model.Suscripcion.')) {
                FacturaScriptsSync::syncSuscripciones();
                FacturaScriptsSync::syncStats();
            } elseif (str_starts_with($name, 'Model.PagoStripe.')) {
                FacturaScriptsSync::syncPagos();
                FacturaScriptsSync::syncStats();
            } elseif (str_starts_with($name, 'Model.Dominio.')) {
                FacturaScriptsSync::syncDominios();
            } elseif (str_starts_with($name, 'Model.Servicio.')) {
                FacturaScriptsSync::syncServicios();
            } elseif (str_starts_with($name, 'Model.Cliente.')) {
                FacturaScriptsSync::syncClientes();
            } elseif (str_starts_with($name, 'Model.FacturaCliente.')) {
                FacturaScriptsSync::syncFacturas();
                FacturaScriptsSync::syncStats();
            }
        } catch (\Throwable $e) {
            Tools::log('solwed')->warning('RedisSyncWorker failed for ' . $name . ': ' . $e->getMessage());
            return false;
        }

        return $this->done();
    }
}
