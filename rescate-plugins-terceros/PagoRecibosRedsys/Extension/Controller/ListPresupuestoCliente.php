<?php

namespace FacturaScripts\Plugins\PagoRecibosRedsys\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\AssetManager;

class ListPresupuestoCliente
{
    public function createViews(): Closure
    {
        return function() {
            AssetManager::addJs(FS_ROUTE.'/Plugins/PagoRecibosRedsys/Assets/JS/modal.js');

            $this->addButton('ListPresupuestoCliente', [
                'action' => 'window.location.href = \'ListSeleccionPresupuestos\';',
                'icon' => 'fas fa-credit-card',
                'label' => 'Pago TPV',
                'type' => 'js',
            ]);
        };
    }
}