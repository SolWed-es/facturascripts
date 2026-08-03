<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Core\Base\Calculator;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Model\Base\SalesDocumentLine;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\PrePago;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\TpvCaja;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Dinamic\Model\User;

/**
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class ParkForm
{
    /** @var SalesDocument */
    protected static $doc;

    /** @var SalesDocumentLine[] */
    protected static $lines = [];

    public static function getAdvancePayments(int $idpresupuesto): array
    {
        $pr = new PresupuestoCliente();
        if (false === $pr->loadFromCode($idpresupuesto) || false === Plugins::isEnabled('PrePagos')) {
            return [];
        }

        return $pr->getPayments();
    }

    public static function getObservations(): string
    {
        return isset(self::$doc) ? self::$doc->observaciones : '';
    }

    public static function getParks(TpvTerminal $tpv): array
    {
        $modelPresupuesto = new PresupuestoCliente();
        $where = [
            new DataBaseWhere('aparcado', true),
            new DataBaseWhere('idtpv', $tpv->idtpv),
            new DataBaseWhere('finoferta', date('Y-m-d'), '>='),
            new DataBaseWhere('editable', true),
        ];
        return $modelPresupuesto->all($where, ['idpresupuesto' => 'desc']);
    }

    public static function loadPark(int $idpresupuesto, User $user, TpvTerminal $tpv): void
    {
        if (empty($idpresupuesto)) {
            return;
        }

        $pr = new PresupuestoCliente();
        $pr->loadFromCode($idpresupuesto);

        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        self::$doc = new $modelClass();
        $user->codagente = $pr->codagente;
        self::$doc->setAuthor($user);

        $cliente = new Cliente();
        $cliente->loadFromCode($pr->codcliente);
        self::$doc->setSubject($cliente);

        self::$doc->observaciones = $pr->observaciones;
        self::$doc->numero2 = $pr->numero2;
        self::$doc->dtopor1 = $pr->dtopor1;

        foreach ($pr->getLines() as $line) {
            $newLine = empty($line->referencia) ?
                self::$doc->getNewLine() :
                self::$doc->getNewProductLine($line->referencia);

            $newLine->cantidad = (float)$line->cantidad;
            $newLine->dtopor = (float)$line->dtopor;
            $newLine->pvpunitario = (float)$line->pvpunitario;
            $newLine->descripcion = $line->descripcion;
            $newLine->idlinea = $line->idlinea;
            $newLine->codimpuesto = $line->codimpuesto;
            self::$lines[] = $newLine;
        }

        SaleForm::setDoc(self::$doc);
        SaleForm::setLines(self::$lines);
        SaleForm::recalculate();
    }

    public static function renderModalPark(TpvTerminal $tpv, array $parks = []): string
    {
        $html = '';
        $parks = empty($parks) ? self::getParks($tpv) : $parks;
        foreach ($parks as $pr) {
            $html .= '<tr>'
                . '<td class="align-middle">' . $pr->codigo . '</td>'
                . '<td class="align-middle">' . $pr->nombrecliente . '</td>'
                . '<td class="align-middle text-right">' . Tools::money($pr->total, $pr->coddivisa) . '</td>'
                . '<td class="align-middle text-right">' . $pr->fecha . ' ' . $pr->hora . '</td>'
                . '<td class="align-middle">' . $pr->observaciones . '</td>'
                . '<td>'
                . '<button onclick="return loadPark(\'' . $pr->idpresupuesto . '\')" title="' . Tools::lang()->trans('show')
                . '" class="btnLoadPark btn btn-success btn-block btn-spin-action"><i class="fas fa-eye fa-fw"></i></button>'
                . '</td>'
                . '<td class="align-middle text-center">'
                . '<button onclick="return modalDeletePark(\'' . $pr->idpresupuesto . '\', this)" title="' . Tools::lang()->trans('delete')
                . '" class="btnDeletePark btn btn-danger btn-block btn-spin-action"><i class="fas fa-trash-alt fa-fw"></i></button>'
                . '</td>';
        }

        if (empty($html)) {
            $html .= '<tr class="table-warning">'
                . '<td colspan="7">' . Tools::lang()->trans('no-data') . '</td>'
                . '</tr>';
        }

        return $html;
    }

    public static function savePark(array $formData, User $user, TpvCaja $caja, ?string $codagente): bool
    {
        $dataBase = new DataBase();
        $dataBase->beginTransaction();
        $user->codagente = $codagente;
        $updateDoc = false;

        $tpv = $caja->getTerminal();

        $doc = new PresupuestoCliente();
        $cliente = new Cliente();
        $codcliente = empty($formData['codcliente']) ? $tpv->codcliente : $formData['codcliente'];
        $cliente->loadFromCode($codcliente);
        $doc->setSubject($cliente);
        $doc->setAuthor($user);

        // si el presupuesto ya existe, lo cargamos y actualizamos
        if (false === empty($formData['codpark']) && $doc->loadFromCode($formData['codpark'])) {
            $updateDoc = true;
        }

        $doc->aparcado = true;
        $budgetDateEnd = $tpv->budgetdateend;
        $doc->finoferta = date("Y-m-d", strtotime(date("Y-m-d") . "+ " . $budgetDateEnd . " days"));
        $doc->codpago = $tpv->codpago;
        $doc->coddivisa = $tpv->coddivisa;
        $doc->idcaja = $caja->idcaja;
        $doc->idtpv = $caja->idtpv;
        $doc->codserie = $cliente->codserie ?? $tpv->codserie;
        $doc->codalmacen = $tpv->codalmacen;
        $doc->observaciones = $formData['observations'] ?? '';

        if ($doc->save() === false) {
            $dataBase->rollback();
            return false;
        }

        // líneas del carrito
        $linesCart = $formData['linesCart'] ?? 100;
        for ($num = 1; $num <= $linesCart; $num++) {
            if (false === isset($formData['descripcion_' . $num])) {
                continue;
            }

            // creamos la línea
            $line = empty($formData['referencia_' . $num]) ?
                $doc->getNewLine() :
                $doc->getNewProductLine($formData['referencia_' . $num]);

            // si es un presupuesto ya existente, buscamos la línea
            if ($updateDoc) {
                foreach ($doc->getLines() as $docLine) {
                    if ((int)$formData['idlinea_' . $num] === (int)$docLine->idlinea) {
                        $line = $docLine;
                        break;
                    }
                }
            }

            $line->orden = (int)$formData['orden_' . $num];
            $line->cantidad = (float)$formData['cantidad_' . $num];
            $line->descripcion = $formData['descripcion_' . $num];
            $line->codimpuesto = $formData['codimpuesto_' . $num];

            if ($tpv->adddiscount) {
                $line->dtopor = (float)$formData['dtopor_' . $num];
            }

            // si permite cambiar el precio y el nuevo precio no está vacío,
            // o si es una línea nueva y el precio no está vacío
            // calculamos el precio sin iva
            if ($tpv->changeprice && $formData['new_precio_' . $num] != ''
                || empty($formData['referencia_' . $num]) && $formData['new_precio_' . $num] != '') {
                $line->pvpunitario = round((100 * floatval($formData['new_precio_' . $num])) / (100 + floatval($line->iva)), 5);
            } else {
                $line->pvpunitario = (float)$formData['pvpunitario_' . $num];
            }

            if ($line->save() === false) {
                $dataBase->rollback();
                return false;
            }
        }

        $lines = $doc->getLines();
        $doc->dtopor1 = (float)$formData['dtopor_global'] ?? 0.0;
        if (false === Calculator::calculate($doc, $lines, true)) {
            $dataBase->rollback();
            return false;
        }

        // guardamos el PrePago
        if (Plugins::isEnabled('PrePagos') && (float)$formData['advance-payment-amount'] > 0) {
            $modelClass = new PrePago();
            $advancePayment = new $modelClass();
            $advancePayment->amount = (float)$formData['advance-payment-amount'];
            $advancePayment->codcliente = $doc->codcliente;
            $advancePayment->codpago = $formData['advance-payment'] ?? $tpv->codpago;
            $advancePayment->modelid = $doc->primaryColumnValue();
            $advancePayment->modelname = $doc->modelClassName();
            $advancePayment->save();
        }

        $dataBase->commit();
        SaleForm::clearCart($tpv);
        return true;
    }
}