<?php
/**
 * Copyright (C) 2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Extension\Controller;

use Closure;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class ListTicketPrinter
{
    public function createViews(): Closure
    {
        return function () {
            $this->createViewsTPVs();
        };
    }

    protected function createViewsTPVs(): Closure
    {
        return function (string $viewName = 'ListTpvTerminal') {
            $this->addView($viewName, 'TpvTerminal', 'pos-terminals', 'fas fa-cash-register');
            $this->views[$viewName]->searchFields = ['name'];
            $this->views[$viewName]->addOrderBy(['name'], 'name');
        };
    }
}