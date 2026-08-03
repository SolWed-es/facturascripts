<?php
/**
 * Copyright (C) 2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Model;

use Closure;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class Familia
{
    public function clear(): Closure
    {
        return function () {
            $this->tpv_show = true;
            $this->tpv_sort = 100;
        };
    }
}