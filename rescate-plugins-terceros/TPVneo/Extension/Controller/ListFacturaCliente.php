<?php
/**
 * Copyright (C) 2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Controller;

use Closure;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class ListFacturaCliente
{
    public function createViews(): Closure
    {
        return function () {
            $tpvs = $this->codeModel->all('tpvsneo', 'idtpv', 'name');
            $this->addFilterSelect('ListFacturaCliente', 'idtpv', 'pos-terminal', 'idtpv', $tpvs);
        };
    }
}