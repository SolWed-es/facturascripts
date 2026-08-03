<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Model;

use Closure;
use FacturaScripts\Plugins\TPVneo\Extension\Traits\TPVneoModelSalesTrait;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class FacturaCliente
{
    use TPVneoModelSalesTrait;

    /** @var bool */
    public $tpv_venta;

    public function clear(): Closure
    {
        return function () {
            $this->tpv_cambio = 0.0;
            $this->tpv_efectivo = 0.0;
            $this->tpv_venta = false;
        };
    }
}
