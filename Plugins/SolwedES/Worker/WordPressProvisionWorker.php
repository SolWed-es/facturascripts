<?php

/**
 * Plugin SolwedES - Worker for WordPress provisioning
 *
 * Runs WordPress hosting provisioning in background (30-60s task).
 * Triggered when a WordPress subscription is activated.
 *
 * Event: solwed.provision.wordpress
 * Params: { idsuscripcion, idcontacto, domain, plan }
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Worker;

use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Lib\WordPressProvisioner;

class WordPressProvisionWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        $data = $event->params();

        $idsuscripcion = (int)($data['idsuscripcion'] ?? 0);
        $idcontacto = (int)($data['idcontacto'] ?? 0);
        $domain = $data['domain'] ?? '';
        $plan = $data['plan'] ?? 'starter';

        if ($idsuscripcion <= 0 || $idcontacto <= 0 || empty($domain)) {
            Tools::log('solwed')->warning('WordPressProvisionWorker: missing params');
            return false;
        }

        $suscripcion = new Suscripcion();
        if (!$suscripcion->load($idsuscripcion)) {
            Tools::log('solwed')->warning('WordPressProvisionWorker: subscription not found: ' . $idsuscripcion);
            return false;
        }

        $contacto = new Contacto();
        if (!$contacto->loadFromCode($idcontacto)) {
            Tools::log('solwed')->warning('WordPressProvisionWorker: contact not found: ' . $idcontacto);
            return false;
        }

        Tools::log('solwed')->info("WordPressProvisionWorker: starting for {$domain} (plan: {$plan})");

        $result = WordPressProvisioner::provision($suscripcion, $contacto, $domain, $plan);

        if ($result->isSuccess()) {
            $suscripcion->provisioning_status = Suscripcion::PROV_COMPLETED ?? 'completed';
            $suscripcion->save();
            Tools::log('solwed')->notice("WordPressProvisionWorker: completed for {$domain}");
        } else {
            $suscripcion->provisioning_status = 'failed';
            $suscripcion->notas = ($suscripcion->notas ?? '') . "\nProvisioning failed: " . $result->getError();
            $suscripcion->save();
            Tools::log('solwed')->error("WordPressProvisionWorker: failed for {$domain}: " . $result->getError());
        }

        return $this->done();
    }
}
