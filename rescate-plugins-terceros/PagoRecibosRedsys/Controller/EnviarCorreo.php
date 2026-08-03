<?php

namespace FacturaScripts\Plugins\PagoRecibosRedsys\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController as ParentController;
use FacturaScripts\Core\Model\Settings;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\LineaFacturaCliente;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\Email\MailNotifier;

class EnviarCorreo extends ParentController{
    
    public $facturas;
    public $recibos;
    public $clientes;
    public $pagado;
    public $nopagado;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = '';
        $data['icon'] = '';
        return $data;
    }

    public function publicCore(&$response)
    {
        parent::publicCore($response);
        $this->createViews();
    }

    public function formatoDinero($numero) {
        $numero_formateado = number_format($numero, 2, ',', '.');
        $numero_formateado .= ' €';
    
        return $numero_formateado;
    }

    protected function createViews() {
        $email=explode(',', $_POST['email']);
        $name=explode(',',$_POST['name']);
        if(count($email)<1){
            MailNotifier::send('notify-payment', $_POST['email'], $_POST['name'], [
                'url' => $_POST['url'],
                'name' => $_POST['name'],
                'amount' => $_POST['amount'],
            ]);
            var_dump($_POST['email'], $_POST['name'], $_POST['amount']); 
        } else{
            array_map(function($mail, $user) {
                MailNotifier::send('notify-payment', $mail, $user, [
                    'url' => $_POST['url'],
                    'name' => $user,
                    'amount' => $_POST['amount'],
                ]);
            }, $email, $name);
        }
        MailNotifier::send('notify-payment', $_POST['email'], $_POST['name'], [
            'url' => $_POST['url'],
            'name' => $_POST['name'],
            'amount' => $_POST['amount'],
        ]);
        $this->setTemplate('EnviarCorreo');
    }
    
}