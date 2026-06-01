<?php

/**
 * Plugin SolwedES - Modelo de Contratos de Servicios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Contacto;

/**
 * Modelo para gestión de contratos de servicios SOLWED.
 */
class ContratServicio extends ModelClass
{
    use ModelTrait;

    // Estados del contrato
    const ESTADO_ACTIVO = 'activo';
    const ESTADO_SUSPENDIDO = 'suspendido';
    const ESTADO_CANCELADO = 'cancelado';
    const ESTADO_VENCIDO = 'vencido';
    const ESTADO_PENDIENTE = 'pendiente';

    // Métodos de pago
    const METODO_STRIPE = 'stripe';
    const METODO_TRANSFERENCIA = 'transferencia';
    const METODO_DOMICILIACION = 'domiciliacion';
    const METODO_MANUAL = 'manual';

    // Estados de aprovisionamiento
    const PROV_NOT_REQUIRED = 'not_required';
    const PROV_PENDING = 'pending';
    const PROV_IN_PROGRESS = 'in_progress';
    const PROV_COMPLETED = 'completed';
    const PROV_FAILED = 'failed';

    /** @var int */
    public $id;

    /** @var int */
    public $idcontacto;

    /** @var int */
    public $idservicio;

    /** @var string */
    public $estado;

    /** @var string */
    public $fecha_inicio;

    /** @var string */
    public $fecha_vencimiento;

    /** @var string|null */
    public $fecha_ultimo_pago;

    /** @var string|null */
    public $fecha_proximo_pago;

    /** @var string */
    public $metodo_pago;

    /** @var string|null */
    public $referencia_externa;

    /** @var bool */
    public $auto_renovar;

    /** @var float */
    public $importe;

    /** @var string|null */
    public $stripe_customer_id;

    /** @var string|null */
    public $notas;

    /** @var string */
    public $provisioning_status;

    /** @var string */
    public $creation_date;

    /** @var string */
    public $last_update;

    public function clear(): void
    {
        parent::clear();
        $this->estado = self::ESTADO_PENDIENTE;
        $this->metodo_pago = self::METODO_MANUAL;
        $this->auto_renovar = true;
        $this->importe = 0.0;
        $this->provisioning_status = self::PROV_NOT_REQUIRED;
        $this->creation_date = Tools::dateTime();
        $this->last_update = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'solwedes_contratos';
    }

    public function getContacto(): Contacto
    {
        $contacto = new Contacto();
        $contacto->load($this->idcontacto);
        return $contacto;
    }

    public function getServicio(): Servicio
    {
        $servicio = new Servicio();
        $servicio->load($this->idservicio);
        return $servicio;
    }

    public static function getActivosByContacto(int $idcontacto): array
    {
        $contrato = new self();
        $where = [
            Where::column('idcontacto', $idcontacto),
            Where::column('estado', self::ESTADO_ACTIVO),
        ];
        return $contrato->all($where, ['fecha_inicio' => 'DESC']);
    }

    public static function getByReferenciaExterna(string $ref): ?self
    {
        $contrato = new self();
        $where = [Where::column('referencia_externa', $ref)];
        $results = $contrato->all($where, [], 0, 1);

        return !empty($results) ? $results[0] : null;
    }

    public static function getByStripeSubscriptionId(string $subId): ?self
    {
        return self::getByReferenciaExterna($subId);
    }

    public static function getByStripeCustomerId(string $customerId): array
    {
        $contrato = new self();
        $where = [Where::column('stripe_customer_id', $customerId)];
        return $contrato->all($where, ['fecha_inicio' => 'DESC']);
    }

    public static function getActiveByStripeCustomerId(string $customerId): ?self
    {
        $contrato = new self();
        $where = [
            Where::column('stripe_customer_id', $customerId),
            Where::column('estado', self::ESTADO_ACTIVO),
        ];
        $results = $contrato->all($where, ['fecha_inicio' => 'DESC'], 0, 1);

        return !empty($results) ? $results[0] : null;
    }

    public static function getActiveByStripeContacto(int $idcontacto): array
    {
        $contrato = new self();
        $where = [
            Where::column('idcontacto', $idcontacto),
            Where::column('metodo_pago', self::METODO_STRIPE),
            Where::column('estado', self::ESTADO_ACTIVO),
        ];
        return $contrato->all($where, ['fecha_inicio' => 'DESC']);
    }

    public static function getProximosAVencer(int $dias = 30): array
    {
        $contrato = new self();
        $fechaLimite = date('Y-m-d', strtotime("+{$dias} days"));
        $where = [
            Where::column('estado', self::ESTADO_ACTIVO),
            Where::column('fecha_vencimiento', $fechaLimite, '<='),
            Where::column('fecha_vencimiento', date('Y-m-d'), '>='),
        ];
        return $contrato->all($where, ['fecha_vencimiento' => 'ASC']);
    }

    public function isVencido(): bool
    {
        if (empty($this->fecha_vencimiento)) {
            return false;
        }
        return strtotime($this->fecha_vencimiento) < strtotime(date('Y-m-d'));
    }

    public function isProximoAVencer(int $dias = 30): bool
    {
        if (empty($this->fecha_vencimiento)) {
            return false;
        }
        $fechaLimite = strtotime("+{$dias} days");
        $vencimiento = strtotime($this->fecha_vencimiento);
        return $vencimiento <= $fechaLimite && $vencimiento >= strtotime(date('Y-m-d'));
    }

    public function isActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    public function getDominios(): array
    {
        if (empty($this->id)) {
            return [];
        }
        return Dominio::getByContrato($this->id);
    }

    public function test(): bool
    {
        if (empty($this->creation_date)) {
            $this->creation_date = Tools::dateTime();
        }
        $this->last_update = Tools::dateTime();

        if (empty($this->idcontacto)) {
            Tools::log()->error('contact-required');
            return false;
        }

        if (empty($this->idservicio)) {
            Tools::log()->error('service-required');
            return false;
        }

        $estadosValidos = [
            self::ESTADO_ACTIVO,
            self::ESTADO_SUSPENDIDO,
            self::ESTADO_CANCELADO,
            self::ESTADO_VENCIDO,
            self::ESTADO_PENDIENTE,
        ];
        if (!in_array($this->estado, $estadosValidos)) {
            $this->estado = self::ESTADO_PENDIENTE;
        }

        $metodosValidos = [
            self::METODO_STRIPE,
            self::METODO_TRANSFERENCIA,
            self::METODO_DOMICILIACION,
            self::METODO_MANUAL,
        ];
        if (!in_array($this->metodo_pago, $metodosValidos)) {
            $this->metodo_pago = self::METODO_MANUAL;
        }

        $provisioningValidos = [
            self::PROV_NOT_REQUIRED,
            self::PROV_PENDING,
            self::PROV_IN_PROGRESS,
            self::PROV_COMPLETED,
            self::PROV_FAILED,
        ];
        if (!in_array($this->provisioning_status, $provisioningValidos)) {
            $this->provisioning_status = self::PROV_NOT_REQUIRED;
        }

        if ($this->importe < 0) {
            $this->importe = 0;
        }

        return parent::test();
    }
}
