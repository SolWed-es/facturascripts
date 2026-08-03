<?php
/**
 * Copyright (C) 2022-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Accounting\TpvMovimientoToAccounting;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\TpvCaja;
use FacturaScripts\Dinamic\Model\TpvTerminal;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TpvMovimiento extends ModelClass
{
    use ModelTrait;

    /** @var float */
    public $amount;

    /** @var string */
    public $codagente;

    /** @var string */
    public $creationdate;

    /** @var int */
    public $id;

    /** @var int */
    public $idasiento;

    /** @var int */
    public $idcaja;

    /** @var int */
    public $idtpv;

    /** @var string */
    public $lastnick;

    /** @var string */
    public $lastupdate;

    /** @var string */
    public $motive;

    /** @var string */
    public $nick;

    public function clear()
    {
        parent::clear();
        $this->amount = 0.0;
        $this->creationdate = Tools::dateTime();
        $this->nick = empty(Session::get('user')) ? null : Session::get('user')->nick;
    }

    public function delete(): bool
    {
        if (false === parent::delete()) {
            return false;
        }

        // eliminamos los asientos asociados
        foreach ($this->getAccountings() as $accounting) {
            $accounting->delete();
        }

        return true;
    }

    public function getAccountings(): array
    {
        $entry = new Asiento();
        $where = [new DataBaseWhere('idasiento', $this->idasiento)];
        return $entry->all($where, [], 0, 0);
    }

    public function getCaja(): TpvCaja
    {
        $caja = new TpvCaja();
        $caja->loadFromCode($this->idcaja);
        return $caja;
    }

    public function getTerminal(): TpvTerminal
    {
        $terminal = new TpvTerminal();
        $terminal->loadFromCode($this->idtpv);
        return $terminal;
    }

    public static function primaryColumn(): string
    {
        return "id";
    }

    public function save(): bool
    {
        if (false === parent::save()) {
            return false;
        }

        TpvMovimientoToAccounting::generate($this);
        return true;
    }

    public static function tableName(): string
    {
        return "tpvsneo_movimientos";
    }

    public function test(): bool
    {
        $this->codagente = Tools::noHtml($this->codagente);
        $this->motive = Tools::noHtml($this->motive);
        return parent::test();
    }

    protected function saveUpdate(array $values = []): bool
    {
        $this->lastnick = empty(Session::get('user')) ? null : Session::get('user')->nick;
        $this->lastupdate = Tools::dateTime();

        return parent::saveUpdate($values);
    }
}
