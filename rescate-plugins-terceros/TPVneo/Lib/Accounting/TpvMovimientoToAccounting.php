<?php
/**
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\Accounting;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\ModelCore;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Plugins\TPVneo\Model\TpvMovimiento;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TpvMovimientoToAccounting
{
    public static function generate(TpvMovimiento $movimiento): void
    {
        // si el movimiento ya tiene asiento, no hacemos nada
        if (false === empty($movimiento->idasiento)) {
            return;
        } elseif ($movimiento->amount == 0) {
            // o si el importe es 0
            return;
        }

        $entry = new Asiento();
        $tpv = $movimiento->getTerminal();
        $box = $movimiento->getCaja();
        if (false === static::setAccountingData($entry, $movimiento, $tpv)) {
            return;
        }

        // creamos el concepto del asiento
        $entry->concepto = Tools::lang()->trans('pos-terminal-box-movement', ['%name%' => $tpv->name])
            . ' (' . $box->fechaini
            . ($box->fechafin ? ' - ' . $box->fechafin : '')
            . ')';

        // guardamos el asiento
        if (false === $entry->save()) {
            Tools::log()->warning('accounting-entry-error');
            return;
        }

        // añadimos la partida de caja
        if (static::addCajaLine($entry, $tpv, $movimiento)
            && static::addVentasLine($entry, $movimiento)
            && static::addComprasLine($entry, $movimiento)
            && $entry->isBalanced()) {
            $movimiento->idasiento = $entry->idasiento;
            $movimiento->save();
            return;
        }

        // si fallo algo, borramos el asiento
        Tools::log()->warning('accounting-lines-error');
        $entry->delete();
    }

    protected static function addCajaLine(Asiento $accountEntry, TpvTerminal $tpv, TpvMovimiento $movimiento): bool
    {
        $line = $accountEntry->getNewLine();

        // si la moneda del TPV es EUROS, buscamos la subcuenta 570
        // si no, buscamos la subcuenta 571
        $subaccount = new Subcuenta();
        $exercise = $accountEntry->getExercise();
        $where = [new DataBaseWhere('codejercicio', $accountEntry->codejercicio)];

        if ($tpv->coddivisa === 'EUR') {
            $where[] = new DataBaseWhere('codsubcuenta', str_pad('570',  $exercise->longsubcuenta, "0"));
        } else {
            $where[] = new DataBaseWhere('codsubcuenta', str_pad('571',  $exercise->longsubcuenta, "0"));
        }

        if (false === $subaccount->loadFromCode('', $where)) {
            return false;
        }

        $line->setAccount($subaccount);

        if ($movimiento->amount < 0) {
            $line->haber = abs($movimiento->amount);
        } else {
            $line->debe = $movimiento->amount;
        }

        return $line->save();
    }

    protected static function addComprasLine(Asiento $accountEntry, TpvMovimiento $movimiento): bool
    {
        // si el total del movimiento es positivo, no hacemos nada
        if ($movimiento->amount > 0) {
            return true;
        }

        $line = $accountEntry->getNewLine();

        // buscamos la subcuenta de compras
        $subaccount = new Subcuenta();
        $exercise = $accountEntry->getExercise();
        $where = [
            new DataBaseWhere('codejercicio', $accountEntry->codejercicio),
            new DataBaseWhere('codsubcuenta', str_pad('600',  $exercise->longsubcuenta, "0"))
        ];

        if (false === $subaccount->loadFromCode('', $where)) {
            return false;
        }

        $line->setAccount($subaccount);
        $line->debe = abs($movimiento->amount);
        return $line->save();
    }

    protected static function addVentasLine(Asiento $accountEntry, TpvMovimiento $movimiento): bool
    {
        // si el total del movimiento es negativo, no hacemos nada
        if ($movimiento->amount < 0) {
            return true;
        }

        $line = $accountEntry->getNewLine();

        // buscamos la subcuenta de ventas
        $subaccount = new Subcuenta();
        $exercise = $accountEntry->getExercise();
        $where = [
            new DataBaseWhere('codejercicio', $accountEntry->codejercicio),
            new DataBaseWhere('codsubcuenta', str_pad('700',  $exercise->longsubcuenta, "0"))
        ];

        if (false === $subaccount->loadFromCode('', $where)) {
            return false;
        }

        $line->setAccount($subaccount);
        $line->haber = $movimiento->amount;
        return $line->save();
    }

    protected static function setAccountingData(Asiento &$entry, TpvMovimiento $movimiento, TpvTerminal $tpv): bool
    {
        $ejercicio = new Ejercicio();
        $ejercicio->idempresa = $tpv->idempresa;
        if ($ejercicio->loadFromDate(date(ModelCore::DATE_STYLE, strtotime($movimiento->creationdate)))) {
            $entry->codejercicio = $ejercicio->codejercicio;
            $entry->fecha = date(ModelCore::DATE_STYLE, strtotime($movimiento->creationdate));
            $entry->idempresa = $ejercicio->idempresa;
            $entry->importe = $movimiento->amount;
            return true;
        }

        Tools::log()->warning('accounting-exercise-not-found');
        return false;
    }
}