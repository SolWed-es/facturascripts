<?php

namespace FacturaScripts\Plugins\DescargarFacturasZIP\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\AssetManager;

class ListFacturaCliente
{
    public function createViews(): Closure
    {
        return function() {
            $this->addButton('ListFacturaCliente', [
                'action' => '
                    var checkboxes = document.querySelectorAll(\'input.form-check-input.listAction:checked\');
                    var valores = [];
                    checkboxes.forEach(function(checkbox) {
                        valores.push(checkbox.value);
                    });
                    var valoresString = valores.join(\',\');
                    
                    // Verificar si hay algún checkbox seleccionado antes de la redirección
                    if (valores.length > 0) {
                        window.location.href = \'DescargarPDF?ids=\' + valoresString;
                    }
                ',
                'icon' => 'fas fa-file-zipper',
                'label' => 'ZIP',
                'type' => 'js',
            ]);
        };
    }
}