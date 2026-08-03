<?php
/**
 * Copyright (C) 2022-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Dinamic\Model\FormaPago;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TpvPago extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $idtpvpago;

    /** @var string */
    public $codpago;

    /** @var int */
    public $idtpv;

    /** @var bool */
    public $paid;

    public function clear()
    {
        parent::clear();
        $this->paid = false;
    }

    public function getMethodPayment(): FormaPago
    {
        $formaPago = new FormaPago();
        $formaPago->loadFromCode($this->codpago);
        return $formaPago;
    }

    public static function primaryColumn(): string
    {
        return "idtpvpago";
    }

    public static function tableName(): string
    {
        return "tpvsneo_pagos";
    }
}