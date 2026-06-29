<?php

/**
 * Plugin SolwedES - Modelo de Suscripciones de Compra (gastos recurrentes a proveedores)
 *
 * @author    Solwed Desarrollo
 * @copyright 2026 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Proveedor;

/**
 * Suscripcion de compra: gasto recurrente que SOLWED paga a un proveedor
 * (hosting upstream, SaaS, licencias, etc.). Gestion manual, sin Stripe.
 */
class SuscripcionCompra extends ModelClass
{
    use ModelTrait;

    // Estados
    const ESTADO_ACTIVA = 'activa';
    const ESTADO_PAUSADA = 'pausada';
    const ESTADO_CANCELADA = 'cancelada';

    // Metodos de pago
    const METODO_DOMICILIACION = 'domiciliacion';
    const METODO_TRANSFERENCIA = 'transferencia';
    const METODO_TARJETA = 'tarjeta';
    const METODO_MANUAL = 'manual';

    /** @var int */
    public $id;

    /** @var string */
    public $codproveedor;

    /** @var string */
    public $concepto;

    /** @var float */
    public $importe;

    /** @var string */
    public $moneda;

    /** @var string */
    public $intervalo;

    /** @var string */
    public $estado;

    /** @var string|null */
    public $fecha_inicio;

    /** @var string|null */
    public $fecha_proximo_pago;

    /** @var string|null */
    public $fecha_ultimo_pago;

    /** @var string */
    public $metodo_pago;

    /** @var string|null */
    public $referencia_externa;

    /** @var bool */
    public $auto_renovar;

    /** @var string|null */
    public $notas;

    /** @var string */
    public $creation_date;

    /** @var string */
    public $last_update;

    public function clear(): void
    {
        parent::clear();
        $this->importe = 0.0;
        $this->moneda = 'EUR';
        $this->intervalo = 'month';
        $this->estado = self::ESTADO_ACTIVA;
        $this->metodo_pago = self::METODO_DOMICILIACION;
        $this->auto_renovar = true;
        $this->creation_date = Tools::dateTime();
        $this->last_update = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'solwedes_suscripciones_compra';
    }

    public function getProveedor(): Proveedor
    {
        $proveedor = new Proveedor();
        $proveedor->load($this->codproveedor);
        return $proveedor;
    }

    public static function getActivasByProveedor(string $codproveedor): array
    {
        $suscripcion = new self();
        $where = [
            Where::column('codproveedor', $codproveedor),
            Where::column('estado', self::ESTADO_ACTIVA),
        ];
        return $suscripcion->all($where, ['fecha_inicio' => 'DESC']);
    }

    public static function getProximasAPagar(int $dias = 15): array
    {
        $suscripcion = new self();
        $fechaLimite = date('Y-m-d', strtotime("+{$dias} days"));
        $where = [
            Where::column('estado', self::ESTADO_ACTIVA),
            Where::column('fecha_proximo_pago', $fechaLimite, '<='),
            Where::column('fecha_proximo_pago', date('Y-m-d'), '>='),
        ];
        return $suscripcion->all($where, ['fecha_proximo_pago' => 'ASC']);
    }

    public function isActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVA;
    }

    public function test(): bool
    {
        if (empty($this->creation_date)) {
            $this->creation_date = Tools::dateTime();
        }
        $this->last_update = Tools::dateTime();

        $this->concepto = Tools::noHtml($this->concepto);
        $this->notas = Tools::noHtml($this->notas);
        $this->referencia_externa = Tools::noHtml($this->referencia_externa);

        if (empty($this->codproveedor)) {
            Tools::log()->error('supplier-required');
            return false;
        }

        if (empty($this->concepto)) {
            Tools::log()->error('field-can-not-be-null', ['%fieldName%' => 'concepto']);
            return false;
        }

        $estadosValidos = [self::ESTADO_ACTIVA, self::ESTADO_PAUSADA, self::ESTADO_CANCELADA];
        if (!in_array($this->estado, $estadosValidos)) {
            $this->estado = self::ESTADO_ACTIVA;
        }

        $metodosValidos = [
            self::METODO_DOMICILIACION,
            self::METODO_TRANSFERENCIA,
            self::METODO_TARJETA,
            self::METODO_MANUAL,
        ];
        if (!in_array($this->metodo_pago, $metodosValidos)) {
            $this->metodo_pago = self::METODO_DOMICILIACION;
        }

        if ($this->importe < 0) {
            $this->importe = 0;
        }

        return parent::test();
    }
}
