<?php
namespace FacturaScripts\Plugins\PagoRecibosRedsys;

use FacturaScripts\Core\Lib\AjaxForms\PurchasesLineHTML;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Core\Tools;

class Init extends \FacturaScripts\Core\Base\InitClass
{
    public function init() {
        $this->loadExtension(new Extension\Controller\ListFacturaCliente());
        $this->loadExtension(new Extension\Controller\ListPresupuestoCliente()); 
        $this->updateEmailNotifications();
    }

    private function updateEmailNotifications(): void
    {
        $body="Buenas, {name}:\n Tiene un pago pendiente de {amount}€, puede pagarlo y ver más información desde el siguiente enlace:\n {url}";

        $notificationModel = new EmailNotification();
        $notificationModel->name = 'notify-payment';
        $notificationModel->body = $body;
        $notificationModel->subject = 'Pago Recibo';
        $notificationModel->enabled = true;
        $notificationModel->save();
    
    }

    public function update() {
    }
}