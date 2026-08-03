<?php
/**
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Agentes;
use FacturaScripts\Core\DataSrc\FormasPago;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\TPVneo\Lib\Tickets\BoxClosure;
use FacturaScripts\Plugins\TPVneo\Model\TpvCaja;

class EditTpvCaja extends EditController
{
    public function getModelClassName(): string
    {
        return "TpvCaja";
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data["menu"] = "admin";
        $data["title"] = "box";
        $data["icon"] = "fas fa-box";
        return $data;
    }

    protected function closeBoxAction(): bool
    {
        if (false === $this->validateFormToken()) {
            return true;
        }

        $caja = new TpvCaja();
        if (false === $caja->loadFromCode($this->request->query->get('code'))
            || $caja->fechafin !== null) {
            return true;
        }

        $caja->close(0);
        if (false === $caja->save()) {
            Tools::log()->warning('record-save-error');
            return true;
        }

        Tools::log()->notice('record-updated-correctly');
        return true;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');

        // leemos todas las columnas y las desactivamos
        $mvn = $this->getMainViewName();
        foreach ($this->views[$mvn]->getColumns() as $group) {
            foreach ($group->columns as $key => $item) {
                $this->views[$mvn]->disableColumn($key, false, 'true');
            }
        }

        AssetManager::add('js', '/Dinamic/Assets/JS/iframePrint.js');

        // desactivamos opciones
        $this->setSettings($mvn, 'btnNew', false);
        $this->setSettings($mvn, 'btnSave', false);
        $this->setSettings($mvn, 'btnDelete', false);
        $this->setSettings($mvn, 'btnUndo', false);

        $this->createViewsDocs();
        $this->createViewsMovements();
    }

    protected function createViewsDocs(string $viewName = 'ListTpvDoc'): void
    {
        // cargamos la caja y el terminal
        $caja = new TpvCaja();
        $caja->loadFromCode($this->request->query->get('code'));
        $tpv = $caja->getTerminal();

        // añadimos la pestaña del modelo que corresponda
        $title = $tpv->doctype === 'FacturaCliente' ? 'invoices' : 'delivery-notes';
        $icon = $tpv->doctype === 'FacturaCliente' ? 'fas fa-file-invoice-dollar fa-fw' : 'fas fa-dolly-flatbed';
        $this->addListView($viewName, $tpv->doctype, $title, $icon)
            ->addSearchFields(['codigo', 'nombrecliente', 'numero2', 'observaciones'])
            ->addOrderBy(['fecha', 'hora'], 'date', 2)
            ->addOrderBy(['codigo'], 'code')
            ->addOrderBy(['total'], 'total')
            ->addFilterSelect('codagente', 'agent', 'codagente', Agentes::codeModel())
            ->addFilterSelect('codpago', 'payment-method', 'codpago', FormasPago::codeModel())
            ->setSettings($viewName, 'btnNew', false);
    }

    protected function createViewsMovements(string $viewName = 'ListTpvMovimiento'): void
    {
        $this->addListView($viewName, 'TpvMovimiento', 'movements', 'fas fa-coins')
            ->addSearchFields(['motive'])
            ->addOrderBy(['creationdate'], 'date', 2)
            ->addOrderBy(['amount'], 'amount')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false)
            ->setSettings('clickable', false)
            ->setSettings('checkBoxes', false);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'close-box':
                return $this->closeBoxAction();

            case 'recalculate-box':
                return $this->recalculateBoxAction();

            case 'print-box-closure':
                return $this->printBoxClosureAction();

            default:
                return parent::execPreviousAction($action);
        }
    }

    protected function loadData($viewName, $view)
    {
        $mvn = $this->getMainViewName();

        switch ($viewName) {
            case 'ListTpvDoc':
            case 'ListTpvMovimiento':
                $where = [new DataBaseWhere('idcaja', $this->request->query->get('code'))];
                $view->loadData('', $where);
                break;

            case $mvn:
                parent::loadData($viewName, $view);

                // si la caja no está cerrada y es administrador
                // añadimos el botón cerrar caja
                if (empty($this->views[$viewName]->model->fechafin)
                    && $this->user->admin) {
                    if ($this->user->admin) {
                        $this->addButton($viewName, [
                            'action' => 'close-box',
                            'color' => 'warning',
                            'confirm' => true,
                            'icon' => 'fas fa-lock',
                            'label' => 'close-box'
                        ]);
                    }
                }

                // si la caja está cerrada
                // añadimos el botón para imprimir el cierre de caja
                if (!empty($this->views[$viewName]->model->fechafin)) {
                    $this->addButton($viewName, [
                        'action' => 'print-box-closure',
                        'color' => 'light',
                        'icon' => 'fas fa-print',
                        'id' => 'iframe-print',
                        'label' => 'print-box-closure',
                        'type' => 'action'
                    ]);
                }

                // si es administrador añadimos el botón para recalcular la caja
                if ($this->user->admin) {
                    $this->addButton($viewName, [
                        'action' => 'recalculate-box',
                        'color' => 'info',
                        'icon' => 'fas fa-calculator',
                        'label' => 'recalculate-box'
                    ]);
                }
                break;
        }
    }

    protected function recalculateBoxAction(): bool
    {
        if (false === $this->validateFormToken()) {
            return true;
        }

        $box = new TpvCaja();
        if (false === $box->loadFromCode($this->request->query->get('code'))) {
            return true;
        }

        if ($box->save()) {
            Tools::log()->notice('record-updated-correctly');
        } else {
            Tools::log()->warning('record-save-error');
        }

        return true;
    }

    protected function printBoxClosureAction(): bool
    {
        if (false === $this->validateFormToken()) {
            return true;
        }

        $box = new TpvCaja();
        if (false === $box->loadFromCode($this->request->query->get('code'))
            || empty($box->fechafin)) {
            return true;
        }

        $tpv = $box->getTerminal();
        if (false === $tpv->exists() || empty($tpv->idprinter)) {
            return true;
        }

        BoxClosure::print($box);
        return true;
    }
}
