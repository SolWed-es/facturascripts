<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Dinamic\Model\Variante;

/**
 *
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class ProductList extends TpvList
{
    public static function render(TpvTerminal $tpv, string $codalmacen = ''): string
    {
        parent::renderData($tpv, $codalmacen);
        return static::familyList() . static::productList($tpv);
    }

    protected static function getProducts(): array
    {
        $dataBase = new DataBase();
        $sql = 'SELECT p.tpvsort, p.descripcion, i.iva, p.nostock, p.observaciones,'
            . ' p.referencia, p.idproducto, i.iva'
            . ' FROM productos as p'
            . ' LEFT JOIN impuestos as i ON p.codimpuesto = i.codimpuesto'
            . ' WHERE p.sevende = true AND p.bloqueado = false';

        if (self::$query) {
            foreach (explode(' ', self::$query) as $value) {
                $sql .= " AND (LOWER(p.referencia) LIKE LOWER(" . $dataBase->var2str('%' . $value . '%') . ")"
                    . " OR LOWER(p.descripcion) LIKE LOWER(" . $dataBase->var2str('%' . $value . '%') . "))";
            }
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

    protected static function getVariants(): array
    {
        $dataBase = new DataBase();
        $sql = 'SELECT p.tpvsort, p.descripcion, i.iva, p.nostock, p.observaciones,'
            . ' p.referencia, p.idproducto, i.iva'
            . ' FROM productos as p'
            . ' INNER JOIN variantes as v ON v.idproducto = p.idproducto'
            . ' LEFT JOIN impuestos as i ON p.codimpuesto = i.codimpuesto'
            . ' WHERE p.sevende = true AND p.bloqueado = false';

        if (self::$query) {
            $sql .= " AND (LOWER(v.codbarras) = LOWER(" . $dataBase->var2str(self::$query) . ")"
                . " OR LOWER(v.referencia) LIKE LOWER(" . $dataBase->var2str('%' . self::$query . '%') . "))";
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

    protected static function productList(TpvTerminal $tpv): string
    {
        $html = '';

        $products = static::getProducts();
        if (empty($products)) {
            $products = static::getVariants();
        }

        foreach ($products as $row) {
            // obtenemos el producto
            $product = new Producto();
            $product->loadFromCode($row['idproducto']);

            // obtenemos todas las variantes del producto
            $variantModel = new Variante();
            $whereVariant = [new DataBaseWhere('idproducto', $row['idproducto'])];
            $variants = $variantModel->all($whereVariant, ['precio' => 'ASC'], 0, 0);

            // pintamos el precio mínimo y máximo
            $price = '';
            if ($variants) {
                $priceMin = static::getPrice($tpv, floatval($variants[0]->precio), floatval($row['iva']));
                $price = Tools::money($priceMin, $tpv->coddivisa);

                if (count($variants) > 1) {
                    $priceMax = static::getPrice($tpv, floatval($variants[count($variants) - 1]->precio), floatval($row['iva']));
                    $price .= ' - ' . Tools::money($priceMax, $tpv->coddivisa);
                }
            }

            $descripcion = Utils::trueTextBreak($row['descripcion'], 100);

            // buscamos si las variantes tienen stock disponible
            $disponible = false;
            foreach ($variants as &$variant) {
                $stock = new Stock();
                $whereStock = [
                    new DataBaseWhere('referencia', $variant->referencia),
                    new DataBaseWhere('codalmacen', self::$codalmacen),
                    new DataBaseWhere('idproducto', $variant->idproducto)
                ];

                if ($stock->loadFromCode('', $whereStock)) {
                    $variant->stock = $stock;
                    if ($stock->disponible > 0) {
                        $disponible = true;
                    }
                    continue;
                }

                $variant->stock = null;
            }

            if ($disponible || in_array($row['nostock'], ['1', 't'])) {
                $cssBorder = 'border-success';
                $cssCoin = 'table-success';
            } else {
                $cssBorder = 'border-warning';
                $cssCoin = 'table-warning';
            }

            $nameModal = 'productModal' . $row['idproducto'];
            $html .= '<div class="col-6 col-sm-4 col-md-3 col-xl-2">'
                . '<div class="' . $cssBorder . ' card shadow-sm mb-3 text-center">'
                . '<div class="cursor-pointer" onclick="$(\'#' . $nameModal . '\').modal(\'show\')">';

            $img = self::getProductImage($product, 'photo-default');
            if (false === empty($img)) {
                $html .= '<div class="photo">' . $img . '</div>';
            }

            $html .= '<div class="h5 mt-2 text-primary pl-1 pr-1">' . $row['referencia'] . '</div>';

            if (empty($img)) {
                $html .= '<p class="small mb-0 pl-1 pr-1">' . $descripcion . '</p>';
            }

            $html .= '</div>'
                . '<div class="' . $cssCoin . ' mt-auto px-1 text-center">' . $price . '</div>'
                . '</div>'
                . self::productInfoModal($tpv, $row, $product, $variants, $nameModal, $disponible)
                . '</div> ';
        }

        return $html;
    }

    protected static function productInfoModal(TpvTerminal $tpv, array $row, Producto $product, array $variants, string $nameModal, bool $disponible): string
    {
        if (empty($variants)) {
            return '';
        }

        $html = '<div class="modal fade modalProductInfo" id="' . $nameModal . '" tabindex="-1" aria-labelledby="' . $nameModal . 'Label" aria-hidden="true">'
            . '<div class="modal-dialog modal-lg">'
            . '<div class="modal-content text-left">'
            . '<div class="modal-header">'
            . '<h5 class="modal-title w-100" id="' . $nameModal . 'Label">'
            . self::getProductImage($product, 'photo-modal mr-2') . Tools::lang()->trans('product') . ' ' . $row['referencia'] . '</h5>'
            . '<button type="button" class="close" data-dismiss="modal" aria-label="' . Tools::lang()->trans('close') . '">'
            . '<span aria-hidden="true">&times;</span>'
            . '</button>'
            . '</div>'
            . '<div class="modal-description px-3 pt-2">'
            . '<strong>' . Tools::lang()->trans('description') . '</strong>'
            . '<p>' . $row['descripcion'] . '</p>'
            . '</div>';

        if ($row['observaciones']) {
            $nameCollapse = 'productCollapse' . $row['idproducto'];
            $html .= '<div class="modal-observations px-3 mb-3">'
                . '<strong data-toggle="collapse" href="#' . $nameCollapse . '" role="button" aria-expanded="false" aria-controls="' . $nameCollapse . '">'
                . Tools::lang()->trans('observations')
                . '<i class="fas fa-eye fa-xs ml-1"></i>'
                . '</strong>'
                . '<div class="collapse" id="' . $nameCollapse . '">'
                . '<p>' . $row['observaciones'] . '</p>'
                . '</div>'
                . '</div>';
        }

        $html .= '<div class="table-responsive border-top">'
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
            } elseif (isset($variant->stock)) {
                $qtyStock = Tools::number($variant->stock->cantidad);
                $qtyPtrecibir = Tools::number($variant->stock->pterecibir);
            }

            if (floatval($qtyStock) > 0 || in_array($row['nostock'], ['1', 't'])) {
                $cssTr = 'table-success';
                $cssBtn = 'btn-success';
            } else {
                $cssTr = 'table-warning';
                $cssBtn = 'btn-warning';
            }

            $price = self::getPrice($tpv, floatval($variant->precio), floatval($row['iva']));

            $html .= '<tr class="' . $cssTr . '">'
                . '<td class="align-middle">' . self::getVariantImage($variant, 'photo-modal') . '</td>'
                . '<td class="align-middle">' . $variant->referencia . '</td>'
                . '<td class="align-middle">' . $variant->description(true) . '</td>'
                . '<td class="text-right align-middle">' . $qtyStock . '</td>'
                . '<td class="text-right align-middle">' . $qtyPtrecibir . '</td>'
                . '<td class="align-middle text-nowrap"><button class="btn ' . $cssBtn . ' btn-block btn-spin-action" onclick="return addProduct(\''
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
}