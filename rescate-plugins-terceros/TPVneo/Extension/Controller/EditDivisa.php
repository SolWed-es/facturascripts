<?php
/**
 * Copyright (C) 2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class EditDivisa
{
    public function createViews(): Closure
    {
        return function () {
            $this->setTabsPosition('bottom');
            $this->createViewsCoin();
        };
    }

    protected function createViewsCoin(): Closure
    {
        return function (string $viewName = 'EditDivisaCoin') {
            $this->addEditListView($viewName, 'TpvCoin', 'coins', 'fas fa-coins');
        };
    }

    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName == 'EditDivisaCoin') {
                $where = [new DataBaseWhere('coddivisa', $this->request->query->get('code'))];
                $view->loadData('', $where);
            }
        };
    }
}