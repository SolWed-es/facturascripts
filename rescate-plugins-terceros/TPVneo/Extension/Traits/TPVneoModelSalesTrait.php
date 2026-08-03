<?php
/**
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Traits;

use Closure;
use FacturaScripts\Dinamic\Model\TpvCaja;
use FacturaScripts\Dinamic\Model\TpvTerminal;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
trait TPVneoModelSalesTrait
{
    public function getCaja(): Closure
    {
        return function (?int $idcaja = null): TpvCaja {
            $box = new TpvCaja();
            $box->loadFromCode($idcaja ?? $this->idcaja);
            return $box;
        };
    }

    public function getTerminal(): Closure
    {
        return function (): TpvTerminal {
            $box = new TpvTerminal();
            $box->loadFromCode($this->idtpv);
            return $box;
        };
    }

    public function onUpdate(): Closure
    {
        return function () {
            // si no es un albarán o una factura, terminamos
            if (false === in_array($this->modelClassName(), ['AlbaranCliente', 'FacturaCliente'])) {
                return;
            }

            // obtenemos la caja actual
            $box = $this->getCaja();

            // si antes tenía caja y ahora no tiene caja,
            // obtenemos la caja anterior
            if (false === empty($this->previousData['idcaja']) && empty($this->idcaja)) {
                $box = $this->getCaja($this->previousData['idcaja']);
            }

            // si la caja no existe, terminamos
            if (false === $box->exists()) {
                return;
            }

            // guardamos la caja
            $box->save();
        };
    }

    public function setPreviousData(): Closure
    {
        return function () {
            $this->previousData['idcaja'] = $this->idcaja ?? null;
            $this->previousData['idtpv'] = $this->idtpv ?? null;
        };
    }
}
