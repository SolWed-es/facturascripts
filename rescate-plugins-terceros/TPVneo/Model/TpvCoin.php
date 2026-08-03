<?php
/**
 * Copyright (C) 2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

/**
 *
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TpvCoin extends ModelClass
{
    use ModelTrait;

    /**
     * @var int
     */
    public $idcoin;

    /**
     * @var string
     */
    public $coddivisa;

    /**
     * @var string
     */
    public $name;

    public static function primaryColumn(): string
    {
        return "idcoin";
    }

    public static function tableName(): string
    {
        return "tpvsneo_coins";
    }
}