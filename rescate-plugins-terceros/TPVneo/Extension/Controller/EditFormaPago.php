<?php
/**
 * Copyright (C) 2022-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class EditFormaPago
{
    public function createViews(): Closure
    {
        return function () {
            $this->setTabsPosition('bottom');

            $this->createViewsTpv();
        };
    }

    protected function createViewsTpv(): Closure
    {
        return function (string $viewName = 'EditTpvPago') {
            $this->addEditListView($viewName, 'TpvPago', 'pos-terminal', 'fas fa-cash-register');
            $this->views[$viewName]->setInLine(true);

            // ocultamos la columna payment-method
            $this->views[$viewName]->disableColumn('payment-method');
        };
    }

    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName == 'EditTpvPago') {
                $where = [new DataBaseWhere('codpago', $this->request->query->get('code'))];
                $view->loadData('', $where);
            }
        };
    }
}