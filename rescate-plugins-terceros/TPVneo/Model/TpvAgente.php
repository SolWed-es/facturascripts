<?php
/**
 * Copyright (C) 2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Dinamic\Model\Agente;

/**
 *
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TpvAgente extends ModelClass
{
    use ModelTrait;

    /**
     * @var int
     */
    public $idtpvagente;

    /**
     * @var string
     */
    public $codagente;

    /**
     * @var int
     */
    public $idtpv;

    public function getAgente(string $codagente): Agente
    {
        $agenteModel = new Agente();
        $agenteModel->loadFromCode($codagente);
        return $agenteModel;
    }

    public static function primaryColumn(): string
    {
        return "idtpvagente";
    }

    public static function tableName(): string
    {
        return "tpvsneo_agentes";
    }
}