<?php
/**
 * Copyright (C) 2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Model;

use Closure;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class ReciboCliente
{
    public function delete(): Closure
    {
        return function () {
            $invoice = $this->getInvoice();
            if ($invoice->exists()) {
                $invoice->save();
            }
        };
    }

    public function save(): Closure
    {
        return function () {
            $invoice = $this->getInvoice();
            if ($invoice->exists()) {
                $invoice->save();
            }
        };
    }
}