<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Worker para notificar servicios próximos a vencer
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Worker;

use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Lib\ClienteServiciosManager;

/**
 * Worker que notifica a clientes sobre servicios próximos a vencer
 */
class ServiceExpiringWorker extends WorkerClass
{
    /**
     * Ejecuta el worker
     *
     * @param WorkEvent $event
     * @return bool
     */
    public function run(WorkEvent $event): bool
    {
        $data = $event->params();

        // Verificar datos necesarios
        if (empty($data['idcontacto']) || empty($data['idrecurrencia'])) {
            Tools::log('solwed')->warning('ServiceExpiringWorker: Datos incompletos');
            return false;
        }

        $contacto = new Contacto();
        if (!$contacto->loadFromCode($data['idcontacto'])) {
            Tools::log('solwed')->warning('ServiceExpiringWorker: Contacto no encontrado');
            return false;
        }

        // Obtener información del servicio
        $servicioInfo = ClienteServiciosManager::getServicioInfo($data['idrecurrencia']);
        if (empty($servicioInfo)) {
            Tools::log('solwed')->warning('ServiceExpiringWorker: Servicio no encontrado');
            return false;
        }

        // Enviar notificación por email
        return $this->sendExpirationNotice($contacto, $servicioInfo, $data['dias_restantes'] ?? 30);
    }

    /**
     * Envía notificación de expiración próxima
     *
     * @param Contacto $contacto
     * @param array $servicioInfo
     * @param int $diasRestantes
     * @return bool
     */
    private function sendExpirationNotice(Contacto $contacto, array $servicioInfo, int $diasRestantes): bool
    {
        if (empty($contacto->email)) {
            Tools::log('solwed')->warning('ServiceExpiringWorker: Contacto sin email');
            return false;
        }

        $subject = Tools::lang()->trans('service-expiring-subject', [
            '%service%' => $servicioInfo['nombre'],
            '%days%' => $diasRestantes
        ]);

        $body = Tools::lang()->trans('service-expiring-body', [
            '%name%' => $contacto->nombre,
            '%service%' => $servicioInfo['nombre'],
            '%days%' => $diasRestantes,
            '%date%' => $servicioInfo['fecha_renovacion'],
            '%price%' => Tools::money($servicioInfo['precio'])
        ]);

        // Usar el sistema de email de FacturaScripts
        $result = NewMail::create()
            ->to($contacto->email, $contacto->nombre)
            ->subject($subject)
            ->body($body)
            ->send();

        if ($result) {
            Tools::log('solwed')->info(
                'Notificación enviada a ' . $contacto->email .
                ' - Servicio: ' . $servicioInfo['nombre']
            );
        }

        return $result;
    }
}
