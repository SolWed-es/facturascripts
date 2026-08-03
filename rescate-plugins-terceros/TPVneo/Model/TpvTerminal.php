<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Series;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\TicketPrinter as DinTicketPrinter;
use FacturaScripts\Dinamic\Model\TpvCaja as DinTpvCaja;
use FacturaScripts\Plugins\Tickets\Model\TicketPrinter;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TpvTerminal extends ModelClass
{
    use ModelTrait;

    const DEFAULT_DOC_TYPE = 'FacturaCliente';
    const DEFAULT_LIST_TYPE = 'Variant';
    const DEFAULT_TICKET_FORMAT = 'Normal';

    /** @var bool */
    public $active;

    /** @var bool */
    public $adddiscount;

    /** @var bool */
    public $addemptyline;

    /** @var int */
    public $budgetdateend;

    /** @var bool */
    public $changeprice;

    /** @var int */
    public $closeagent;

    /** @var string */
    public $codalmacen;

    /** @var string */
    public $codcliente;

    /** @var string */
    public $coddivisa;

    /** @var string */
    public $codpago;

    /** @var string */
    public $codserie;

    /** @var string */
    public $doctype;

    /** @var bool */
    public $grouplines;

    /** @var int */
    public $idempresa;

    /** @var int */
    public $idtpv;

    /** @var int */
    public $idprinter;

    /** @var string */
    public $listtype;

    /** @var string */
    public $name;

    /** @var bool */
    public $not_line_price_empty;

    /** @var bool */
    public $sound;

    /** @var bool */
    public $preticket;

    /** @var bool */
    public $price_tax_include;

    /** @var int */
    public $productlimit;

    /** @var string */
    public $ticketformat;

    /** @var int */
    public $ticket_number;

    public function clear()
    {
        parent::clear();
        $this->active = true;
        $this->addemptyline = false;
        $this->adddiscount = false;
        $this->budgetdateend = 1;
        $this->changeprice = false;
        $this->closeagent = 60;
        $this->codalmacen = Tools::settings('default', 'codalmacen');
        $this->coddivisa = Tools::settings('default', 'coddivisa');
        $this->codpago = Tools::settings('default', 'codpago');
        $this->codserie = $this->getSimplifiedSerie();
        $this->doctype = self::DEFAULT_DOC_TYPE;
        $this->grouplines = true;
        $this->listtype = self::DEFAULT_LIST_TYPE;
        $this->not_line_price_empty = false;
        $this->preticket = false;
        $this->price_tax_include = true;
        $this->productlimit = 50;
        $this->sound = false;
        $this->ticketformat = self::DEFAULT_TICKET_FORMAT;
        $this->ticket_number = 1;
    }

    /**
     * @return TpvCaja[]
     */
    public function getCajas(): array
    {
        $caja = new DinTpvCaja();
        $where = [new DataBaseWhere('idtpv', $this->idtpv)];
        $orderBy = ['fechaini' => 'DESC'];
        return $caja->all($where, $orderBy);
    }

    public function getCustomer(): Cliente
    {
        $customer = new Cliente();
        $customer->loadFromCode($this->codcliente);
        return $customer;
    }

    public function getMethodPayment(): FormaPago
    {
        $paymentMethod = new FormaPago();
        $paymentMethod->loadFromCode($this->codpago);
        return $paymentMethod;
    }

    public function getMethodPayments(): array
    {
        $paymentMethod = new TpvPago();
        $where = [new DataBaseWhere('idtpv', $this->idtpv)];
        return $paymentMethod->all($where, [], 0, 0);
    }

    public function getPrinter(): TicketPrinter
    {
        $printer = new DinTicketPrinter();
        $printer->loadFromCode($this->idprinter);
        return $printer;
    }

    public function getWarehouse(): Almacen
    {
        $warehouse = new Almacen();
        $warehouse->loadFromCode($this->codalmacen);
        return $warehouse;
    }

    public function install(): string
    {
        new TicketPrinter();
        return parent::install();
    }

    public function isOpen(): bool
    {
        foreach ($this->getCajas() as $caja) {
            if (empty($caja->fechafin)) {
                return true;
            }
        }

        return false;
    }

    public static function primaryColumn(): string
    {
        return 'idtpv';
    }

    public function save(): bool
    {
        if ($this->active === false) {
            // cerramos las cajas abiertas
            $modelCaja = new TpvCaja();
            $where = [
                new DataBaseWhere('idtpv', $this->idtpv),
                new DataBaseWhere('fechafin', null)
            ];
            foreach ($modelCaja->all($where, [], 0, 0) as $caja) {
                $caja->close(0.0);
                $caja->save();
            }
        }

        return parent::save();
    }

    public static function tableName(): string
    {
        return 'tpvsneo';
    }

    public function test(): bool
    {
        // escapamos el html de los campos
        $this->name = Tools::noHtml($this->name);

        // si no tiene empresa, asignamos la del almacén
        if (empty($this->idempresa)) {
            $this->idempresa = $this->getWarehouse()->idempresa;
        }

        if (empty($this->listtype)) {
            $this->listtype = self::DEFAULT_LIST_TYPE;
        }

        if (empty($this->ticketformat) || $this->ticketformat === 'BoxClosure') {
            $this->ticketformat = self::DEFAULT_TICKET_FORMAT;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'ListTicketPrinter'): string
    {
        return parent::url($type, $list . '?activetab=List');
    }

    protected function getSimplifiedSerie(): string
    {
        foreach (Series::all() as $serie) {
            if (strtolower($serie->codserie) === 's') {
                return $serie->codserie;
            }
        }

        return Tools::settings('default', 'codserie');
    }
}
