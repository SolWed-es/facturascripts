<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Agentes;
use FacturaScripts\Core\DataSrc\FormasPago;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Lib\ExtendedController\BaseView;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Plugins\Tickets\Model\TicketPrinter;
use FacturaScripts\Plugins\TPVneo\Lib\Tickets\BoxClosure;
use FacturaScripts\Plugins\TPVneo\Lib\TPVneo\SaleTicket;
use FacturaScripts\Plugins\TPVneo\Model\TpvCaja;
use FacturaScripts\Plugins\TPVneo\Model\TpvTerminal;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class EditTpvTerminal extends EditController
{
    public function getModelClassName(): string
    {
        return 'TpvTerminal';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data["title"] = "pos-terminal";
        $data["icon"] = "fas fa-cash-register";
        return $data;
    }

    protected function closeBoxAction(): bool
    {
        if (false === $this->validateFormToken()) {
            return true;
        }

        $codes = $this->request->request->get('code', []);
        if (empty($codes)) {
            Tools::log()->warning('no-selected-item');
            return true;
        }

        foreach ($codes as $code) {
            $caja = new TpvCaja();
            if (false === $caja->loadFromCode($code) || $caja->fechafin !== null) {
                continue;
            }

            $caja->close(0);
            if (false === $caja->save()) {
                Tools::log()->warning('record-save-error');
                return true;
            }

            Tools::log()->notice('record-updated-correctly');
        }

        return true;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');

        $this->createViewsBoxes();
        $this->createViewsDocs();
        $this->createViewsBudgets();
        $this->createViewsAgents();
        $this->createViewsPayments();
    }

    protected function createViewsAgents(string $viewName = 'EditTpvAgente'): void
    {
        $this->addEditListView($viewName, 'TpvAgente', 'agents', 'fas fa-user-tie')
            ->disableColumn('pos-terminal');
        $this->views[$viewName]->setInLine(true);
    }

    protected function createViewsBoxes(string $viewName = 'ListTpvCaja'): void
    {
        AssetManager::add('js', '/Dinamic/Assets/JS/iframePrint.js');

        $this->addListView($viewName, 'TpvCaja', 'boxes', 'fas fa-box')
            ->addSearchFields(['observaciones'])
            ->addOrderBy(['idcaja'], 'code', 2)
            ->addOrderBy(['fechaini'], 'start-date')
            ->addOrderBy(['fechafin'], 'end-date')
            ->addFilterPeriod('fechaini', 'start-date', 'fechaini')
            ->addFilterPeriod('fechafin', 'end-date', 'fechafin')
            ->addFilterNumber('income', 'income', 'ingresos', '>=')
            ->addFilterCheckbox('box', 'opened', 'fechafin', 'IS', null)
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false)
            ->setSettings('btnPrint', true);

        $this->addButton($viewName, [
            'action' => 'print-box-closure',
            'color' => 'light',
            'icon' => 'fas fa-print',
            'title' => 'print-box-closure',
            'type' => 'action'
        ]);

        // si es administrador, añadimos el botón cerrar caja y recalcular
        if ($this->user->admin) {
            $this->addButton($viewName, [
                'action' => 'close-box',
                'color' => 'warning',
                'confirm' => true,
                'icon' => 'fas fa-lock',
                'title' => 'close-box'
            ]);
            $this->addButton($viewName, [
                'action' => 'recalculate-box',
                'color' => 'info',
                'icon' => 'fas fa-calculator',
                'title' => 'recalculate-box'
            ]);
        }
    }

    protected function createViewsBudgets(string $viewName = 'ListTpvPresupuesto'): void
    {
        $this->addListView($viewName, 'PresupuestoCliente', 'estimations', 'far fa-file-powerpoint')
            ->setSettings($viewName, 'btnNew', false);

        // filtros
        $this->setOptionsFilters($viewName);
    }

    protected function createViewsDocs(string $viewName = 'ListTpvDoc'): void
    {
        // cargamos la pestaña del modelo que corresponda
        $tpv = new TpvTerminal();
        $tpv->loadFromCode($this->request->query->get('code', ''));
        $title = $tpv->doctype === 'FacturaCliente' ? 'invoices' : 'delivery-notes';
        $icon = $tpv->doctype === 'FacturaCliente' ? 'fas fa-file-invoice-dollar fa-fw' : 'fas fa-dolly-flatbed';
        $this->addListView($viewName, $tpv->doctype, $title, $icon)
            ->setSettings($viewName, 'btnNew', false);

        // filtros
        $this->setOptionsFilters($viewName);
    }

    protected function createViewsPayments(string $viewName = 'EditTpvPago'): void
    {
        $this->addEditListView($viewName, 'TpvPago', 'payment-methods', 'fas fa-credit-card')
            ->disableColumn('pos-terminal');
        $this->views[$viewName]->setInLine(true);
    }

    protected function fastTpvConfigAction(): bool
    {
        // si ya hay terminales, no hacemos nada
        $terminal = new TpvTerminal();
        if ($terminal->count() > 0) {
            return true;
        }

        // si no hay clientes, creamos el cliente contado
        $cliente = new Cliente();
        if ($cliente->count() === 0) {
            $cliente->cifnif = '00000000-A';
            $cliente->nombre = 'Contado';
            $cliente->save();
        } else {
            foreach ($cliente->all() as $cli) {
                // seleccionamos el primero que encontremos
                $cliente = $cli;
                break;
            }
        }

        // si no hay una impresora, creamos una
        $impresora = new TicketPrinter();
        if ($impresora->count() === 0) {
            $impresora->name = 'TPV';
            $impresora->nick = $this->user->nick;
            $impresora->save();
        } else {
            foreach ($impresora->all() as $imp) {
                // seleccionamos la primera que encontremos
                $impresora = $imp;
                break;
            }
        }

        // creamos la terminal
        $terminal->codcliente = $cliente->codcliente;
        $terminal->idprinter = $impresora->id;
        $terminal->name = 'Terminal 1';
        $terminal->ticketformat = 'Normal';
        if ($terminal->save()) {
            $this->redirect('TPVneo');
            return true;
        }

        Tools::log()->warning('tpv-terminal-not-created');
        return true;
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'close-box':
                return $this->closeBoxAction();

            case 'fast-config':
                return $this->fastTpvConfigAction();

            case 'recalculate-box':
                return $this->recalculateBoxAction();

            case 'print-box-closure':
                return $this->printBoxClosureAction();

            default:
                return parent::execPreviousAction($action);
        }
    }

    /**
     * @param string $viewName
     * @param BaseView $view
     */
    protected function loadData($viewName, $view)
    {
        $mvn = $this->getMainViewName();
        $idtpv = $this->getViewModelValue($mvn, 'idtpv');

        switch ($viewName) {
            case 'EditTpvAgente':
            case 'EditTpvPago':
                $this->loadMethodPayments($mvn, $view);
            case 'ListTpvCaja':
            case 'ListTpvPresupuesto':
            case 'ListTpvDoc':
                $where = [new DataBaseWhere('idtpv', $idtpv)];
                $view->loadData('', $where);
                break;

            default:
                parent::loadData($viewName, $view);
                $this->loadTicketFormats($view);
                break;
        }
    }

    protected function loadMethodPayments(string $mvn, BaseView $view): void
    {
        $column = $view->columnForName('payment-method');
        if ($column && $column->widget->getType() === 'select') {
            $where = [new DataBaseWhere('idempresa', $this->getViewModelValue($mvn, 'idempresa'))];
            $customValues = $this->codeModel->all('formaspago', 'codpago', 'descripcion', false, $where);
            $column->widget->setValuesFromCodeModel($customValues);
        }
    }

    protected function loadTicketFormats(BaseView $view): void
    {
        $column = $view->columnForName('ticket-format');
        if ($column && $column->widget->getType() === 'select') {
            $customValues = [];
            foreach (SaleTicket::loadFormats($view->model->doctype) as $format) {
                $customValues[] = [
                    'value' => $format['nameFile'],
                    'title' => Tools::lang()->trans(strtolower($format['label']))
                ];
            }
            $column->widget->setValuesFromArray($customValues, false, false);
        }
    }

    protected function recalculateBoxAction()
    {
        if (false === $this->validateFormToken()) {
            return true;
        }

        $codes = $this->request->request->get('code', []);
        if (false === is_array($codes)) {
            return true;
        }

        if (empty($codes)) {
            Tools::log()->warning('no-selected-item');
            return true;
        }

        foreach ($codes as $code) {
            $box = new TpvCaja();
            if ($box->loadFromCode($code)) {
                $box->save();
            }
        }

        Tools::log()->notice('record-updated-correctly');
        return true;
    }

    protected function printBoxClosureAction(): bool
    {
        if (false === $this->validateFormToken()) {
            return true;
        }

        $terminal = $this->getModel();
        if (false === $terminal->loadFromCode($this->request->query->get('code', ''))
            || empty($terminal->idprinter)) {
            return true;
        }

        $codes = $this->request->request->get('code', []);
        if (false === is_array($codes)) {
            return true;
        }

        if (empty($codes)) {
            Tools::log()->warning('no-selected-item');
            return true;
        }

        foreach ($codes as $code) {
            $box = new TpvCaja();
            if (false === $box->loadFromCode($code)) {
                continue;
            } elseif (empty($box->fechafin)) {
                Tools::log()->warning('box-not-closed', ['%date%' => $box->fechaini]);
                continue;
            }

            BoxClosure::print($box);
        }

        return true;
    }

    protected function selectAction(): array
    {
        $data = $this->requestGet(['field', 'fieldcode', 'fieldfilter', 'fieldtitle', 'formname', 'source', 'term']);

        if ($data['field'] !== 'ticketformat' && $data['field'] !== 'payment-method') {
            return parent::selectAction();
        }

        $results = [];
        foreach (SaleTicket::loadFormats($data['term']) as $format) {
            $results[] = ['key' => $format['nameFile'], 'value' => Tools::lang()->trans(strtolower($format['label']))];
        }
        return $results;
    }

    protected function setOptionsFilters($viewName): void
    {
        $this->views[$viewName]->addSearchFields(['codigo', 'nombrecliente', 'numero2', 'observaciones']);
        $this->views[$viewName]->addOrderBy(['fecha', 'hora'], 'date', 2);
        $this->views[$viewName]->addOrderBy(['codigo'], 'code');
        $this->views[$viewName]->addOrderBy(['codagente'], 'agent');
        $this->views[$viewName]->addOrderBy(['nombrecliente'], 'customer');
        $this->views[$viewName]->addOrderBy(['total'], 'total');

        // filtro de fechas
        $this->views[$viewName]->addFilterPeriod('date', 'date', 'fecha');

        // filtros de total
        $this->views[$viewName]->addFilterNumber('min-total', 'total', 'total', '>=');
        $this->views[$viewName]->addFilterNumber('max-total', 'total', 'total', '<=');

        // filtro de agentes
        $this->views[$viewName]->addFilterSelect('codagente', 'agent', 'codagente', Agentes::codeModel());

        // filtro de formas de pago
        $this->views[$viewName]->addFilterSelect('codpago', 'payment-method', 'codpago', FormasPago::codeModel());
    }
}