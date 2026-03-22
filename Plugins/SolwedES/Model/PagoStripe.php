<?php

/**
 * Plugin SolwedES - Modelo de Pagos Stripe
 *
 * Registra todos los pagos procesados por Stripe, tanto suscripciones como pagos únicos.
 * Sirve como enlace central entre Stripe y los documentos de FacturaScripts.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Where;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\AlbaranCliente;

/**
 * Modelo para gestión de pagos Stripe
 */
class PagoStripe extends ModelClass
{
    use ModelTrait;

    // Tipos de pago
    const TIPO_SUBSCRIPTION = 'subscription';
    const TIPO_ONE_TIME = 'one_time';

    // Estados del pago
    const ESTADO_PENDING = 'pending';
    const ESTADO_SUCCEEDED = 'succeeded';
    const ESTADO_FAILED = 'failed';
    const ESTADO_REFUNDED = 'refunded';
    const ESTADO_PARTIAL_REFUND = 'partial_refund';
    const ESTADO_CANCELED = 'canceled';

    /** @var int Identificador único */
    public $id;

    /** @var int ID del contacto */
    public $idcontacto;

    /** @var int|null ID de la suscripción (si aplica) */
    public $idsuscripcion;

    /** @var int|null ID del servicio */
    public $idservicio;

    /** @var string ID del PaymentIntent de Stripe (único) */
    public $stripe_payment_intent;

    /** @var string|null ID de la factura de Stripe */
    public $stripe_invoice_id;

    /** @var string|null ID de la sesión de checkout */
    public $stripe_checkout_session;

    /** @var string ID del cliente en Stripe */
    public $stripe_customer_id;

    /** @var string Tipo: subscription, one_time */
    public $tipo;

    /** @var string Concepto/descripción del pago */
    public $concepto;

    /** @var float Importe del pago */
    public $importe;

    /** @var string Código de moneda (EUR, USD, etc.) */
    public $moneda;

    /** @var string Estado del pago */
    public $estado;

    /** @var string|null JSON con detalles del método de pago */
    public $metodo_pago;

    /** @var int|null ID de la factura de FacturaScripts */
    public $idfactura;

    /** @var int|null ID del albarán de FacturaScripts */
    public $idalbaran;

    /** @var string|null Fecha y hora del pago */
    public $fecha_pago;

    /** @var string|null JSON con metadata adicional */
    public $metadata;

    /** @var string Fecha de creación */
    public $creation_date;

    /** @var string Última actualización */
    public $last_update;

    public function clear(): void
    {
        parent::clear();
        $this->tipo = self::TIPO_ONE_TIME;
        $this->estado = self::ESTADO_PENDING;
        $this->moneda = 'EUR';
        $this->importe = 0.0;
        $this->creation_date = date('Y-m-d H:i:s');
        $this->last_update = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'solwedes_pagos';
    }

    /**
     * Busca un pago por PaymentIntent de Stripe
     */
    public static function getByPaymentIntent(string $paymentIntentId): ?self
    {
        if (empty($paymentIntentId)) {
            return null;
        }

        $pago = new self();
        $where = [Where::column('stripe_payment_intent', $paymentIntentId)];
        $results = $pago->all($where, [], 0, 1);

        return !empty($results) ? $results[0] : null;
    }

    /**
     * Busca un pago por Invoice ID de Stripe
     */
    public static function getByInvoiceId(string $invoiceId): ?self
    {
        if (empty($invoiceId)) {
            return null;
        }

        $pago = new self();
        $where = [Where::column('stripe_invoice_id', $invoiceId)];
        $results = $pago->all($where, [], 0, 1);

        return !empty($results) ? $results[0] : null;
    }

    /**
     * Busca un pago por Checkout Session ID
     */
    public static function getByCheckoutSession(string $sessionId): ?self
    {
        if (empty($sessionId)) {
            return null;
        }

        $pago = new self();
        $where = [Where::column('stripe_checkout_session', $sessionId)];
        $results = $pago->all($where, [], 0, 1);

        return !empty($results) ? $results[0] : null;
    }

    /**
     * Obtiene todos los pagos de un contacto
     */
    public static function getByContacto(int $idcontacto, ?string $estado = null): array
    {
        $pago = new self();
        $where = [Where::column('idcontacto', $idcontacto)];

        if ($estado !== null) {
            $where[] = Where::column('estado', $estado);
        }

        return $pago->all($where, ['fecha_pago' => 'DESC', 'creation_date' => 'DESC'], 50);
    }

    /**
     * Obtiene pagos exitosos de un contacto
     */
    public static function getPagosExitososByContacto(int $idcontacto): array
    {
        return self::getByContacto($idcontacto, self::ESTADO_SUCCEEDED);
    }

    /**
     * Obtiene todos los pagos de una suscripción
     */
    public static function getBySuscripcion(int $idsuscripcion): array
    {
        $pago = new self();
        $where = [Where::column('idsuscripcion', $idsuscripcion)];

        return $pago->all($where, ['fecha_pago' => 'DESC']);
    }

    /**
     * Obtiene el contacto asociado
     */
    public function getContacto(): ?Contacto
    {
        if (empty($this->idcontacto)) {
            return null;
        }

        $contacto = new Contacto();
        if ($contacto->load($this->idcontacto)) {
            return $contacto;
        }
        return null;
    }

    /**
     * @deprecated SuscripcionStripe has been replaced by ContratServicio.
     * Use getContrato() or look up ContratServicio by referencia_externa instead.
     * @return null Always returns null as SuscripcionStripe no longer exists
     */
    public function getSuscripcion()
    {
        return null;
    }

    /**
     * Gets the associated ContratServicio (if linked via stripe_customer_id)
     */
    public function getContrato(): ?ContratServicio
    {
        if (empty($this->stripe_customer_id)) {
            return null;
        }

        $contratos = ContratServicio::getByStripeCustomerId($this->stripe_customer_id);
        return !empty($contratos) ? $contratos[0] : null;
    }

    /**
     * Obtiene el servicio asociado
     */
    public function getServicio(): ?Servicio
    {
        if (empty($this->idservicio)) {
            return null;
        }

        $servicio = new Servicio();
        if ($servicio->load($this->idservicio)) {
            return $servicio;
        }
        return null;
    }

    /**
     * Obtiene la factura de FacturaScripts asociada
     */
    public function getFactura(): ?FacturaCliente
    {
        if (empty($this->idfactura)) {
            return null;
        }

        $factura = new FacturaCliente();
        if ($factura->load($this->idfactura)) {
            return $factura;
        }
        return null;
    }

    /**
     * Obtiene el albarán de FacturaScripts asociado
     */
    public function getAlbaran(): ?AlbaranCliente
    {
        if (empty($this->idalbaran)) {
            return null;
        }

        $albaran = new AlbaranCliente();
        if ($albaran->load($this->idalbaran)) {
            return $albaran;
        }
        return null;
    }

    /**
     * Establece los detalles del método de pago desde array
     */
    public function setMetodoPago(array $details): void
    {
        $this->metodo_pago = json_encode($details, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Obtiene los detalles del método de pago
     */
    public function getMetodoPago(): array
    {
        if (empty($this->metodo_pago)) {
            return [];
        }

        $decoded = json_decode($this->metodo_pago, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Establece metadata adicional
     */
    public function setMetadata(array $data): void
    {
        $this->metadata = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Obtiene metadata adicional
     */
    public function getMetadata(): array
    {
        if (empty($this->metadata)) {
            return [];
        }

        $decoded = json_decode($this->metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Marca el pago como exitoso
     */
    public function markAsSucceeded(): bool
    {
        $this->estado = self::ESTADO_SUCCEEDED;
        $this->fecha_pago = date('Y-m-d H:i:s');
        return $this->save();
    }

    /**
     * Marca el pago como fallido
     */
    public function markAsFailed(): bool
    {
        $this->estado = self::ESTADO_FAILED;
        return $this->save();
    }

    /**
     * Marca el pago como reembolsado
     */
    public function markAsRefunded(bool $partial = false): bool
    {
        $this->estado = $partial ? self::ESTADO_PARTIAL_REFUND : self::ESTADO_REFUNDED;
        return $this->save();
    }

    /**
     * Verifica si el pago fue exitoso
     */
    public function isSucceeded(): bool
    {
        return $this->estado === self::ESTADO_SUCCEEDED;
    }

    /**
     * Verifica si es un pago de suscripción
     */
    public function isSubscription(): bool
    {
        return $this->tipo === self::TIPO_SUBSCRIPTION;
    }

    /**
     * Verifica si es un pago único
     */
    public function isOneTime(): bool
    {
        return $this->tipo === self::TIPO_ONE_TIME;
    }

    /**
     * Vincula el pago a una factura
     */
    public function linkToFactura(int $idfactura): bool
    {
        $this->idfactura = $idfactura;
        return $this->save();
    }

    /**
     * Vincula el pago a un albarán
     */
    public function linkToAlbaran(int $idalbaran): bool
    {
        $this->idalbaran = $idalbaran;
        return $this->save();
    }

    /**
     * Obtiene el importe formateado
     */
    public function getImporteFormateado(): string
    {
        return number_format($this->importe, 2, ',', '.') . ' ' . $this->moneda;
    }

    /**
     * Obtiene descripción del método de pago para mostrar
     */
    public function getMetodoPagoDescripcion(): string
    {
        $metodo = $this->getMetodoPago();

        if (empty($metodo)) {
            return 'N/A';
        }

        $brand = $metodo['brand'] ?? 'Tarjeta';
        $last4 = $metodo['last4'] ?? '****';

        return sprintf('%s **** %s', $brand, $last4);
    }

    protected function saveInsert(array $values = []): bool
    {
        $this->creation_date = date('Y-m-d H:i:s');
        $this->last_update = date('Y-m-d H:i:s');
        return parent::saveInsert();
    }

    protected function saveUpdate(array $values = []): bool
    {
        $this->last_update = date('Y-m-d H:i:s');
        return parent::saveUpdate();
    }

    public function test(): bool
    {
        if (empty($this->stripe_payment_intent)) {
            Tools::log('solwed')->error('stripe-payment-intent-required');
            return false;
        }

        // stripe_customer_id is optional (test events may not include it)
        if (empty($this->stripe_customer_id)) {
            $this->stripe_customer_id = 'unknown';
        }

        // Validar tipo
        $tiposValidos = [self::TIPO_SUBSCRIPTION, self::TIPO_ONE_TIME];
        if (!in_array($this->tipo, $tiposValidos)) {
            $this->tipo = self::TIPO_ONE_TIME;
        }

        // Validar estado
        $estadosValidos = [
            self::ESTADO_PENDING,
            self::ESTADO_SUCCEEDED,
            self::ESTADO_FAILED,
            self::ESTADO_REFUNDED,
            self::ESTADO_PARTIAL_REFUND,
            self::ESTADO_CANCELED
        ];
        if (!in_array($this->estado, $estadosValidos)) {
            $this->estado = self::ESTADO_PENDING;
        }

        // Validar moneda
        if (empty($this->moneda)) {
            $this->moneda = 'EUR';
        }
        $this->moneda = strtoupper($this->moneda);

        // Ensure concepto is not null
        if (empty($this->concepto)) {
            $this->concepto = 'Pago Stripe';
        }

        return parent::test();
    }

    /**
     * Obtiene estadísticas de pagos para un contacto
     */
    public static function getEstadisticasByContacto(int $idcontacto): array
    {
        $pagos = self::getByContacto($idcontacto);

        $stats = [
            'total_pagos' => 0,
            'importe_total' => 0.0,
            'pagos_exitosos' => 0,
            'pagos_fallidos' => 0,
            'ultimo_pago' => null
        ];

        foreach ($pagos as $pago) {
            $stats['total_pagos']++;

            if ($pago->estado === self::ESTADO_SUCCEEDED) {
                $stats['pagos_exitosos']++;
                $stats['importe_total'] += $pago->importe;

                if ($stats['ultimo_pago'] === null) {
                    $stats['ultimo_pago'] = $pago->fecha_pago;
                }
            } elseif ($pago->estado === self::ESTADO_FAILED) {
                $stats['pagos_fallidos']++;
            }
        }

        return $stats;
    }
}
