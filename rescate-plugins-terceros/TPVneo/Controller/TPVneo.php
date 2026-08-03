<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\ExtensionsTrait;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\DataSrc\Impuestos;
use FacturaScripts\Core\DataSrc\Series;
use FacturaScripts\Core\KernelException;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Tickets\BoxClosure;
use FacturaScripts\Dinamic\Lib\Tickets\PreTicket;
use FacturaScripts\Dinamic\Lib\TPVneo\ParkForm;
use FacturaScripts\Dinamic\Lib\TPVneo\ProductList;
use FacturaScripts\Dinamic\Lib\TPVneo\SaleEmail;
use FacturaScripts\Dinamic\Lib\TPVneo\SaleForm;
use FacturaScripts\Dinamic\Lib\TPVneo\SaleReturn;
use FacturaScripts\Dinamic\Lib\TPVneo\SaleTicket;
use FacturaScripts\Dinamic\Model\Agente;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\CodeModel;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\Divisa;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\TicketPrinter;
use FacturaScripts\Dinamic\Model\TpvAgente;
use FacturaScripts\Dinamic\Model\TpvCaja;
use FacturaScripts\Dinamic\Model\TpvCoin;
use FacturaScripts\Dinamic\Model\TpvMovimiento;
use FacturaScripts\Dinamic\Model\TpvPago;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Dinamic\Model\User;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TPVneo extends Controller
{
    use ExtensionsTrait;

    /** @var Agente */
    public $agente;

    /** @var TpvCaja */
    public $caja;

    /** @var string */
    public $cookieCodagente;

    /** @var bool */
    public $prepagos = false;

    /** @var TpvTerminal */
    public $tpv;

    /** @var TpvTerminal[] */
    public $tpvs = [];

    /** @var TpvAgente[] */
    public $tpvAgents = [];

    /** @var string[] */
    private $logLevels = ['critical', 'error', 'info', 'notice', 'warning'];

    public function getAmount(): string
    {
        return SaleForm::amount($this->tpv);
    }

    public function getCoinTypes(): array
    {
        $modelCoin = new TpvCoin();
        $where = [new DataBaseWhere('coddivisa', $this->tpv->coddivisa)];
        return $modelCoin->all($where, ['name' => 'asc'], 0, 0);
    }

    public function getDivisa(): Divisa
    {
        $divisa = new Divisa();
        $divisa->loadFromCode($this->tpv->coddivisa);
        return $divisa;
    }

    public function getFormatMoney(float $money): string
    {
        return Tools::money($money, $this->getDivisa()->coddivisa);
    }

    public function getNameClient(): string
    {
        $cliente = new Cliente();
        $cliente->loadFromCode($this->tpv->codcliente);
        return $cliente->codcliente . ' | ' . $cliente->nombre;
    }

    public function getPageData(): array
    {
        $pageData = parent::getPageData();
        $pageData["title"] = "TPVneo";
        $pageData["menu"] = "sales";
        $pageData["icon"] = "fas fa-cash-register";
        return $pageData;
    }

    public function getParkCount(): ?int
    {
        return count(ParkForm::getParks($this->tpv));
    }

    public function getPaymentMethodsTpv(): array
    {
        $payments[$this->tpv->codpago] = $this->tpv->getMethodPayment();

        $tpvPagos = new TpvPago();
        $where = [new DataBaseWhere('idtpv', $this->tpv->idtpv)];
        $tpvPayments = $tpvPagos->all($where, [], 0, 0);
        foreach ($tpvPayments as $tpvPayment) {
            if (false === isset($payments[$tpvPayment->codpago])) {
                $payments[$tpvPayment->codpago] = $tpvPayment->getMethodPayment();
            }
        }

        if (false === empty($tpvPayments)) {
            return $payments;
        }

        $pagosModel = new FormaPago();
        $where2 = [new DataBaseWhere('idempresa', $this->tpv->idempresa)];
        foreach ($pagosModel->all($where2, [], 0, 0) as $formaPago) {
            if (false === isset($payments[$formaPago->codpago])) {
                $payments[$formaPago->codpago] = $formaPago;
            }
        }

        return $payments;
    }

    public function getPaymentMethodsModalCollectMoneyHtml(): string
    {
        $html = '';
        foreach ($this->getPaymentMethodsTpv() as $formaPago) {
            $html .= '<div class="payment input-group input-group-lg mb-3">'
                . '<div class="input-group-prepend">'
                . '<span class="input-group-text">' . $formaPago->descripcion . '</span>'
                . '</div>'
                . '<input codpago="' . $formaPago->codpago . '" type="number" max="" class="text-center form-control">';

            $html .= '<div class="input-group-append">'
                . '<button class="btn btn-outline-secondary px-1 setMaxPayment" type="button">'
                . Tools::lang()->trans('max-abr') . '</button>'
                . '</div>';

            $html .= '</div>';
        }

        return $html;
    }

    public function getSelectValues($table, $code, $description, $empty = false): array
    {
        $values = $empty ? ['' => '------'] : [];
        foreach (CodeModel::all($table, $code, $description, $empty) as $row) {
            $values[$row->code] = $row->description;
        }
        return $values;
    }

    public function getSeries(): array
    {
        // excluímos las rectificativas
        return array_filter(Series::all(), function ($serie) {
            return $serie->tipo != 'R';
        });
    }

    public function getTaxes(): array
    {
        return Impuestos::all();
    }

    /**
     * @param Response $response
     * @param User $user
     * @param ControllerPermissions $permissions
     * @throws KernelException
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->caja = new TpvCaja();
        $this->tpv = new TpvTerminal();

        // preguntamos si existe el plugin PrePagos
        $this->prepagos = Plugins::isEnabled('PrePagos');

        // obtenemos el agente de la cookie si hay sesión iniciada del agente
        $this->cookieCodagente = $this->request->cookies->get('tpvneoCodagente', '');
        $this->agente = new Agente();
        if ('' !== $this->cookieCodagente) {
            $this->agente->loadFromCode($this->cookieCodagente);
        }

        // si la petición es por ajax (solo desde el tpv iniciado)
        if ($this->request->get('ajax', false) && false === $this->checkAjax()) {
            $this->setTemplate(false);
            $content = ['refresh' => true];
            $this->response->setContent(json_encode($content));
            return;
        }

        $action = $this->request->request->get('action');
        if ($action === 'agent-auth') {
            // iniciar sesión del agente cuando sea necesario
            $this->agentAuthAction();
            return;
        } elseif ($action === 'agent-out') {
            // cerrar sesión del agente
            $this->agentOutAction();
            return;
        }

        // preguntamos si hay caja abierta
        if (false === $this->loadCaja()) {

            // si el tpv no tiene caja abierta, recargamos la página
            // esto es necesario cuando tenemos el tpv abierto
            // cerramos la caja desde otro sitio e intentamos operar en el tpv
            if ($this->request->get('ajax', false)) {
                $this->agentOutAction(true);
                $this->setTemplate(false);
                $content = ['refresh' => true];
                $this->response->setContent(json_encode($content));
                return;
            }

            switch ($action) {
                case 'select-tpv':
                    // seleccionamos el tpv
                    $this->selectTpvAction();
                    return;

                case 'starting-money':
                    // obtenemos el dinero inicial de la caja
                    $this->startingMoneyAction();
                    return;
            }

            // cargamos todos los terminales cuando no se seleccionó ningún tpv
            $this->loadTpvs();
            return;
        }

        // cargamos el tpv de la caja abierta y comprobamos que el tpv este activo
        if (false === $this->tpv->loadFromCode($this->caja->idtpv) || false === $this->tpv->active) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        $this->loadTpvAgents();

        // si el tpv tiene agentes pero no hay agente iniciado > login
        if (count($this->tpvAgents) > 0 && '' === $this->cookieCodagente) {
            $this->setTemplate('TPVneo/login');
            return;
        }

        // si el tpv tiene agentes, pero tengo login sobre un agente que ya no existe en el tpv > login
        $foundAgente = false;
        foreach ($this->tpvAgents as $agent) {
            if ($this->cookieCodagente == $agent->codagente) {
                $foundAgente = true;
            }
        }

        if (count($this->tpvAgents) > 0 && $foundAgente === false) {
            $this->setTemplate('TPVneo/login');
            return;
        }

        // correcto cargamos el tpv
        $this->setTemplate('TPVneo/index');

        switch ($action) {
            case 'add-barcode':
            case 'add-product':
            case 'new-line':
            case 'recalculate':
            case 'rm-line':
                $this->recalculateAction(true);
                break;

            case 'add-customer':
                $this->addCustomerAction();
                break;

            case 'autocomplete-customer':
                $this->autocompleteCustomerAction();
                return;

            case 'autocomplete-search-doc':
                $this->autocompleteSearchDocAction();
                return;

            case 'box-movement':
                $this->boxMovement();
                break;

            case 'clear-cart':
                $this->clearCart();
                break;

            case 'close-box':
                $this->closeBoxAction();
                break;

            case 'close-box-start':
                $this->closeBoxStartAction();
                break;

            case 'delete-park':
                $this->deletePark();
                break;

            case 'find-products':
                $this->findProductsAction();
                break;

            case 'load-doc-print':
                $this->loadDocPrint();
                break;

            case 'load-doc-return':
                $this->loadDocReturn();
                break;

            case 'load-park':
                $this->loadPark();
                break;

            case 'modal-list-park':
                $this->getModalListPark();
                break;

            case 'modal-tickets':
                $this->getModalTickets();
                break;

            case 'open-drawer':
                $this->openDrawer();
                break;

            case 'pre-print-ticket':
                $this->prePrintTicket();
                break;

            case 'print-ticket':
                $this->printTicket();
                break;

            case 'recalculate-line':
                $this->recalculateAction(false);
                break;

            case 'save-cart':
                $this->saveCart();
                break;

            case 'save-park':
                $this->saveParkAction();
                break;

            case 'save-return':
                $this->saveReturn();
                break;

            case 'send-doc':
                $this->sendDoc();
                break;
        }
    }

    public function renderProductList(string $codalmacen): string
    {
        $className = '\\FacturaScripts\\Dinamic\\Lib\\TPVneo\\' . $this->tpv->listtype . 'List';
        if (false === class_exists($className)) {
            $className = '\\FacturaScripts\\Dinamic\\Lib\\TPVneo\\VariantList';
        }

        $listClass = new $className();
        return $listClass::render($this->tpv, $codalmacen);
    }

    public function renderSaleForm(): string
    {
        return SaleForm::render($this->tpv);
    }

    protected function addCustomerAction(): void
    {
        $this->setTemplate(false);

        $newContact = new Contacto();
        $newContact->verificado = 1;
        $newContact->nombre = $this->request->request->get('empresa');
        $newContact->cifnif = $this->request->request->get('cifnif');
        $newContact->direccion = $this->request->request->get('direccion');
        $newContact->apartado = $this->request->request->get('apartado');
        $newContact->codpostal = $this->request->request->get('codpostal');
        $newContact->ciudad = $this->request->request->get('ciudad');
        $newContact->provincia = $this->request->request->get('provincia');
        $newContact->telefono1 = $this->request->request->get('telefono1');
        $newContact->email = $this->request->request->get('email');
        $newContact->personafisica = $this->request->request->get('personafisica');
        $newContact->tipoidfiscal = $this->request->request->get('tipoidfiscal');
        $newContact->codpais = $this->request->request->get('codpais');
        $newContact->save();

        $customer = $newContact->getCustomer();
        if ($customer->exists() === false) {
            Tools::log()->error('record-save-error');
        }

        $content = [
            'customer' => $customer,
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function agentAuthAction(): void
    {
        if (false === $this->validateFormToken()) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        // comprobamos que existe el tpv
        $idtpv = $this->request->request->get('idtpv', '');
        if (empty($idtpv) || false === $this->tpv->loadFromCode($idtpv)) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        // comprobamos que el tpv sigue activo
        if (false === $this->tpv->active) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        // comprobamos que existe el agente
        $codagente = $this->request->request->get('codagente');
        if (empty($codagente) || false === $this->agente->loadFromCode($codagente)) {
            $this->loadTpvAgents();
            $this->setTemplate('TPVneo/login');
            return;
        }

        // comprobamos la contraseña del agente
        $passwd = $this->request->request->get('passwd');
        if ($passwd !== $this->agente->passwd && $this->agente->passwd !== null) {
            $this->loadTpvAgents();
            Tools::log()->warning('login-password-fail');
            $this->setTemplate('TPVneo/login');
            return;
        }

        // creamos la cookie del agente que inicio sesión en el tpv
        $expire = time() + FS_COOKIES_EXPIRE;
        $this->response->headers->setCookie(
            Cookie::create('tpvneoCodagente', $this->agente->codagente, $expire, FS_ROUTE)
        );
        $this->response->headers->setCookie(
            Cookie::create('tpvneoPasswd', sha1($passwd), $expire, FS_ROUTE)
        );
        $this->cookieCodagente = $this->agente->codagente;

        if (false === $this->tpv->isOpen()) {
            // si el tpv no está abierto mostramos la vista de abrir caja
            $this->setTemplate('TPVneo/start-tpv');
            return;
        }

        $this->loadTpvAgents();

        // si el tpv está abierto cargamos esa caja
        $where = [
            new DataBaseWhere('idtpv', $this->tpv->idtpv),
            new DataBaseWhere('fechafin', null)
        ];
        $this->caja->loadFromCode('', $where);
        $this->setTemplate('TPVneo/index');
    }

    protected function agentOutAction($ajax = false): void
    {
        $this->deleteCookieAgent();

        // solo ejecutamos si la llamada viene por ajax desde el tpv abierto
        if ($ajax) {
            return;
        }

        // comprobamos que existe el tpv
        $idtpv = $this->request->request->get('idtpv', '');
        if (empty($idtpv) || false === $this->tpv->loadFromCode($idtpv)) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        $this->loadTpvAgents();
        if (count($this->tpvAgents) > 0) {
            // si el tpv tiene agentes asignados mostramos la vista de login
            $this->setTemplate('TPVneo/login');
        } else {
            // si no tiene agentes asignados mostramos la vista de terminales
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
        }
    }

    protected function autocompleteCustomerAction(): void
    {
        $this->setTemplate(false);

        $list = [];
        $customerModel = new Cliente();
        $query = $this->request->get('query');
        $fields = 'cifnif|codcliente|email|nombre|observaciones|razonsocial|telefono1|telefono2';

        $where = [
            new DataBaseWhere($fields, mb_strtolower($query, 'UTF8'), 'LIKE'),
            new DataBaseWhere('fechabaja', null, 'IS'),
        ];

        foreach ($customerModel->all($where, [], 0, 0) as $customer) {
            $list[] = [
                'key' => $customer->codcliente,
                'value' => $customer->nombre,
                'serie' => $customer->codserie,
            ];
        }

        if (empty($list)) {
            $list[] = ['key' => null, 'value' => Tools::lang()->trans('no-data')];
        }

        $this->response->setContent(json_encode($list));
    }

    protected function autocompleteSearchDocAction(): void
    {
        $this->setTemplate(false);

        $list = [];
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $this->tpv->doctype;
        $doc = new $modelClass();
        $query = $this->request->get('query');

        foreach ($doc->codeModelSearch($query) as $value) {
            $list[] = [
                'key' => Utils::fixHtml($value->code),
                'value' => Utils::fixHtml($value->description)
            ];
        }

        if (empty($list)) {
            $list[] = ['key' => null, 'value' => Tools::lang()->trans('no-data')];
        }

        $this->response->setContent(json_encode($list));
    }

    protected function boxMovement(): void
    {
        $this->setTemplate(false);

        // añadimos el movimiento de caja
        $boxMovement = new TpvMovimiento();
        $boxMovement->idtpv = $this->tpv->idtpv;
        $boxMovement->idcaja = $this->caja->idcaja;
        $boxMovement->amount = (float)$this->request->get('quantity');
        $boxMovement->motive = $this->request->get('motive');

        // si el tipo de movimiento es OUT ponemos el importe en negativo
        $type = $this->request->get('type');
        $boxMovement->amount = ($type === 'OUT') ? $boxMovement->amount * -1 : $boxMovement->amount;

        if ($this->agente) {
            $boxMovement->codagente = $this->agente->codagente;
        }

        $result = false;
        if ($boxMovement->save() && $this->caja->save()) {
            $result = true;
        } else {
            Tools::log()->error('record-save-error');
        }

        $content = [
            'boxMovement' => $result,
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function checkAjax(): bool
    {
        // comprobamos que el tpv existe
        if (false === $this->tpv->loadFromCode($this->request->request->get('idtpv'))) {
            $this->agentOutAction(true);
            return false;
        }

        // comprobamos que el tpv sigue activo
        if (false === $this->tpv->active) {
            $this->agentOutAction(true);
            return false;
        }

        // comprobamos si el tpv tiene agentes, pero no hemos iniciado sesión con un agente
        if ('' === $this->cookieCodagente) {
            $tpvAgente = new TpvAgente();
            $where = [new DataBaseWhere('idtpv', $this->tpv->idtpv)];
            if ($tpvAgente->count($where) > 0) {
                $this->agentOutAction(true);
                return false;
            }
        }

        // preguntamos si hay agente con la sesión iniciada
        // entonces buscamos que ese agente siga teniendo permiso para acceder al tpv
        if ('' !== $this->cookieCodagente) {
            $tpvAgente = new TpvAgente();
            $where = [new DataBaseWhere('codagente', $this->cookieCodagente)];
            if (false === $tpvAgente->loadFromCode('', $where)) {
                $this->agentOutAction(true);
                return false;
            }
        }

        return true;
    }

    protected function clearCart(): void
    {
        $this->setTemplate(false);
        SaleForm::clearCart($this->tpv);
        $content = [
            'clearcart' => true,
            'amount' => SaleForm::amount($this->tpv),
            'cart' => SaleForm::render($this->tpv),
            'totalCart' => SaleForm::totalCart(),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function closeBoxAction(): void
    {
        // comprobamos el token
        if (false === $this->validateFormToken()) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        $coinsTypes = $this->getCoinTypes();
        $data = $this->request->request->all();

        $finalAmount = 0;
        foreach ($coinsTypes as $coin) {
            $finalAmount += $coin->name * $data[str_replace('.', '_', $coin->name)];
        }

        $this->caja->close($finalAmount);
        $this->caja->observaciones = $this->request->request->get('observaciones', '');

        if ($this->caja->save()) {
            BoxClosure::print($this->caja);
            Tools::log()->notice('box-closed-ok');
            $this->agentOutAction();

            $this->response->headers->setCookie(
                Cookie::create('idcaja', '', 0, FS_ROUTE)
            );
        }
    }

    protected function closeBoxStartAction(): void
    {
        $this->setTemplate('TPVneo/close-box-start');
    }

    protected function deleteCookieAgent(): void
    {
        setcookie('tpvneoCodagente', '', time() - 3600, Tools::config('route', '/'));
        setcookie('tpvneoPasswd', '', time() - 3600, Tools::config('route', '/'));
    }

    protected function deletePark(): void
    {
        $this->setTemplate(false);
        $pr = new PresupuestoCliente();

        if ($pr->loadFromCode($this->request->request->get('codpark', ''))) {
            $pr->delete();
        }

        $content = [
            'messages' => Tools::log()->read('master', $this->logLevels),
            'totalParks' => count(ParkForm::getParks($this->tpv)),
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function findProductsAction(): void
    {
        $this->setTemplate(false);
        $formData = $this->request->request->all();

        $className = '\\FacturaScripts\\Dinamic\\Lib\\TPVneo\\' . $this->tpv->listtype . 'List';
        if (false === class_exists($className)) {
            $className = '\\FacturaScripts\\Dinamic\\Lib\\TPVneo\\VariantList';
        }
        $libClass = new $className();

        $libClass::apply($formData);
        $content = [
            'products' => $libClass::render($this->tpv),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function getConnectedPrinter(): void
    {
        // obtenemos la impresora del tpv
        $printer = $this->tpv->getPrinter();

        // si no existe, devolvemos false
        if (false === $printer->exists()) {
            return;
        }

        // si la última conexión fué hace más de 15 minutos, devolvemos false
        if (time() - strtotime($printer->lastactivity) > 900) {
            Tools::log()->warning('printer-not-connected');
        }
    }

    protected function getModalListPark(): void
    {
        $this->setTemplate(false);
        $parks = ParkForm::getParks($this->tpv);
        $content = [
            'totalParks' => count($parks),
            'presupuestos' => ParkForm::renderModalPark($this->tpv, $parks),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function getModalTickets(): void
    {
        $this->setTemplate(false);
        $content = [
            'tickets' => SaleForm::renderModalTickets($this->caja, $this->request->get('codigo', '')),
            'boxEstimated' => $this->getFormatMoney($this->caja->ingresos),
            'boxTotal' => $this->getFormatMoney($this->caja->totalcaja),
            'boxTotalTickets' => $this->getFormatMoney($this->caja->totaltickets),
            'boxTotalMovements' => $this->getFormatMoney($this->caja->totalmovi),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    /**
     * @return bool
     */
    protected function loadCaja(): bool
    {
        $where = [new DataBaseWhere('fechafin', null)];
        if ('' !== $this->cookieCodagente) {
            $where[] = new DataBaseWhere('idtpv', $this->request->request->get('idtpv', 0));
        } else {
            $where[] = new DataBaseWhere('idcaja', $this->request->request->get('idcaja', 0));
        }
        return $this->caja->loadFromCode('', $where);
    }

    protected function loadDocPrint(): void
    {
        $this->setTemplate(false);
        $idDoc = $this->request->get('idDoc', '');
        $pay = (bool)$this->request->get('pay', false);
        $content = [
            'docprint' => SaleForm::loadDocPrint($idDoc, $this->tpv, $pay),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function loadDocReturn(): void
    {
        $this->setTemplate(false);
        $idDoc = $this->request->get('idDoc', '');
        $content = [
            'idDoc' => $idDoc,
            'docreturn' => SaleReturn::loadDocReturn($idDoc, $this->tpv),
            'methodreturn' => SaleReturn::getMethodReturn($idDoc, $this->tpv),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function loadPark(): void
    {
        $this->setTemplate(false);
        $codpark = $this->request->get('codpark', '');
        ParkForm::loadPark($codpark, $this->user, $this->tpv);
        $content = [
            'dtopor_global' => SaleForm::getDoc()->dtopor1,
            'customer' => SaleForm::getDoc()->getSubject(),
            'advance-payments' => ParkForm::getAdvancePayments($codpark),
            'codpark' => $codpark,
            'amount' => SaleForm::amount($this->tpv),
            'cart' => SaleForm::render($this->tpv),
            'observations' => ParkForm::getObservations(),
            'totalCart' => SaleForm::totalCart(),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function saveParkAction(): void
    {
        $this->setTemplate(false);
        $formData = $this->request->request->all();
        $content = [
            'savepark' => ParkForm::savePark($formData, $this->user, $this->caja, $this->agente->codagente),
            'amount' => SaleForm::amount($this->tpv),
            'cart' => SaleForm::render($this->tpv),
            'totalCart' => SaleForm::totalCart(),
            'totalParks' => count(ParkForm::getParks($this->tpv)),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function loadTpvAgents(): void
    {
        $tpvAgentsModel = new TpvAgente();
        $where = [new DataBaseWhere('idtpv', $this->tpv->idtpv)];
        $this->tpvAgents = $tpvAgentsModel->all($where, [], 0, 0);
    }

    protected function loadTpvs(): void
    {
        $tpvModel = new TpvTerminal();
        $where = [new DataBaseWhere('active', true)];
        $orderBy = ['name' => 'ASC'];
        $this->tpvs = $tpvModel->all($where, $orderBy, 0, 0);
    }

    protected function openDrawer(): void
    {
        $this->setTemplate(false);
        if ($this->tpv->idprinter) {
            SaleTicket::openDrawer($this->tpv, $this->user, $this->agente);
            $this->getConnectedPrinter();
        } else {
            Tools::log()->warning('printer-not-configured');
        }

        $content = ['messages' => Tools::log()->read('master', $this->logLevels)];
        $this->response->setContent(json_encode($content));
    }

    protected function prePrintTicket(): void
    {
        $this->setTemplate(false);

        $printer = new TicketPrinter();
        $formData = $this->request->request->all();
        SaleForm::apply($formData, $this->user, $this->tpv, $this->agente->codagente);

        if ($printer->loadFromCode($this->tpv->idprinter)) {
            PreTicket::setLines(SaleForm::getLines());
            PreTicket::print(SaleForm::getDoc(), $printer, $this->user, $this->agente);
            PreTicket::setLines();
            $this->getConnectedPrinter();
        } else {
            Tools::log()->warning('printer-not-configured');
        }

        $content = ['messages' => Tools::log()->read('master', $this->logLevels)];
        $this->response->setContent(json_encode($content));
    }

    protected function printTicket(): void
    {
        $this->setTemplate(false);
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $this->tpv->doctype;
        $ticketClass = '\\FacturaScripts\\Dinamic\\Lib\\Tickets\\' . $this->request->get('ticketformat', 'Normal');

        if (false === class_exists($modelClass)) {
            Tools::log()->error('class-not-exists');
        } elseif (false === class_exists($ticketClass)) {
            Tools::log()->error('ticket-class-not-exists');
        } else {
            $printer = new TicketPrinter();
            $doc = new $modelClass();

            if (false === $doc->loadFromCode($this->request->get('idDoc'))) {
                Tools::log()->error('document-not-exists');
            } elseif (false === $printer->loadFromCode($this->tpv->idprinter)) {
                Tools::log()->warning('printer-not-configured');
            } else {
                for ($i = 0; $i < (int)$this->request->get('ticket_number', $this->tpv->ticket_number); $i++) {
                    $ticketClass::print($doc, $printer, $this->user, $this->agente);
                }

                $this->getConnectedPrinter();
            }
        }

        $content = ['messages' => Tools::log()->read('master', $this->logLevels)];
        $this->response->setContent(json_encode($content));
    }

    protected function recalculateAction(bool $renderLines): void
    {
        $this->setTemplate(false);
        $formData = $this->request->request->all();
        SaleForm::apply($formData, $this->user, $this->tpv, $this->agente->codagente);
        $content = [
            'amount' => SaleForm::amount($this->tpv),
            'cart' => $renderLines ? SaleForm::render($this->tpv) : '',
            'cartMap' => $renderLines ? [] : SaleForm::map($this->tpv),
            'totalCart' => SaleForm::totalCart(),
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function saveCart(): void
    {
        $this->setTemplate(false);
        $formData = $this->request->request->all();
        $content['savecart'] = SaleForm::saveDoc($formData, $this->user, $this->caja, $this->agente->codagente);
        if ($content['savecart']) {
            $content['amount'] = SaleForm::amount($this->tpv);
            $content['cart'] = '';
            $content['totalCart'] = SaleForm::totalCart();
            $content['document'] = SaleForm::getLastDocSave();
            $content['totalParks'] = count(ParkForm::getParks($this->tpv));
        }
        $this->pipe('saveCartAfter', $content);
        $content['messages'] = Tools::log()->read('master', $this->logLevels);
        $this->response->setContent(json_encode($content));
    }

    protected function saveReturn(): void
    {
        $this->setTemplate(false);
        $formData = $this->request->request->all();
        $content = [
            'savereturn' => SaleReturn::saveReturn($formData, $this->user, $this->caja, $this->agente->codagente),
            'document' => SaleReturn::$lastDocSave,
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function selectTpvAction(): void
    {
        // comprobamos que existe el tpv
        $idtpv = $this->request->request->get('idtpv', '');
        if (empty($idtpv) || false === $this->tpv->loadFromCode($idtpv)) {
            return;
        }

        // comprobamos que el tpv sigue activo
        if (false === $this->tpv->active) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        // obtenemos los agentes del tpv
        $this->loadTpvAgents();

        // comprobamos si el tpv tiene agentes
        if (count($this->tpvAgents) > 0) {
            // comprobamos si el agente con la sesión iniciada esta en la lista de agentes del tpv
            $this->loadTpvAgents();
            $found = false;
            foreach ($this->tpvAgents as $agent) {
                if ($agent->codagente == $this->cookieCodagente) {
                    $found = true;
                }
            }

            // preguntamos si encontró el agente en el tpv
            if ($found) {
                if ($this->tpv->isOpen()) {
                    // si el tpv está abierto cargamos el tpv
                    $this->setTemplate('TPVneo/index');
                } else {
                    // si el tpv está cerrado cargamos la apertura de caja
                    $this->setTemplate('TPVneo/start-tpv');
                }
            } else {
                // si no encontró el agente cargamos el login
                $this->setTemplate('TPVneo/login');
            }

            // el tpv no tiene agentes asignados y esta cerrado
        } elseif (false === $this->tpv->isOpen()) {
            $this->deleteCookieAgent();
            // si el tpv no está abierto mostramos la caja
            $this->setTemplate('TPVneo/start-tpv');

            // si el tpv no tiene agentes asignados y está abierto
        } else {
            $this->deleteCookieAgent();
            $where = [
                new DataBaseWhere('idtpv', $this->tpv->idtpv),
                new DataBaseWhere('fechafin', null)
            ];
            $this->caja->loadFromCode('', $where);
            $this->setTemplate('TPVneo/index');
        }

        // si el cliente predeterminado tiene una serie asignada, y es diferente a la serie del tpv, advertimos
        $customer = $this->tpv->getCustomer();
        if ($customer->codserie && $customer->codserie !== $this->tpv->codserie) {
            Tools::log()->warning('tpv-customer-different-serie');
        }
    }

    protected function sendDoc(): void
    {
        $this->setTemplate(false);

        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $this->tpv->doctype;
        $doc = new $modelClass();
        $email = $this->request->get('email', '');
        $mailbox = $this->request->get('mailbox', '');

        $result = false;
        if ($email !== '' && $doc->loadFromCode($this->request->get('idDoc', ''))) {
            $result = SaleEmail::send($doc, $email, $mailbox);
        }

        $content = [
            'send-email' => $result,
            'messages' => Tools::log()->read('master', $this->logLevels)
        ];
        $this->response->setContent(json_encode($content));
    }

    protected function startingMoneyAction(): void
    {
        // comprobamos el token
        if (false === $this->validateFormToken()) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        // comprobamos que existe el tpv
        $idtpv = $this->request->request->get('idtpv');
        if (false === $this->tpv->loadFromCode($idtpv)) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        // comprobamos que el tpv sigue activo
        if (false === $this->tpv->active) {
            $this->loadTpvs();
            $this->setTemplate('TPVneo');
            return;
        }

        // comprobamos si ya hay una caja abierta
        $where = [
            new DataBaseWhere('fechafin', null),
            new DataBaseWhere('idtpv', $idtpv),
        ];
        $orderBy = ['idcaja' => 'DESC'];
        if ($this->caja->loadFromCode('', $where, $orderBy)) {
            $this->loadTpvAgents();
            $this->setTemplate('TPVneo/index');
            return;
        }

        $coinsTypes = $this->getCoinTypes();
        $data = $this->request->request->all();

        $total = 0;
        foreach ($coinsTypes as $coin) {
            $total += $coin->name * $data[str_replace('.', '_', $coin->name)];
        }

        $this->caja->dineroini = $total;
        $this->caja->totalcaja = $total;
        $this->caja->idtpv = $idtpv;
        $this->caja->nick = $this->user->nick;

        if ($this->caja->save()) {
            $this->loadTpvAgents();
            $this->setTemplate('TPVneo/index');
        }
    }
}
