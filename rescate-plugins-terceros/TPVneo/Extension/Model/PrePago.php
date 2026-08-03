<?php
/**
 * Copyright (C) 2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Model;

use Closure;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class PrePago
{
    public function delete(): Closure
    {
        return function () {
            $doc = $this->getDocument();
            if (false === empty($doc) && $doc->exists()) {
                $doc->save();
            }
        };
    }

    public function save(): Closure
    {
        return function () {
            $doc = $this->getDocument();
            if (false === empty($doc) && $doc->exists()) {
                $doc->save();
            }
        };
    }
}