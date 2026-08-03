<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Dinamic\Model\Variante;

/**
 *
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class VariantList extends TpvList
{
    public static function render(TpvTerminal $tpv, string $codalmacen = ''): string
    {
        parent::renderData($tpv, $codalmacen);
        return static::familyList() . static::variantList($tpv);
    }

    protected static function getVariants(): array
    {
        $dataBase = new DataBase();
        $sql = 'SELECT p.tpvsort, v.referencia, p.descripcion, v.precio, i.iva, COALESCE(s.disponible, 0) as disponible,
                p.nostock, p.observaciones, p.referencia as productref, v.idvariante, v.idproducto'
            . ' FROM variantes as v'
            . ' LEFT JOIN productos as p ON v.idproducto = p.idproducto'
            . ' LEFT JOIN impuestos as i ON p.codimpuesto = i.codimpuesto'
            . ' LEFT JOIN stocks as s ON v.referencia = s.referencia AND s.codalmacen = ' . $dataBase->var2str(self::$codalmacen)
            . ' WHERE p.sevende = true AND p.bloqueado = false';

        if (self::$query) {
            $sql .= " AND (LOWER(v.codbarras) = LOWER(" . $dataBase->var2str(self::$query) . ")";
            $xLike = '';
            foreach (explode(' ', self::$query) as $value) {
                $xLike .= empty($xLike) ? ' OR (' : ' AND ';

                $xLike .= "(LOWER(v.referencia) LIKE LOWER(" . $dataBase->var2str('%' . $value . '%') . ")"
                    . " OR LOWER(p.descripcion) LIKE LOWER(" . $dataBase->var2str('%' . $value . '%') . "))";
            }
            $sql .= empty($xLike) ? ')' : $xLike . '))';
        }

        if (self::$codfamilia != '-1' && self::$codfamilia != '0') {
            $sql .= ' AND codfamilia = ' . $dataBase->var2str(self::$codfamilia);
        }

        $sql .= " ORDER BY p.tpvsort ASC";

        if (self::$limit > 0) {
            return $dataBase->selectLimit($sql, self::$limit);
        }

        $sql .= ';';
        return $dataBase->select($sql);
    }

    protected static function variantInfoModal(TpvTerminal $tpv, array $row, Variante $variant, string $nameModal): string
    {
        if (false === $variant->exists()) {
            return '';
        }

        if (floatval($row['disponible']) > 0 || in_array($row['nostock'], ['1', 't'])) {
            $cssTr = 'table-success';
            $cssBtn = 'btn-success';
        } else {
            $cssTr = 'table-warning';
            $cssBtn = 'btn-warning';
        }

        $qtyPtrecibir = 0;
        if (in_array($row['nostock'], ['1', 't'])) {
            $qtyStock = '∞';
            $qtyPtrecibir = '∞';
        } elseif (floatval($row['disponible']) > 0) {
            $qtyStock = $row['disponible'];
        } else {
            $qtyStock = 0;
        }

        if ($qtyPtrecibir == 0 && in_array($row['nostock'], ['0', 'f'])) {
            $stock = new Stock();
            $whereStock = [new DataBaseWhere('referencia', $variant->referencia)];
            if ($stock->loadFromCode('', $whereStock)) {
                $qtyPtrecibir = $stock->pterecibir;
            }
        }

        $price = floatval($variant->precio) * (100 + floatval($row['iva'])) / 100;
        $html = '<div class="modal fade modalProductInfo" id="' . $nameModal . '" tabindex="-1" aria-labelledby="' . $nameModal . 'Label" aria-hidden="true">'
            . '<div class="modal-dialog modal-lg">'
            . '<div class="modal-content text-left">'
            . '<div class="modal-header">'
            . '<h5 class="modal-title w-100" id="' . $nameModal . 'Label">' . static::getVariantImage($variant, 'photo-modal mr-2') . Tools::lang()->trans('variant') . ' ' . $row['referencia'] . '</h5>'
            . '<button type="button" class="close" data-dismiss="modal" aria-label="' . Tools::lang()->trans('close') . '">'
            . '<span aria-hidden="true">&times;</span>'
            . '</button>'
            . '</div>'
            . '<div class="modal-description px-3 pt-2">'
            . '<strong>' . Tools::lang()->trans('description') . '</strong>'
            . '<p>' . $row['descripcion'] . '</p>'
            . '</div>';

        if ($row['observaciones']) {
            $nameCollapse = 'productCollapse' . $row['idvariante'];
            $html .= '<div class="modal-observations px-3">'
                . '<strong data-toggle="collapse" href="#' . $nameCollapse . '" role="button" aria-expanded="false" aria-controls="' . $nameCollapse . '">'
                . Tools::lang()->trans('observations')
                . '<i class="fas fa-eye fa-xs ml-1"></i>'
                . '</strong>'
                . '<div class="collapse" id="' . $nameCollapse . '">'
                . '<p>' . $row['observaciones'] . '</p>'
                . '</div>'
                . '</div>';
        }

        $attributes = $variant->description(true);
        if ($attributes) {
            $html .= '<div class="modal-attributes px-3 pt-3">'
                . '<strong>' . Tools::lang()->trans('attributes') . '</strong>'
                . '<p>' . $variant->description(true) . '</p>'
                . '</div>';
        }

        $html .= '<div class="table-responsive">'
            . '<table class="table mb-0">'
            . '<thead>'
            . '<tr>'
            . '<th>' . Tools::lang()->trans('available') . '</th>'
            . '<th class="text-right">' . Tools::lang()->trans('pending-reception') . '</th>'
            . '<th class="text-center">' . Tools::lang()->trans('price') . '</th>'
            . '</tr>'
            . '</thead>'
            . '<tr class="' . $cssTr . '">'
            . '<td class="align-middle">' . $qtyStock . '</td>'
            . '<td class="text-right align-middle">' . $qtyPtrecibir . '</td>'
            . '<td class="align-middle text-nowrap"><button class="btn ' . $cssBtn . ' btn-block" onclick="return addProduct(\''
            . $variant->referencia . '\')"><i class="fas fa-shopping-cart mr-1"></i>' . Tools::money($price, $tpv->coddivisa) . '</button></td>'
            . '</tr>'
            . '</table>'
            . '</div>';

        $variantModel = new Variante();
        $where = [
            new DataBaseWhere('idproducto', $row['idproducto']),
            new DataBaseWhere('referencia', $row['referencia'], '!=')
        ];
        $variants = $variantModel->all($where, ['referencia' => 'ASC'], 0, 0);

        if (empty($variants)) {
            $html .= '</div>'
                . '</div>'
                . '</div>';

            return $html;
        }

        $html .= '<strong class="text-center h5 mt-5">' . Tools::lang()->trans('more') . ' ' . strtolower(Tools::lang()->trans('variants')) . '</strong>'
            . '<div class="table-responsive border-top">'
            . '<table class="table mb-0">'
            . '<thead>'
            . '<tr>'
            . '<th>' . Tools::lang()->trans('image') . '</th>'
            . '<th>' . Tools::lang()->trans('variant') . '</th>'
            . '<th>' . Tools::lang()->trans('attributes') . '</th>'
            . '<th class="text-right">' . Tools::lang()->trans('available') . '</th>'
            . '<th class="text-right">' . Tools::lang()->trans('pending-reception') . '</th>'
            . '<th class="text-center">' . Tools::lang()->trans('price') . '</th>'
            . '</tr>'
            . '</thead>';

        foreach ($variants as $variant) {
            $qtyStock = 0;
            $qtyPtrecibir = 0;

            if (in_array($row['nostock'], ['1', 't'])) {
                $qtyStock = '∞';
                $qtyPtrecibir = '∞';
            } else {
                $stock = new Stock();
                $whereStock = [new DataBaseWhere('referencia', $variant->referencia)];
                if ($stock->loadFromCode('', $whereStock)) {
                    $qtyStock = Tools::number($stock->cantidad);
                    $qtyPtrecibir = Tools::number($stock->pterecibir);
                }
            }

            if (floatval($qtyStock) > 0 || in_array($row['nostock'], ['1', 't'])) {
                $cssTr = 'table-success';
                $cssBtn = 'btn-success';
            } else {
                $cssTr = 'table-warning';
                $cssBtn = 'btn-warning';
            }

            $price = floatval($variant->precio) * (100 + floatval($row['iva'])) / 100;
            $html .= '<tr class="' . $cssTr . '">'
                . '<td class="align-middle">' . static::getVariantImage($variant, 'photo-modal') . '</td>'
                . '<td class="align-middle">' . $variant->referencia . '</td>'
                . '<td class="align-middle">' . $variant->description(true) . '</td>'
                . '<td class="text-right align-middle">' . $qtyStock . '</td>'
                . '<td class="text-right align-middle">' . $qtyPtrecibir . '</td>'
                . '<td class="align-middle text-nowrap"><button class="btn ' . $cssBtn . ' btn-block" onclick="return addProduct(\''
                . $variant->referencia . '\')"><i class="fas fa-shopping-cart mr-1"></i>' . Tools::money($price, $tpv->coddivisa) . '</button></td>'
                . '</tr>';
        }

        $html .= '</table>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';

        return $html;
    }

    protected static function variantList(TpvTerminal $tpv): string
    {
        $html = '';

        foreach (static::getVariants() as $row) {
            $variant = new Variante();
            $variant->loadFromCode($row['idvariante']);

            $price = static::getPrice($tpv, floatval($row['precio']), floatval($row['iva']));
            $descripcion = Utils::trueTextBreak($row['descripcion'], 100);

            if (floatval($row['disponible']) > 0 || in_array($row['nostock'], ['1', 't'])) {
                $cssBorder = 'border-success';
                $cssCoin = 'table-success';
            } else {
                $cssBorder = 'border-warning';
                $cssCoin = 'table-warning';
            }

            $nameModal = 'productModal' . $row['idvariante'];
            $html .= '<div class="col-6 col-sm-4 col-md-3 col-xl-2">'
                . '<div class="' . $cssBorder . ' card shadow-sm mb-3 text-center">'
                . '<div class="cursor-pointer add-product" onclick="return addProduct(\'' . $row['referencia'] . '\')">';

            $img = static::getVariantImage($variant, 'photo-default');
            if (false === empty($img)) {
                $html .= '<div class="photo">' . $img . '</div>';
            }

            $html .= '<div class="h5 mt-2 text-primary pl-1 pr-1">' . $row['referencia'] . '</div>';

            if (empty($img)) {
                $html .= '<p class="small mb-0 pl-1 pr-1">' . $descripcion . '</p>';
            }

            $html .= '</div>'
                . '<div class="' . $cssCoin . ' mt-auto">'
                . '<div class="float-left pl-1 text-left">' . Tools::money($price, $tpv->coddivisa) . '</div>'
                . '<a href="#" data-toggle="modal" data-target="#' . $nameModal . '" class="float-right pr-1 text-right">'
                . '+ ' . Tools::lang()->trans('detail') . '</a>'
                . '</div>'
                . '</div>'
                . static::variantInfoModal($tpv, $row, $variant, $nameModal)
                . '</div> ';
        }

        return $html;
    }
}