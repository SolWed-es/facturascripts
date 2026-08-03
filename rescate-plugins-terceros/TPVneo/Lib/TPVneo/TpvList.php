<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Familia;
use FacturaScripts\Dinamic\Model\TpvTerminal;

/**
 *
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TpvList
{
    use TpvTrait;

    /** @var string */
    protected static $codalmacen = '';

    /** @var string */
    protected static $codfamilia = '-1';

    /** @var int */
    protected static $limit = 0;

    /** @var string */
    protected static $orden = '';

    /** @var string */
    protected static $query = '';

    public static function apply(array $formData)
    {
        self::$codalmacen = $formData['codalmacen'] ?? Tools::settings('default', 'codalmacen');
        self::$codfamilia = $formData['codfamilia'];
        self::$limit = (int)$formData['productlimit'];
        self::$query = $formData['query'];
    }

    protected static function familyList(): string
    {
        $html = '';
        $familyModel = new Familia();
        $orderBy = ['tpv_sort' => 'ASC', 'descripcion' => 'ASC'];

        // si no tenemos familias padres para mostrar, no mostramos nada
        $whereFamiliesParent = [
            new DataBaseWhere('madre', null, 'IS'),
            new DataBaseWhere('tpv_show', true),
        ];
        if ($familyModel->count($whereFamiliesParent) == 0) {
            return $html;
        }

        // mostramos la familia de inicio
        if (self::$codfamilia == '-1') {
            $html .= '<div class="col-6 col-sm-4 col-md-3 col-xl-2">'
                . '<div class="card shadow-sm mb-3 cursor-pointer text-center d-flex flex-column" onclick="return showFamily(\'0\')">'
                . '<div class="text-info mt-3"><i class="far fa-folder fa-fw fa-4x"></i></div>'
                . '<div class="card-footer p-0 mt-auto">' . Tools::lang()->trans('families') . '</div>'
                . '</div>'
                . '</div> ';
        }

        // si tenemos alguna búsqueda, mostramos el botón de inicio y terminamos
        if (self::$query) {
            return $html;
        }

        // mostramos el botón para volver al inicio
        if (self::$codfamilia != '-1') {
            $html .= '<div class="col-6 col-sm-4 col-md-3 col-xl-2">'
                . '<div class="card shadow-sm mb-3 cursor-pointer text-center d-flex flex-column" onclick="return showFamily(\'-1\')">'
                . '<div class="text-danger mt-3"><i class="fas fa-backspace fa-fw fa-4x"></i></div>'
                . '<div class="card-footer p-0 mt-auto">' . Tools::lang()->trans('start') . '</div>'
                . '</div>'
                . '</div> ';
        }

        // mostramos las familias padres
        if (self::$codfamilia != '-1' && self::$codfamilia == '0') {
            foreach ($familyModel->all($whereFamiliesParent, $orderBy, 0, 0) as $family) {
                $html .= '<div class="col-6 col-sm-4 col-md-3 col-xl-2">'
                    . '<div class="card shadow-sm mb-3 cursor-pointer text-center d-flex flex-column" onclick="return showFamily(\'' . $family->codfamilia . '\')">'
                    . '<div class="text-info mt-3"><i class="far fa-folder fa-fw fa-4x"></i></div>'
                    . '<div class="card-footer p-0 mt-auto">' . $family->descripcion . '</div>'
                    . '</div>'
                    . '</div> ';
            }
        }

        // mostramos las familias hijas cuando estamos dentro de otra familia
        if (self::$codfamilia != '-1' && self::$codfamilia != '0') {
            // mostramos el botón para volver a la familia padre
            $familyModel->loadFromCode(self::$codfamilia);
            $parentFamily = ($familyModel->madre == '') ? 0 : $familyModel->madre;
            $html .= '<div class="col-6 col-sm-4 col-md-3 col-xl-2">'
                . '<div class="card shadow-sm mb-3 cursor-pointer text-center d-flex flex-column" onclick="return showFamily(\'' . $parentFamily . '\')">'
                . '<div class="text-danger mt-3"><i class="fas fa-backspace fa-fw fa-4x"></i></div>'
                . '<div class="card-footer p-0 text-center mt-auto">' . $familyModel->descripcion . '</div>'
                . '</div>'
                . '</div> ';

            // mostramos las familias hijas que tenga está familia
            $where = [
                new DataBaseWhere('madre', self::$codfamilia),
                new DataBaseWhere('tpv_show', true),
            ];
            foreach ($familyModel->all($where, $orderBy, 0, 0) as $family) {
                $html .= '<div class="col-6 col-sm-4 col-md-3 col-xl-2">'
                    . '<div class="card shadow-sm mb-3 cursor-pointer text-center d-flex flex-column" onclick="return showFamily(\'' . $family->codfamilia . '\')">'
                    . '<div class="text-info mt-3"><i class="far fa-folder fa-fw fa-4x"></i></div>'
                    . '<div class="card-footer p-0 mt-auto">' . $family->descripcion . '</div>'
                    . '</div>'
                    . '</div> ';
            }
        }

        return $html;
    }

    protected static function getPrice(TpvTerminal $tpv, float $price, float $iva): float
    {
        return $tpv->price_tax_include
            ? $price * (100 + $iva) / 100
            : $price;
    }

    protected static function renderData(TpvTerminal $tpv, string $codalmacen = ''): void
    {
        if ($codalmacen !== '') {
            self::$codalmacen = $codalmacen;
        }
        self::$limit = $tpv->productlimit;
    }
}