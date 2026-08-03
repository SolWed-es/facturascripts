<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Core\Base\Calculator;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\TpvCaja;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\Variante;

/**
 *
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class SaleReturn
{
    use TpvTrait;

    public static $lastDocSave;

    public static function getMethodReturn($idDoc, $tpv): string
    {
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        $doc = new $modelClass();

        if (!$doc->loadFromCode($idDoc)) {
            return '';
        }

        $pago = new FormaPago();
        $pago->loadFromCode($doc->codpago);
        return $pago->descripcion;
    }

    public static function loadDocReturn($idDoc, TpvTerminal $tpv): string
    {
        $html = '';
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        $doc = new $modelClass();

        if (!$doc->loadFromCode($idDoc)) {
            return $html;
        }

        $html .= '<div class="text-center h4 mt-3">' . $doc->codigo . '</div>'
            . '<div class="table-responsive">'
            . '<table class="table table-striped mb-0">'
            . '<thead>'
            . '<th>' . Tools::lang()->trans('image') . '</th>'
            . '<th>' . Tools::lang()->trans('product') . '</th>'
            . '<th class="text-center">' . Tools::lang()->trans('quantity') . '</th>'
            . '<th class="text-right">' . Tools::lang()->trans('price') . '</th>'
            . '<th class="text-right">' . Tools::lang()->trans('total') . '</th>'
            . '<th class="text-right text-nowrap">'
            . '<div class="dropdown">'
            . '<button class="btn btn-secondary dropdown-toggle" type="button" id="dropdownMenuButtonReturn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'
            . '<i class="fas fa-check-square fa-fw"></i> '
            . Tools::lang()->trans('qty-return')
            . '</button>'
            . '<div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuButtonReturn">'
            . '<button type="button" class="dropdown-item" onclick=" return selectNoneReturn()">'
            . '<i class="far fa-square fa-fw"></i>'
            . Tools::lang()->trans('select-none')
            . '</button>'
            . '<button type="button" class="dropdown-item" onclick=" return selectAllReturn()">'
            . '<i class="fas fa-square fa-fw"></i>'
            . Tools::lang()->trans('select-all')
            . '</button>'
            . '</div>'
            . '</div>'
            . '</th>'
            . '</thead>'
            . '<tbody>';

        foreach ($doc->getLines() as $line) {
            $refunded = 0;
            if ($tpv->doctype === 'FacturaCliente') {
                $refunded = $line->refundedQuantity();
            }
            $refundedQty = $line->cantidad - $refunded;
            $price = floatval($line->pvpunitario) * (100 + floatval($line->iva)) / 100;
            $total = floatval($line->pvptotal) * (100 + floatval($line->iva)) / 100;
            $descripcion = strlen($line->descripcion) > 97 ? substr($line->descripcion, 0, 120) . '...' : $line->descripcion;
            $disabled = $refundedQty <= 0 ? 'text-muted' : '';
            $html .= '<tr class="' . $disabled . '" pvpunitario="' . $price . '" referencia="' . $line->referencia . '" idlinea="' . $line->idlinea . '">';

            if (false === empty($line->referencia)) {
                $variant = new Variante();
                $where = [new DataBaseWhere('referencia', $line->referencia)];
                if ($variant->loadFromCode('', $where)) {
                    $html .= '<td>' . static::getVariantImage($variant, 'photo-modal') . '</td>';
                }
            } else {
                $html .= '<td></td>';
            }

            $html .= '<td><b>' . $line->referencia . '</b> ' . $descripcion . '</td>';
            $html .= '<td class="text-center align-middle">' . $line->cantidad . '</td>';
            $html .= '<td class="text-right text-nowrap align-middle">' . Tools::money($price, $doc->coddivisa) . '</td>';
            $html .= '<td class="text-right text-nowrap align-middle">' . Tools::money($total, $doc->coddivisa) . '</td>';

            if ($refundedQty > 0) {
                $html .= '<td class="table-success align-middle"><input type="number" class="form-control text-right" min="0" max="' . $refundedQty . '" value="0" /></td>';
            } else {
                $html .= '<td></td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody>'
            . '</table>'
            . '</div>';

        return $html;
    }

    public static function saveReturn(array $formData, User $user, TpvCaja $caja, ?string $codagente): bool
    {
        if ((int)$formData['lines'] === 0) {
            Tools::log()->warning('no-lines-return');
            return false;
        }

        $tpv = $caja->getTerminal();
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        $docOld = new $modelClass();
        if (false === $docOld->loadFromCode($formData['idDocReturn'])) {
            return false;
        }

        return $tpv->doctype === 'FacturaCliente' ?
            static::saveReturnInvoice($docOld, $formData, $user, $tpv, $caja, $codagente) :
            static::saveReturnDeliveryNote($docOld, $formData, $tpv, $caja);
    }

    protected static function saveReturnDeliveryNote(AlbaranCliente $docOld, array $formData, TpvTerminal $tpv, TpvCaja $caja): bool
    {
        $dataBase = new DataBase();
        $dataBase->beginTransaction();

        for ($num = 0; $num < $formData['lines']; $num++) {
            foreach ($docOld->getLines() as $lineOld) {
                if ($lineOld->referencia != $formData['referencia_' . $num]) {
                    continue;
                }
                $lineOld->cantidad = $lineOld->cantidad - $formData['cantidad_' . $num];
                if ($lineOld->save() === false) {
                    $dataBase->rollback();
                    return false;
                }
            }
        }

        // si la forma de pago del terminal y del documento es efectivo
        // actualizamos el efectivo y el cambio
        if ($tpv->codpago === $docOld->codpago) {
            $docOld->tpv_efectivo = $docOld->total;
            $docOld->tpv_cambio = 0.0;
        }

        $lines = $docOld->getLines();
        if (false === Calculator::calculate($docOld, $lines, true)) {
            $dataBase->rollback();
            return false;
        }

        // actualizamos la caja
        $caja->save();

        $dataBase->commit();
        $docOld->code = $docOld->PrimaryColumnValue();
        self::$lastDocSave = $docOld;
        return true;
    }

    protected static function saveReturnInvoice(FacturaCliente $docOld, array $formData, User $user, TpvTerminal $tpv, TpvCaja $caja, ?string $codagente): bool
    {
        $dataBase = new DataBase();
        $dataBase->beginTransaction();

        $user->codagente = $codagente;
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        $doc = new $modelClass();

        $cliente = new Cliente();
        $cliente->loadFromCode($docOld->codcliente);
        $doc->setSubject($cliente);
        $doc->setAuthor($user);

        $doc->codpago = $docOld->codpago;
        $doc->idtpv = $tpv->idtpv;
        $doc->idcaja = $caja->idcaja;
        $doc->editable = 1;
        $doc->tpv_efectivo = 0.0;
        $doc->tpv_cambio = 0.0;
        $doc->tpv_venta = true;

        if ($docOld->editable) {
            foreach ($docOld->getAvailableStatus() as $status) {
                if ($status->editable) {
                    continue;
                }

                $docOld->idestado = $status->idestado;
                if ($docOld->save() === false) {
                    $dataBase->rollback();
                    return false;
                }
            }
        }

        $doc->codigorect = $docOld->codigo;
        $doc->idfacturarect = $docOld->idfactura;
        $doc->codserie = Tools::settings('default', 'codserierec');
        if ($doc->save() === false) {
            $dataBase->rollback();
            return false;
        }

        $linesOld = $docOld->getLines();
        for ($num = 0; $num < $formData['lines']; $num++) {
            if (isset($formData['referencia_' . $num])) {
                $newLine = $doc->getNewProductLine($formData['referencia_' . $num]);
            } else {
                $newLine = $doc->getNewLine();
            }

            $newLine->cantidad = 0 - (float)$formData['cantidad_' . $num];
            $newLine->idlinearect = $formData['idlinea_' . $num];

            foreach ($linesOld as $lineOld) {
                if ((int)$formData['idlinea_' . $num] !== (int)$lineOld->idlinea) {
                    continue;
                }
                $newLine->pvpunitario = $lineOld->pvpunitario;
                $newLine->descripcion = $lineOld->descripcion;
                $newLine->dtopor = $lineOld->dtopor;
                $newLine->codimpuesto = $lineOld->codimpuesto;
                break;
            }

            if ($newLine->save() === false) {
                $dataBase->rollback();
                return false;
            }
        }

        $lines = $doc->getLines();
        Calculator::calculate($doc, $lines, false);
        $doc->tpv_efectivo = $doc->total;
        $doc->idestado = $docOld->idestado;

        // guardamos la factura
        if (false === $doc->save()) {
            $dataBase->rollback();
            return false;
        }

        // marcamos los recibos como pagados
        foreach ($doc->getReceipts() as $recibo) {
            $recibo->pagado = true;
            $recibo->save();
        }

        // actualizamos la factura
        $doc->loadFromCode($doc->primaryColumnValue());

        $dataBase->commit();
        $doc->code = $doc->primaryColumnValue();
        self::$lastDocSave = $doc;
        return true;
    }
}