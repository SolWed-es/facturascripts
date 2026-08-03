<?php

namespace FacturaScripts\Plugins\PagoRecibosRedsys\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\AssetManager;

class ListFacturaCliente
{
    public function createViews(): Closure
    {
        return function() {
            AssetManager::addJs(FS_ROUTE.'/Plugins/PagoRecibosRedsys/Assets/JS/modal.js');
            
            $this->addButton('ListReciboCliente', [
                'action' => '
                    $(\'#modalOptions\').modal(\'show\');
                ',
                'icon' => 'fas fa-credit-card',
                'label' => 'Pago TPV',
                'type' => 'js',
            ]);

            $this->addButton('ListFacturaCliente', [
                'action' => '
                    $(\'#modalOptions\').modal(\'show\');
                ',
                'icon' => 'fas fa-credit-card',
                'label' => 'Pago TPV',
                'type' => 'js',
            ]);
        };
    }
}