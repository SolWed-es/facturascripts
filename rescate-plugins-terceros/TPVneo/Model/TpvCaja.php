<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\TpvTerminal as DinTpvTerminal;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class TpvCaja extends ModelClass
{
    use ModelTrait;

    /**
     * La diferencia entre el dinero que hay al cerrar la caja (dinerofin) y el dinero que debería haber (totalcaja).
     *
     * @var float
     */
    public $diferencia;

    /**
     * El dinero que hay al cerrar la caja.
     *
     * @var float
     */
    public $dinerofin;

    /**
     * El dinero que había al abrir la caja.
     *
     * @var float
     */
    public $dineroini;

    /** @var string */
    public $fechafin;

    /** @var string */
    public $fechaini;

    /** @var int */
    public $idcaja;

    /** @var int */
    public $idtpv;

    /**
     * La suma de todos los ingresos en efectivo. No incluye el dinero inicial.
     *
     * @var float
     */
    public $ingresos;

    /** @var string */
    public $nick;

    /**
     * El número de tickets que se han emitido.
     *
     * @var int
     */
    public $numtickets;

    /** @var string */
    public $observaciones;

    /**
     * El dinero que debería haber en la caja. Es la suma del dinero inicial más todos los ingresos en efectivo.
     *
     * @var float
     */
    public $totalcaja;

    /**
     * La suma de todos los movimientos de caja.
     *
     * @var float
     */
    public $totalmovi;

    /**
     * La suma de los totales de todos los tickets emitidos.
     *
     * @var float
     */
    public $totaltickets;

    public function clear()
    {
        parent::clear();
        $this->diferencia = 0.0;
        $this->dinerofin = 0.0;
        $this->dineroini = 0.0;
        $this->fechaini = date(self::DATETIME_STYLE);
        $this->ingresos = 0.0;
        $this->numtickets = 0;
        $this->totalcaja = 0.0;
        $this->totalmovi = 0.0;
        $this->totaltickets = 0.0;
    }

    public function close(float $finalAmount): void
    {
        $this->dinerofin = $finalAmount;
        $this->diferencia = $finalAmount - $this->totalcaja;
        $this->fechafin = date(self::DATETIME_STYLE);
    }

    public function getDocs(?TpvTerminal $tpv = null): array
    {
        if (is_null($tpv)) {
            $tpv = $this->getTerminal();
        }
        if (false === $tpv->exists()) {
            return [];
        }

        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        $docModel = new $modelClass();
        $where = [new DataBaseWhere('idcaja', $this->idcaja)];
        $orderBy = ['fecha' => 'DESC', 'hora' => 'DESC'];
        return $docModel->all($where, $orderBy, 0, 0);
    }

    public function getMovements(): array
    {
        $movementModel = new TpvMovimiento();
        $where = [new DataBaseWhere('idcaja', $this->idcaja)];
        return $movementModel->all($where, [], 0, 0);
    }

    public function getPaymentBreakdown(): array
    {
        $payments = [];
        $tpv = $this->getTerminal();
        foreach ($this->getDocs($tpv) as $doc) {
            if ($tpv->doctype === 'AlbaranCliente') {
                $paymentMethod = $doc->getPaymentMethod();
                if (!isset($payments[$paymentMethod->codpago])) {
                    $payments[$paymentMethod->codpago] = [
                        'descripcion' => $paymentMethod->descripcion,
                        'total' => $doc->total,
                    ];
                    continue;
                }
                $payments[$paymentMethod->codpago]['total'] += $doc->total;
                continue;
            }

            foreach ($doc->getReceipts() as $receipt) {
                $paymentMethod = $receipt->getPaymentMethod();
                if (!isset($payments[$paymentMethod->codpago])) {
                    $payments[$paymentMethod->codpago] = [
                        'descripcion' => $paymentMethod->descripcion,
                        'total' => $receipt->importe,
                    ];
                    continue;
                }
                $payments[$paymentMethod->codpago]['total'] += $receipt->importe;
            }
        }
        return $payments;
    }

    public function getTerminal(): TpvTerminal
    {
        $tpv = new DinTpvTerminal();
        $tpv->loadFromCode($this->idtpv);
        return $tpv;
    }

    public static function primaryColumn(): string
    {
        return 'idcaja';
    }

    public static function tableName(): string
    {
        return 'tpvsneo_cajas';
    }

    public function test(): bool
    {
        // escapamos el html de observaciones
        $this->observaciones = Utils::noHtml($this->observaciones);

        $this->setTotals();
        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        return $type === 'list' ?
            $this->getTerminal()->url() :
            parent::url($type, $list);
    }

    protected function setTotals(): void
    {
        if (empty($this->primaryColumnValue())) {
            return;
        }

        $terminal = $this->getTerminal();
        $this->ingresos = 0.0;
        $this->numtickets = 0;
        $this->totalcaja = $this->dineroini;
        $this->totaltickets = 0.0;
        $this->totalmovi = 0.0;

        // recorremos los documentos de la caja
        foreach ($this->getDocs() as $doc) {
            $this->numtickets++;

            $this->ingresos += $doc->tpv_efectivo - $doc->tpv_cambio;
            $this->totalcaja += $doc->tpv_efectivo - $doc->tpv_cambio;
            $this->totaltickets += $doc->total;
        }

        // recorremos los presupuestos aparcados
        $presupuestoModel = new PresupuestoCliente();
        $where = [
            new DataBaseWhere('idcaja', $this->idcaja),
            new DataBaseWhere('aparcado', true),
        ];
        foreach ($presupuestoModel->all($where, [], 0, 0) as $presupuesto) {
            if (false === Plugins::isEnabled('PrePagos')) {
                continue;
            }

            foreach ($presupuesto->getPayments() as $prepayment) {
                // si la forma de pago es la del terminal, es efectivo
                if ($prepayment->codpago === $terminal->codpago) {
                    $this->ingresos += $prepayment->amount;
                    $this->totalcaja += $prepayment->amount;
                }
            }
        }

        // recorremos los movimientos de la caja
        foreach ($this->getMovements() as $movement) {
            $this->totalcaja += $movement->amount;
            $this->totalmovi += $movement->amount;
        }
    }
}