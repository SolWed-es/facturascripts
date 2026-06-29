<?php
/**
 * Plugin SolwedES - Controlador Webhook Stripe Unificado
 *
 * Recibe eventos de Stripe y procesa según configuración:
 * - checkout.session.completed: Procesa pagos únicos y suscripciones
 * - invoice.paid: Genera factura para cualquier tipo de pago
 * - payment_intent.succeeded: Pagos directos sin checkout
 * - customer.subscription.deleted: Marca suscripción como cancelada
 * - customer.subscription.updated: Actualiza plan
 * - invoice.payment_failed: Notifica al cliente
 * - charge.refunded: Procesa reembolsos
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\Serie;
use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;
use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Model\PagoStripe;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Plugins\SolwedES\Lib\StripeHelper;
use FacturaScripts\Plugins\SolwedES\Lib\StripeSubscriptionManager;
use FacturaScripts\Plugins\SolwedES\Lib\AlbaranManager;
use FacturaScripts\Plugins\SolwedES\Lib\EmailManager;
use FacturaScripts\Plugins\SolwedES\Lib\SolwedLogger;
use FacturaScripts\Plugins\SolwedES\Lib\DonDominioHelper;
use FacturaScripts\Plugins\SolwedES\Lib\StripeUtils;
use FacturaScripts\Plugins\SolwedES\Lib\WordPressProvisioner;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;

class StripeWebhook extends Controller
{
    /** @var \FacturaScripts\Core\Base\DataBase */
    private $db;

    public function __construct(string $className = '', string $uri = '')
    {
        parent::__construct($className, $uri);
        $this->db = new \FacturaScripts\Core\Base\DataBase();
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'Stripe Webhook';
        $data['showonmenu'] = false;
        return $data;
    }

    /**
     * Punto de entrada público (no requiere autenticación)
     */
    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        SolwedLogger::stripe('=== WEBHOOK REQUEST RECEIVED ===');

        // Obtener payload raw
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (empty($payload) || empty($signature)) {
            SolwedLogger::error('Missing payload or signature');
            $this->sendJsonResponse(['error' => 'Missing payload or signature'], 400);
            return;
        }

        SolwedLogger::stripe('Payload length: ' . strlen($payload));

        // Verificar configuración
        if (!StripeHelper::isConfigured()) {
            SolwedLogger::error('Stripe webhook not configured - check StripeHelper::isConfigured()');
            $this->sendJsonResponse(['error' => 'Webhook not configured'], 500);
            return;
        }

        SolwedLogger::stripe('Stripe is configured, verifying signature...');

        try {
            // Verificar firma usando StripeHelper
            $event = StripeHelper::verifyWebhookSignature($payload, $signature);

            SolwedLogger::stripe(sprintf(
                'Event verified: %s (ID: %s)',
                $event->type,
                $event->id
            ));

            // Procesar evento
            $result = $this->processEvent($event);

            SolwedLogger::stripe('Event processed', $result);

            if ($result['success']) {
                $this->sendJsonResponse($result);
            } else {
                $this->sendJsonResponse($result, 400);
            }
        } catch (Exception $e) {
            SolwedLogger::error('Webhook exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            $this->sendJsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Procesa el evento de Stripe
     */
    private function processEvent(object $event): array
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                return $this->handleCheckoutCompleted($event->data->object);

            case 'invoice.paid':
                return $this->handleInvoicePaid($event->data->object);

            case 'payment_intent.succeeded':
                return $this->handlePaymentIntentSucceeded($event->data->object);

            case 'customer.subscription.deleted':
                return $this->handleSubscriptionDeleted($event->data->object);

            case 'customer.subscription.updated':
                return $this->handleSubscriptionUpdated($event->data->object);

            case 'invoice.payment_failed':
                return $this->handlePaymentFailed($event->data->object);

            case 'charge.refunded':
                return $this->handleChargeRefunded($event->data->object);

            default:
                SolwedLogger::stripe('Unhandled event: ' . $event->type);
                return ['success' => true, 'message' => 'Event not handled'];
        }
    }

    /**
     * Maneja checkout.session.completed - Tanto suscripciones como pagos únicos
     */
    private function handleCheckoutCompleted(object $session): array
    {
        SolwedLogger::stripe('=== HANDLING checkout.session.completed ===');
        SolwedLogger::stripe('Session ID: ' . $session->id);
        SolwedLogger::stripe('Mode: ' . $session->mode);
        SolwedLogger::stripe('Payment Status: ' . ($session->payment_status ?? 'N/A'));
        SolwedLogger::stripe('Customer: ' . ($session->customer ?? 'N/A'));

        // Verificar si ya procesamos esta sesión
        $existingPago = PagoStripe::getByCheckoutSession($session->id);
        if ($existingPago) {
            SolwedLogger::stripe('Session already processed, skipping');
            return ['success' => true, 'message' => 'Session already processed'];
        }

        if ($session->mode === 'subscription') {
            return $this->handleSubscriptionCheckout($session);
        } elseif ($session->mode === 'payment') {
            return $this->handlePaymentCheckout($session);
        }

        return ['success' => true, 'message' => 'Unknown checkout mode: ' . $session->mode];
    }

    /**
     * Procesa checkout de suscripción
     * Uses database transactions to ensure data integrity.
     */
    private function handleSubscriptionCheckout(object $session): array
    {
        SolwedLogger::stripe('Processing SUBSCRIPTION checkout');

        // Check for type-specific handling
        $type = $session->metadata->type ?? null;

        // DEBUG: Log all metadata received
        SolwedLogger::stripe('DEBUG: Session metadata type = ' . ($type ?? 'NULL'));
        SolwedLogger::stripe('DEBUG: Full metadata = ' . json_encode($session->metadata ?? []));

        // Domain auto-renewal subscriptions
        if ($type === 'domain_auto_renewal') {
            return $this->handleDomainAutoRenewalCheckout($session);
        }

        // WordPress hosting subscriptions
        if ($type === 'wordpress_hosting') {
            SolwedLogger::stripe('DEBUG: Detected wordpress_hosting type, routing to handleWordPressHostingCheckout');
            return $this->handleWordPressHostingCheckout($session);
        }

        // Verificar si ya existe el contrato para esta suscripción
        $existingContrato = Suscripcion::getByStripeSubscriptionId($session->subscription);
        if ($existingContrato) {
            return ['success' => true, 'message' => 'Contract already exists'];
        }

        $idcontacto = $session->metadata->idcontacto ?? null;
        $idservicio = $session->metadata->idservicio ?? null;

        if (!$idcontacto || !$idservicio) {
            // Intentar buscar/crear contacto por email
            $contacto = $this->findOrCreateContactoFromSession($session);
            if ($contacto) {
                $idcontacto = $contacto->idcontacto;
            }
        }

        if (!$idcontacto) {
            SolwedLogger::error('Missing idcontacto in checkout session metadata and could not create');
            return ['success' => false, 'error' => 'Missing contact'];
        }

        // Obtener detalles de la suscripción desde Stripe
        $stripeSubscription = StripeSubscriptionManager::getSubscription($session->subscription);
        if (!$stripeSubscription) {
            return ['success' => false, 'error' => 'Could not get subscription details'];
        }

        // Calculate dates using StripeUtils for robust handling across API versions
        $fechaInicio = date('Y-m-d');
        if (isset($stripeSubscription->current_period_start) && $stripeSubscription->current_period_start > 0) {
            $fechaInicio = date('Y-m-d', $stripeSubscription->current_period_start);
        }

        // Use StripeUtils for robust end date calculation
        $fechaVencimiento = StripeUtils::getSubscriptionEndDate($stripeSubscription);
        SolwedLogger::stripe('Calculated fecha_vencimiento: ' . $fechaVencimiento);

        // Start transaction for multi-step data creation
        $this->db->beginTransaction();

        try {
            // Crear Suscripcion (modelo unificado)
            $suscripcion = new Suscripcion();
            $suscripcion->idcontacto = (int)$idcontacto;
            $suscripcion->idservicio = (int)$idservicio;
            $suscripcion->estado = Suscripcion::ESTADO_ACTIVO;
            $suscripcion->fecha_inicio = $fechaInicio;
            $suscripcion->fecha_vencimiento = $fechaVencimiento;
            $suscripcion->fecha_ultimo_pago = $session->payment_status === 'paid' ? date('Y-m-d') : null;
            $suscripcion->fecha_proximo_pago = $fechaVencimiento;
            $suscripcion->metodo_pago = Suscripcion::METODO_STRIPE;
            $suscripcion->referencia_externa = $session->subscription;
            $suscripcion->stripe_customer_id = $session->customer;
            $suscripcion->auto_renovar = !($stripeSubscription->cancel_at_period_end ?? false);
            $suscripcion->importe = ($stripeSubscription->items->data[0]->price->unit_amount ?? 0) / 100;

            if (!$suscripcion->save()) {
                throw new Exception('Failed to create Suscripcion');
            }

            SolwedLogger::stripe('Suscripcion created: ' . $suscripcion->id . ' for subscription: ' . $session->subscription);

            // Crear acceso al servicio
            if ($idservicio) {
                $acceso = AccesoServicio::getByClienteServicio((int)$idcontacto, (int)$idservicio);
                if (!$acceso) {
                    $servicio = new Servicio();
                    if ($servicio->load($idservicio)) {
                        $acceso = new AccesoServicio();
                        $acceso->idcontacto = (int)$idcontacto;
                        $acceso->idservicio = (int)$idservicio;
                        $acceso->activo = true;
                        $acceso->tipo_acceso = 'otro';
                        $acceso->url_acceso = 'https://portal.solwed.es';
                        if (!$acceso->save()) {
                            throw new Exception('Failed to create AccesoServicio');
                        }
                        SolwedLogger::stripe('Service access created for service: ' . $servicio->nombre);
                    }
                }
            }

            // Crear PagoStripe inicial (será actualizado cuando llegue invoice.paid)
            $pago = new PagoStripe();
            $pago->idcontacto = (int)$idcontacto;
            $pago->idservicio = $idservicio ? (int)$idservicio : null;
            $pago->stripe_payment_intent = $session->payment_intent ?? 'pending_' . $session->id;
            $pago->stripe_checkout_session = $session->id;
            $pago->stripe_customer_id = $session->customer;
            $pago->tipo = PagoStripe::TIPO_SUBSCRIPTION;
            $pago->concepto = $this->getConceptoFromSession($session);
            $pago->importe = ($session->amount_total ?? 0) / 100;
            $pago->moneda = strtoupper($session->currency ?? 'EUR');
            $pago->estado = $session->payment_status === 'paid' ? PagoStripe::ESTADO_SUCCEEDED : PagoStripe::ESTADO_PENDING;

            if ($session->payment_status === 'paid') {
                $pago->fecha_pago = date('Y-m-d H:i:s');
            }

            if (!$pago->save()) {
                throw new Exception('Failed to create PagoStripe');
            }
            SolwedLogger::stripe('PagoStripe created: ' . $pago->id);

            // Commit transaction on success
            $this->db->commit();

            return [
                'success' => true,
                'contrato_id' => $suscripcion->id,
                'pago_id' => $pago->id
            ];
        } catch (\Throwable $e) {
            // Rollback on any error
            $this->db->rollBack();
            SolwedLogger::error('Transaction failed in handleSubscriptionCheckout: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Procesa checkout de pago único
     */
    private function handlePaymentCheckout(object $session): array
    {
        SolwedLogger::stripe('Processing ONE-TIME PAYMENT checkout');

        if ($session->payment_status !== 'paid') {
            SolwedLogger::stripe('Payment not completed yet, waiting for payment_intent.succeeded');
            return ['success' => true, 'message' => 'Payment not yet completed'];
        }

        // Check if this is a domain-related payment
        $type = $session->metadata->type ?? null;
        if ($type === 'domain_registration') {
            return $this->handleDomainRegistration($session);
        } elseif ($type === 'domain_renewal') {
            return $this->handleDomainRenewal($session);
        } elseif ($type === 'domain_auto_renewal') {
            return $this->handleDomainAutoRenewalCheckout($session);
        }

        // Buscar o crear contacto
        $idcontacto = $session->metadata->idcontacto ?? null;
        $idservicio = $session->metadata->idservicio ?? null;

        $contacto = null;
        if ($idcontacto) {
            $contacto = new Contacto();
            if (!$contacto->load($idcontacto)) {
                $contacto = null;
            }
        }

        if (!$contacto) {
            $contacto = $this->findOrCreateContactoFromSession($session);
        }

        if (!$contacto) {
            SolwedLogger::error('Could not find or create contact for checkout session');
            return ['success' => false, 'error' => 'Could not create contact'];
        }

        $idcontacto = $contacto->idcontacto;

        // Crear registro de pago
        $pago = new PagoStripe();
        $pago->idcontacto = $idcontacto;
        $pago->idservicio = $idservicio ? (int)$idservicio : null;
        $pago->stripe_payment_intent = $session->payment_intent ?? 'checkout_' . $session->id;
        $pago->stripe_checkout_session = $session->id;
        $pago->stripe_customer_id = $session->customer ?? 'checkout_customer';
        $pago->tipo = PagoStripe::TIPO_ONE_TIME;
        $pago->concepto = $this->getConceptoFromSession($session);
        $pago->importe = ($session->amount_total ?? 0) / 100;
        $pago->moneda = strtoupper($session->currency ?? 'EUR');
        $pago->estado = PagoStripe::ESTADO_SUCCEEDED;
        $pago->fecha_pago = date('Y-m-d H:i:s');

        // Obtener detalles del método de pago
        if (!empty($session->payment_intent)) {
            $paymentDetails = StripeHelper::getPaymentMethodDetails($session->payment_intent);
            if ($paymentDetails['success']) {
                $pago->setMetodoPago($paymentDetails);
            }
        }

        // Guardar metadata
        $customerEmail = null;
        if (isset($session->customer_email)) {
            $customerEmail = $session->customer_email;
        } elseif (isset($session->customer_details) && isset($session->customer_details->email)) {
            $customerEmail = $session->customer_details->email;
        }

        $pago->setMetadata([
            'line_items' => $this->getLineItemsFromSession($session),
            'customer_email' => $customerEmail
        ]);

        if (!$pago->save()) {
            SolwedLogger::error('Could not save PagoStripe for one-time payment');
            return ['success' => false, 'error' => 'Could not save payment'];
        }

        SolwedLogger::stripe('PagoStripe created for one-time payment: ' . $pago->id);

        // Crear factura
        $result = ['success' => true, 'pago_id' => $pago->id];

        if (StripeHelper::getSetting('crear_factura', true)) {
            $factura = $this->createFacturaFromPago($pago, $session);
            if ($factura) {
                $pago->linkToFactura($factura->idfactura);
                $result['factura'] = $factura->codigo;
                SolwedLogger::stripe('Factura created: ' . $factura->codigo);

                // Enviar email
                if (StripeHelper::getSetting('enviar_email', true)) {
                    $paymentDetails = $this->getPaymentDetailsWithFallback($pago);
                    $emailResult = EmailManager::sendFacturaEmail($factura, $paymentDetails, $contacto);
                    $result['email_sent'] = $emailResult['success'] ?? false;
                }
            }
        }

        // Crear acceso al servicio si corresponde
        if ($idservicio) {
            $this->createServiceAccessIfNeeded((int)$idcontacto, (int)$idservicio);
        }

        return $result;
    }

    /**
     * Maneja invoice.paid - Para cualquier tipo de factura (suscripción o no)
     * Uses database transactions to ensure data integrity.
     */
    private function handleInvoicePaid(object $invoice): array
    {
        SolwedLogger::stripe('=== HANDLING invoice.paid ===');
        SolwedLogger::stripe('Invoice ID: ' . ($invoice->id ?? 'N/A'));
        SolwedLogger::stripe('Subscription ID: ' . ($invoice->subscription ?? 'NONE'));
        SolwedLogger::stripe('Customer email: ' . ($invoice->customer_email ?? 'N/A'));
        SolwedLogger::stripe('Amount paid: ' . (($invoice->amount_paid ?? 0) / 100) . ' ' . ($invoice->currency ?? 'N/A'));

        // Check if this is a domain auto-renewal subscription (via Suscripcion)
        $subscriptionId = $invoice->subscription ?? null;
        if ($subscriptionId) {
            $suscripcion = Suscripcion::getByStripeSubscriptionId($subscriptionId);
            if ($suscripcion) {
                // Check if it's a domain-related contract
                $dominios = $suscripcion->getDominios();
                if (!empty($dominios)) {
                    SolwedLogger::stripe('This is a domain subscription renewal for contract: ' . $suscripcion->id);
                    return $this->handleDomainSubscriptionRenewal($invoice, $subscriptionId);
                }
            }
        }

        // Verificar si ya procesamos este pago (idempotencia)
        // Check by invoice_id first (most reliable), then by payment_intent
        $existingPago = null;
        if (!empty($invoice->id)) {
            $existingPago = PagoStripe::getByInvoiceId($invoice->id);
            if ($existingPago && $existingPago->isSucceeded()) {
                SolwedLogger::stripe('Invoice already processed, skipping');
                return ['success' => true, 'message' => 'Already processed'];
            }
        }

        $paymentIntentId = $invoice->payment_intent ?? '';

        // Determinar tipo de pago
        $isSubscription = !empty($invoice->subscription);
        $tipo = $isSubscription ? PagoStripe::TIPO_SUBSCRIPTION : PagoStripe::TIPO_ONE_TIME;

        SolwedLogger::stripe('Payment type: ' . $tipo);

        // Buscar contacto
        $contacto = $this->findContactoByEmail($invoice->customer_email ?? '');
        $idcontacto = $contacto ? $contacto->idcontacto : null;

        // Si es suscripción, buscar datos de contrato local
        $suscripcion = null;
        $idservicio = null;

        if ($isSubscription) {
            $suscripcion = Suscripcion::getByStripeSubscriptionId($invoice->subscription);
            if ($suscripcion) {
                $idcontacto = $suscripcion->idcontacto;
                $idservicio = $suscripcion->idservicio;
                $contacto = $suscripcion->getContacto();
            }
        }

        // Si no tenemos contacto, intentar crear uno
        if (!$contacto && !empty($invoice->customer_email)) {
            $contacto = $this->findOrCreateContactoFromInvoice($invoice);
            if ($contacto) {
                $idcontacto = $contacto->idcontacto;
            }
        }

        if (!$idcontacto) {
            SolwedLogger::error('Could not find or create contact for invoice');
            return ['success' => false, 'error' => 'Contact not found'];
        }

        // Start transaction for multi-step data creation
        $this->db->beginTransaction();

        try {
            $result = [
                'success' => true,
                'factura' => null,
                'albaran' => null,
                'email_sent' => false,
                'pago_id' => null
            ];

            // Buscar o crear PagoStripe
            $pago = null;
            if (!empty($paymentIntentId)) {
                $pago = PagoStripe::getByPaymentIntent($paymentIntentId);
            }

            if (!$pago) {
                $pago = new PagoStripe();
                $pago->idcontacto = $idcontacto ?? 0;
                $pago->idservicio = $idservicio;
                $pago->stripe_payment_intent = $paymentIntentId ?: 'invoice_' . $invoice->id;
                $pago->stripe_invoice_id = $invoice->id;
                $pago->stripe_customer_id = $invoice->customer ?? 'invoice_customer';
                $pago->tipo = $tipo;
                $pago->concepto = $this->getConceptoFromInvoice($invoice);
                $pago->importe = ($invoice->amount_paid ?? 0) / 100;
                $pago->moneda = strtoupper($invoice->currency ?? 'EUR');
            }

            // Actualizar estado
            $pago->estado = PagoStripe::ESTADO_SUCCEEDED;
            $pago->fecha_pago = date('Y-m-d H:i:s');
            $pago->stripe_invoice_id = $invoice->id;

            // Obtener detalles del método de pago
            if (!empty($paymentIntentId)) {
                $paymentDetails = StripeHelper::getPaymentMethodDetails($paymentIntentId);
                if ($paymentDetails['success']) {
                    $pago->setMetodoPago($paymentDetails);
                }
            }

            if (!$pago->save()) {
                throw new Exception('Failed to save PagoStripe');
            }
            $result['pago_id'] = $pago->id;
            SolwedLogger::stripe('PagoStripe saved: ' . $pago->id);

            // Crear factura si está habilitado
            if (StripeHelper::getSetting('crear_factura', true)) {
                $factura = $this->createFacturaFromInvoice($invoice, $contacto, $suscripcion);
                if ($factura) {
                    $pago->linkToFactura($factura->idfactura);
                    $result['factura'] = $factura->codigo;
                    SolwedLogger::stripe('Factura created: ' . $factura->codigo);
                }
            }

            // Crear albarán si está habilitado
            if (StripeHelper::getSetting('crear_albaran', true)) {
                $albaran = AlbaranManager::createAlbaranFromStripe($invoice);
                if ($albaran) {
                    $pago->linkToAlbaran($albaran->idalbaran);
                    $result['albaran'] = $albaran->codigo;
                    $result['total'] = $albaran->total;
                    SolwedLogger::stripe('Albaran created: ' . $albaran->codigo);
                }
            }

            // Actualizar Suscripcion si es suscripción
            if ($suscripcion && isset($invoice->lines->data[0]->period->end)) {
                $proximoCobro = date('Y-m-d', $invoice->lines->data[0]->period->end);
                $suscripcion->estado = Suscripcion::ESTADO_ACTIVO;
                $suscripcion->fecha_ultimo_pago = date('Y-m-d');
                $suscripcion->fecha_proximo_pago = $proximoCobro;
                $suscripcion->fecha_vencimiento = $proximoCobro;
                if (!$suscripcion->save()) {
                    throw new Exception('Failed to update Suscripcion');
                }
                SolwedLogger::stripe('Suscripcion updated on invoice.paid: ' . $suscripcion->id);
            }

            // Commit transaction on success
            $this->db->commit();

            // Send email outside of transaction (non-critical)
            if (StripeHelper::getSetting('enviar_email', true) && $contacto && isset($albaran)) {
                $paymentDetails = $this->getPaymentDetailsWithFallback($pago);
                $emailResult = EmailManager::sendAlbaranEmail($albaran, $paymentDetails, $contacto);
                $result['email_sent'] = $emailResult['success'] ?? false;
            }

            return $result;
        } catch (\Throwable $e) {
            // Rollback on any error
            $this->db->rollBack();
            SolwedLogger::error('Transaction failed in handleInvoicePaid: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Maneja payment_intent.succeeded - Para pagos directos sin checkout/invoice
     *
     * NOTE: For subscription payments, we skip this handler and let invoice.paid handle it
     * to avoid creating duplicate PagoStripe records.
     */
    private function handlePaymentIntentSucceeded(object $paymentIntent): array
    {
        SolwedLogger::stripe('=== HANDLING payment_intent.succeeded ===');
        SolwedLogger::stripe('PaymentIntent ID: ' . $paymentIntent->id);
        SolwedLogger::stripe('Amount: ' . ($paymentIntent->amount / 100) . ' ' . $paymentIntent->currency);

        // Skip if this payment is associated with an invoice (subscription payments)
        // Let invoice.paid handle these to avoid duplicate PagoStripe records
        if (!empty($paymentIntent->invoice)) {
            SolwedLogger::stripe('PaymentIntent has invoice, skipping (will be handled by invoice.paid)');
            return ['success' => true, 'message' => 'Skipped - has invoice'];
        }

        // Verificar si ya procesamos este pago
        $existingPago = PagoStripe::getByPaymentIntent($paymentIntent->id);
        if ($existingPago && $existingPago->isSucceeded()) {
            SolwedLogger::stripe('PaymentIntent already processed, skipping');
            return ['success' => true, 'message' => 'Already processed'];
        }

        // Si el pago ya existe pero no está completado, actualizarlo
        if ($existingPago) {
            $existingPago->markAsSucceeded();

            // Obtener detalles del método de pago
            $paymentDetails = StripeHelper::getPaymentMethodDetails($paymentIntent->id);
            if ($paymentDetails['success']) {
                $existingPago->setMetodoPago($paymentDetails);
                $existingPago->save();
            }

            SolwedLogger::stripe('Existing PagoStripe updated to succeeded');
            return ['success' => true, 'pago_id' => $existingPago->id];
        }

        // Si no existe, es un pago directo sin checkout/invoice
        // Intentar obtener información del cliente
        $customerEmail = null;
        $customerId = $paymentIntent->customer ?? null;

        if ($customerId) {
            try {
                $customerResult = \FacturaScripts\Plugins\SolwedES\Lib\BridgeClient::get('/stripe/customers/' . $customerId);
                $customerEmail = $customerResult['data']['email'] ?? null;
            } catch (Exception $e) {
                SolwedLogger::stripe('Could not retrieve customer: ' . $e->getMessage());
            }
        }

        // Buscar contacto
        $contacto = null;
        $idcontacto = $paymentIntent->metadata->idcontacto ?? null;

        if ($idcontacto) {
            $contacto = new Contacto();
            if (!$contacto->load($idcontacto)) {
                $contacto = null;
            }
        }

        if (!$contacto && $customerEmail) {
            $contacto = $this->findContactoByEmail($customerEmail);
        }

        if (!$contacto) {
            SolwedLogger::stripe('No contact found for direct payment, creating minimal record');
            // Crear registro de pago sin contacto (se puede vincular después)
        }

        // Crear PagoStripe
        $pago = new PagoStripe();
        $pago->idcontacto = $contacto ? $contacto->idcontacto : 0;
        $pago->idservicio = isset($paymentIntent->metadata->idservicio) ? (int)$paymentIntent->metadata->idservicio : null;
        $pago->stripe_payment_intent = $paymentIntent->id;
        $pago->stripe_customer_id = $customerId ?? 'direct_payment';
        $pago->tipo = PagoStripe::TIPO_ONE_TIME;
        $pago->concepto = $paymentIntent->description ?? 'Pago directo Stripe';
        $pago->importe = $paymentIntent->amount / 100;
        $pago->moneda = strtoupper($paymentIntent->currency ?? 'EUR');
        $pago->estado = PagoStripe::ESTADO_SUCCEEDED;
        $pago->fecha_pago = date('Y-m-d H:i:s');

        // Obtener detalles del método de pago
        $paymentDetails = StripeHelper::getPaymentMethodDetails($paymentIntent->id);
        if ($paymentDetails['success']) {
            $pago->setMetodoPago($paymentDetails);
        }

        if (!$pago->save()) {
            SolwedLogger::error('Could not save PagoStripe for direct payment');
            return ['success' => false, 'error' => 'Could not save payment'];
        }
        SolwedLogger::stripe('PagoStripe created for direct payment: ' . $pago->id);

        $result = ['success' => true, 'pago_id' => $pago->id];

        // Crear factura si tenemos contacto
        if ($contacto && StripeHelper::getSetting('crear_factura', true)) {
            $factura = $this->createFacturaFromPaymentIntent($paymentIntent, $contacto);
            if ($factura) {
                $pago->linkToFactura($factura->idfactura);
                $result['factura'] = $factura->codigo;
            }
        }

        return $result;
    }

    /**
     * Maneja charge.refunded - Procesa reembolsos completos
     * Uses database transactions to ensure data integrity.
     */
    private function handleChargeRefunded(object $charge): array
    {
        SolwedLogger::stripe('=== HANDLING charge.refunded ===');
        SolwedLogger::stripe('Charge ID: ' . $charge->id);
        SolwedLogger::stripe('PaymentIntent: ' . ($charge->payment_intent ?? 'N/A'));
        SolwedLogger::stripe('Amount: ' . ($charge->amount / 100));
        SolwedLogger::stripe('Amount refunded: ' . ($charge->amount_refunded / 100));

        $paymentIntentId = $charge->payment_intent ?? '';
        if (empty($paymentIntentId)) {
            return ['success' => true, 'message' => 'No payment intent in charge'];
        }

        // Try to find payment by charge ID first, then by payment intent
        $pago = PagoStripe::getByPaymentIntent($paymentIntentId);
        if (!$pago) {
            SolwedLogger::stripe('No PagoStripe found for refunded charge');
            return ['success' => true, 'message' => 'Payment not found locally'];
        }

        // Determinar si es reembolso total o parcial
        $isFullRefund = $charge->amount_refunded >= $charge->amount;
        $refundAmount = $charge->amount_refunded / 100;
        $currency = strtoupper($charge->currency ?? 'EUR');

        // Start transaction for multi-step refund processing
        $this->db->beginTransaction();

        try {
            $result = [
                'success' => true,
                'pago_id' => $pago->id,
                'refund_type' => $isFullRefund ? 'full' : 'partial',
                'refund_amount' => $refundAmount,
                'credit_memo' => null,
                'contract_canceled' => false,
            ];

            // 1. Update payment status
            $pago->markAsRefunded(!$isFullRefund);
            SolwedLogger::stripe(sprintf(
                'Payment %d marked as %s',
                $pago->id,
                $isFullRefund ? 'refunded' : 'partially refunded'
            ));

            // 2. Create credit memo (factura rectificativa) if original invoice exists
            if ($pago->idfactura) {
                $factura = new FacturaCliente();
                if ($factura->load($pago->idfactura)) {
                    $creditMemo = $this->createCreditMemo($factura, $refundAmount, $charge->id);
                    if ($creditMemo) {
                        $result['credit_memo'] = $creditMemo->codigo;
                        SolwedLogger::stripe('Credit memo created: ' . $creditMemo->codigo);
                    }
                }
            }

            // 3. Cancel related contract if full refund
            if ($isFullRefund) {
                $contractCanceled = $this->cancelRelatedContract($pago);
                $result['contract_canceled'] = $contractCanceled;
            }

            // Commit transaction on success
            $this->db->commit();

            // 4. Send notification email (outside transaction - non-critical)
            $this->sendRefundNotification($pago, $charge, $isFullRefund);

            return $result;
        } catch (\Throwable $e) {
            // Rollback on any error
            $this->db->rollBack();
            SolwedLogger::error('Transaction failed in handleChargeRefunded: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Creates a credit memo (factura rectificativa) for a refund
     */
    private function createCreditMemo(FacturaCliente $originalFactura, float $refundAmount, string $chargeId): ?FacturaCliente
    {
        try {
            // Load the client
            $cliente = new Cliente();
            if (!$cliente->load($originalFactura->codcliente)) {
                SolwedLogger::error('Could not load client for credit memo');
                return null;
            }

            // Create rectificative invoice
            $creditMemo = new FacturaCliente();
            $creditMemo->setSubject($cliente);
            $creditMemo->codserie = $originalFactura->codserie;
            $creditMemo->idfacturarect = $originalFactura->idfactura;
            $creditMemo->codigorect = $originalFactura->codigo;
            $creditMemo->observaciones = sprintf(
                'Factura rectificativa por reembolso Stripe. Charge ID: %s. Factura original: %s',
                $chargeId,
                $originalFactura->codigo
            );

            if (!$creditMemo->save()) {
                SolwedLogger::error('Could not save credit memo header');
                return null;
            }

            // Add single line with negative amount
            $linea = $creditMemo->getNewLine();
            $linea->descripcion = 'Reembolso - ' . $originalFactura->codigo;
            $linea->pvpunitario = -$refundAmount;
            $linea->cantidad = 1;

            // Copy IVA from original invoice lines if available
            $originalLines = $originalFactura->getLines();
            if (!empty($originalLines)) {
                $linea->iva = $originalLines[0]->iva;
                $linea->codimpuesto = $originalLines[0]->codimpuesto;
            }

            if (!$linea->save()) {
                SolwedLogger::error('Could not save credit memo line');
                $creditMemo->delete();
                return null;
            }

            // Recalculate totals
            $lines = $creditMemo->getLines();
            \FacturaScripts\Core\Lib\Calculator::calculate($creditMemo, $lines, true);

            return $creditMemo;
        } catch (Exception $e) {
            SolwedLogger::error('Exception creating credit memo: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cancels the contract associated with a refunded payment
     */
    private function cancelRelatedContract(PagoStripe $pago): bool
    {
        // Find contract by idservicio and idcontacto (since pago may not have direct contrato link)
        if (!$pago->idservicio || !$pago->idcontacto) {
            return false;
        }

        $suscripcion = new Suscripcion();
        $where = [
            new DataBaseWhere('idcontacto', $pago->idcontacto),
            new DataBaseWhere('idservicio', $pago->idservicio),
            new DataBaseWhere('estado', Suscripcion::ESTADO_ACTIVO),
        ];
        $suscripcions = $suscripcion->all($where, ['id' => 'DESC'], 0, 1);

        if (empty($suscripcions)) {
            SolwedLogger::stripe('No active contract found to cancel for refunded payment');
            return false;
        }

        $suscripcion = $suscripcions[0];
        $suscripcion->estado = Suscripcion::ESTADO_CANCELADO;
        $suscripcion->notas = ($suscripcion->notas ?? '') . "\n[" . date('Y-m-d H:i:s') . "] Cancelado por reembolso completo";

        if (!$suscripcion->save()) {
            SolwedLogger::error('Could not cancel contract after refund');
            return false;
        }

        SolwedLogger::stripe('Contract ' . $suscripcion->id . ' canceled due to full refund');

        // Also deactivate service access
        if ($suscripcion->idcontacto && $suscripcion->idservicio) {
            $acceso = AccesoServicio::getByClienteServicio($suscripcion->idcontacto, $suscripcion->idservicio);
            if ($acceso) {
                $acceso->activo = false;
                $acceso->save();
                SolwedLogger::stripe('Service access deactivated after refund');
            }
        }

        return true;
    }

    /**
     * Sends email notification about a refund
     */
    private function sendRefundNotification(PagoStripe $pago, object $charge, bool $isFullRefund): void
    {
        try {
            $contacto = $pago->getContacto();
            if (!$contacto || empty($contacto->email)) {
                return;
            }

            $refundAmount = $charge->amount_refunded / 100;
            $currency = strtoupper($charge->currency ?? 'EUR');

            $subject = $isFullRefund
                ? 'Confirmación de reembolso completo'
                : 'Confirmación de reembolso parcial';

            $body = sprintf(
                "Hola %s,\n\n" .
                "Te confirmamos que hemos procesado un %s por %s %.2f.\n\n" .
                "Referencia del pago: %s\n" .
                "Fecha: %s\n\n" .
                "El importe será devuelto a tu método de pago original en 5-10 días hábiles.\n\n" .
                "Si tienes alguna pregunta, no dudes en contactarnos.\n\n" .
                "Saludos,\nEquipo SOLWED",
                $contacto->nombre ?? 'Cliente',
                $isFullRefund ? 'reembolso completo' : 'reembolso parcial',
                $currency,
                $refundAmount,
                $pago->concepto ?? 'N/A',
                date('d/m/Y H:i')
            );

            EmailManager::sendSimpleEmail($contacto->email, $subject, $body);
            SolwedLogger::stripe('Refund notification email sent to: ' . $contacto->email);
        } catch (Exception $e) {
            // Email failure is non-critical
            SolwedLogger::stripe('Could not send refund notification: ' . $e->getMessage());
        }
    }

    /**
     * Sends alert to admin when domain renewal fails after payment
     */
    private function sendDomainRenewalFailureAlert(Dominio $dominio, string $error, object $invoice): void
    {
        try {
            $adminEmail = StripeHelper::getSetting('admin_email', 'admin@solwed.es');
            $amount = ($invoice->amount_paid ?? 0) / 100;
            $currency = strtoupper($invoice->currency ?? 'EUR');

            $subject = "⚠️ URGENTE: Renovación de dominio fallida - {$dominio->getNombreCompleto()}";
            $body = \sprintf(
                "ALERTA: Fallo en renovación automática de dominio\n\n" .
                "Dominio: %s\n" .
                "Error: %s\n" .
                "Invoice ID: %s\n" .
                "Importe cobrado: %.2f %s\n" .
                "Fecha: %s\n\n" .
                "ACCIÓN REQUERIDA: El cliente ha sido cobrado pero el dominio NO se ha renovado.\n" .
                "Por favor, renueve manualmente el dominio o procese un reembolso.",
                $dominio->getNombreCompleto(),
                $error,
                $invoice->id ?? 'N/A',
                $amount,
                $currency,
                date('d/m/Y H:i')
            );

            EmailManager::sendSimpleEmail($adminEmail, $subject, $body);
            SolwedLogger::stripe('Domain renewal failure alert sent to admin');
        } catch (Exception $e) {
            SolwedLogger::error('Could not send domain renewal failure alert: ' . $e->getMessage());
        }
    }

    /**
     * Maneja customer.subscription.deleted
     */
    private function handleSubscriptionDeleted(object $subscription): array
    {
        SolwedLogger::stripe('=== HANDLING customer.subscription.deleted ===');
        SolwedLogger::stripe('Subscription ID: ' . $subscription->id);

        // Find Suscripcion by subscription ID
        $suscripcion = Suscripcion::getByStripeSubscriptionId($subscription->id);

        if (!$suscripcion) {
            SolwedLogger::stripe('No Suscripcion found for subscription: ' . $subscription->id);
            return ['success' => true, 'message' => 'Contract not found locally'];
        }

        // Check if this is a domain subscription
        $dominios = $suscripcion->getDominios();
        if (!empty($dominios)) {
            SolwedLogger::stripe('This is a domain subscription for contract: ' . $suscripcion->id);

            // Update each linked domain
            foreach ($dominios as $dominio) {
                $dominio->observaciones = ($dominio->observaciones ?? '') .
                    "\n[" . date('Y-m-d') . "] Auto-renovación cancelada (suscripción eliminada)";
                $dominio->save();
            }

            SolwedLogger::stripe('Domain auto-renewal canceled for contract: ' . $suscripcion->id);
        }

        // Desactivar acceso al servicio
        if ($suscripcion->idservicio && $suscripcion->idcontacto) {
            $acceso = AccesoServicio::getByClienteServicio($suscripcion->idcontacto, $suscripcion->idservicio);
            if ($acceso) {
                $acceso->activo = false;
                $acceso->save();
                SolwedLogger::stripe('Service access deactivated');
            }
        }

        // Marcar contrato como cancelado
        $suscripcion->estado = Suscripcion::ESTADO_CANCELADO;
        $suscripcion->save();

        SolwedLogger::stripe('Subscription canceled: ' . $subscription->id);
        SolwedLogger::stripe('Suscripcion canceled: ' . $suscripcion->id);

        return [
            'success' => true,
            'contrato_id' => $suscripcion->id
        ];
    }

    /**
     * Maneja customer.subscription.updated
     */
    private function handleSubscriptionUpdated(object $subscription): array
    {
        $suscripcion = Suscripcion::getByStripeSubscriptionId($subscription->id);
        if (!$suscripcion) {
            SolwedLogger::stripe('No Suscripcion found for subscription: ' . $subscription->id);
            return ['success' => true, 'message' => 'Contract not found locally'];
        }

        SolwedLogger::stripe('=== HANDLING customer.subscription.updated ===');
        SolwedLogger::stripe('Subscription ID: ' . $subscription->id);
        SolwedLogger::stripe('Status: ' . $subscription->status);

        // Map Stripe status using StripeUtils
        $suscripcion->estado = StripeUtils::mapStripeStatusToSuscripcion($subscription->status);

        // Update dates using StripeUtils for robust calculation across API versions
        $fechaVencimiento = StripeUtils::getSubscriptionEndDate($subscription);
        $suscripcion->fecha_vencimiento = $fechaVencimiento;
        $suscripcion->fecha_proximo_pago = $fechaVencimiento;
        SolwedLogger::stripe('Updated dates: ' . $suscripcion->fecha_vencimiento);

        $suscripcion->auto_renovar = !($subscription->cancel_at_period_end ?? false);
        $suscripcion->importe = StripeUtils::formatAmount(
            $subscription->items->data[0]->price->unit_amount ?? 0,
            $subscription->currency ?? 'eur'
        );
        SolwedLogger::stripe('Updated importe: ' . $suscripcion->importe);

        // Detectar cambio de plan
        if (isset($subscription->items->data[0]->price->id)) {
            $newPriceId = $subscription->items->data[0]->price->id;
            $newServiceId = null;
            $newServiceName = null;

            // First try new ServicioPrecio table
            $servicioPrecio = new \FacturaScripts\Plugins\SolwedES\Model\ServicioPrecio();
            $wherePrecio = [new DataBaseWhere('stripe_price_id', $newPriceId)];
            $precios = $servicioPrecio->all($wherePrecio, [], 0, 1);

            if (!empty($precios)) {
                $newServiceId = $precios[0]->idservicio;
            } else {
                // Fallback to legacy Servicio table
                $servicioModel = new Servicio();
                $where = [new DataBaseWhere('stripe_price_id', $newPriceId)];
                $servicios = $servicioModel->all($where, [], 0, 1);

                if (!empty($servicios)) {
                    $newServiceId = $servicios[0]->id;
                }
            }

            if ($newServiceId && $newServiceId !== $suscripcion->idservicio) {
                $oldServiceId = $suscripcion->idservicio;
                $suscripcion->idservicio = $newServiceId;

                SolwedLogger::stripe(sprintf(
                    'Plan changed: %s from service %d to %d',
                    $subscription->id,
                    $oldServiceId,
                    $suscripcion->idservicio
                ));

                // Actualizar acceso al servicio
                $this->updateServiceAccess($suscripcion->idcontacto, $oldServiceId, $suscripcion->idservicio);
            }
        }

        $suscripcion->save();
        SolwedLogger::stripe('Suscripcion updated on subscription.updated: ' . $suscripcion->id);

        return [
            'success' => true,
            'contrato_id' => $suscripcion->id
        ];
    }

    /**
     * Maneja invoice.payment_failed
     */
    private function handlePaymentFailed(object $invoice): array
    {
        SolwedLogger::stripe('=== HANDLING invoice.payment_failed ===');

        $paymentIntentId = $invoice->payment_intent ?? '';

        // Buscar o crear PagoStripe
        $pago = null;
        if (!empty($paymentIntentId)) {
            $pago = PagoStripe::getByPaymentIntent($paymentIntentId);
        }

        if (!$pago) {
            // Crear registro del pago fallido
            $contacto = $this->findContactoByEmail($invoice->customer_email ?? '');

            $pago = new PagoStripe();
            $pago->idcontacto = $contacto ? $contacto->idcontacto : 0;
            $pago->stripe_payment_intent = $paymentIntentId ?: 'failed_' . $invoice->id;
            $pago->stripe_invoice_id = $invoice->id;
            $pago->stripe_customer_id = $invoice->customer ?? 'failed_payment';
            $pago->tipo = !empty($invoice->subscription) ? PagoStripe::TIPO_SUBSCRIPTION : PagoStripe::TIPO_ONE_TIME;
            $pago->concepto = $this->getConceptoFromInvoice($invoice);
            $pago->importe = ($invoice->amount_due ?? 0) / 100;
            $pago->moneda = strtoupper($invoice->currency ?? 'EUR');
        }

        $pago->markAsFailed();
        SolwedLogger::stripe('Payment marked as failed: ' . $pago->id);

        // Actualizar Suscripcion si aplica
        if (!empty($invoice->subscription)) {
            $suscripcion = Suscripcion::getByStripeSubscriptionId($invoice->subscription);
            if ($suscripcion) {
                $suscripcion->estado = Suscripcion::ESTADO_SUSPENDIDO;
                $suscripcion->save();
                SolwedLogger::stripe('Suscripcion suspended on payment_failed: ' . $suscripcion->id);

                $contacto = $suscripcion->getContacto();
                if ($contacto) {
                    SolwedLogger::error(sprintf(
                        'Payment failed for subscription %s - Contact: %s',
                        $invoice->subscription,
                        $contacto->email
                    ));
                }
            }
        }

        // Crear albarán de impago (se muestra en rojo en el listado) si crear_albaran está habilitado
        $result = ['success' => true, 'pago_id' => $pago->id];
        if (StripeHelper::getSetting('crear_albaran', true)) {
            $albaranImpago = AlbaranManager::createImpagoFromInvoice($invoice);
            if ($albaranImpago) {
                $result['albaran_impago'] = $albaranImpago->codigo;
                SolwedLogger::stripe('Impago albaran created: ' . $albaranImpago->codigo);
            }
        }

        return $result;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Crea factura desde invoice de Stripe
     */
    private function createFacturaFromInvoice(object $invoice, ?Contacto $contacto, ?Suscripcion $suscripcion): ?FacturaCliente
    {
        SolwedLogger::stripe('--- createFacturaFromInvoice START ---');

        // Obtener cliente
        $cliente = null;
        $servicio = null;

        if ($suscripcion) {
            $servicio = $suscripcion->getServicio();
        }

        if ($contacto && !empty($contacto->codcliente)) {
            $cliente = new Cliente();
            $cliente->load($contacto->codcliente);
        }

        if (!$cliente) {
            SolwedLogger::error('FACTURA FAILED: Could not find client');
            return null;
        }

        try {
            $factura = new FacturaCliente();
            $factura->setSubject($cliente);
            $factura->observaciones = sprintf(
                'Factura automática Stripe | Invoice: %s | PaymentIntent: %s',
                $invoice->id,
                $invoice->payment_intent ?? 'N/A'
            );

            // Serie desde configuración
            $serieCodigo = StripeHelper::getSetting('serie_factura', 'A');
            $serie = new Serie();
            if ($serie->load($serieCodigo)) {
                $factura->codserie = $serie->codserie;
            }

            // Marcar como pagada
            $factura->pc_paid = true;
            $factura->pc_created = true;
            $factura->pc_payment_intent_stripe = $invoice->payment_intent ?? '';

            if (!$factura->save()) {
                SolwedLogger::error('FACTURA SAVE FAILED');
                return null;
            }

            // Añadir líneas
            if (isset($invoice->lines) && isset($invoice->lines->data)) {
                foreach ($invoice->lines->data as $item) {
                    $linea = $factura->getNewLine();
                    $linea->descripcion = $item->description ?? ($servicio ? $servicio->nombre : 'Pago Stripe');
                    $linea->pvpunitario = $item->amount / 100;
                    $linea->cantidad = $item->quantity ?? 1;

                    if ($servicio && !empty($servicio->idproducto)) {
                        $linea->idproducto = $servicio->idproducto;
                    }

                    if (!$linea->save()) {
                        SolwedLogger::error('FACTURA LINE SAVE FAILED');
                        $factura->delete();
                        return null;
                    }
                }
            }

            // Recalcular totales
            $lines = $factura->getLines();
            \FacturaScripts\Core\Lib\Calculator::calculate($factura, $lines, true);

            SolwedLogger::stripe('Factura created: ' . $factura->codigo . ' | Total: ' . $factura->total);
            return $factura;
        } catch (Exception $e) {
            SolwedLogger::error('Exception in createFacturaFromInvoice: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crea factura desde PagoStripe (para pagos únicos de checkout)
     */
    private function createFacturaFromPago(PagoStripe $pago, object $session): ?FacturaCliente
    {
        $contacto = $pago->getContacto();
        if (!$contacto || empty($contacto->codcliente)) {
            SolwedLogger::error('Cannot create factura: no client linked to contact');
            return null;
        }

        $cliente = new Cliente();
        if (!$cliente->load($contacto->codcliente)) {
            return null;
        }

        try {
            $factura = new FacturaCliente();
            $factura->setSubject($cliente);
            $factura->observaciones = sprintf(
                'Factura automática Stripe | Checkout: %s | PaymentIntent: %s',
                $session->id,
                $session->payment_intent ?? 'N/A'
            );

            $serieCodigo = StripeHelper::getSetting('serie_factura', 'A');
            $serie = new Serie();
            if ($serie->load($serieCodigo)) {
                $factura->codserie = $serie->codserie;
            }

            $factura->pc_paid = true;
            $factura->pc_created = true;
            $factura->pc_payment_intent_stripe = $session->payment_intent ?? '';

            if (!$factura->save()) {
                return null;
            }

            // Añadir línea con el concepto del pago
            $linea = $factura->getNewLine();
            $linea->descripcion = $pago->concepto;
            $linea->pvpunitario = $pago->importe;
            $linea->cantidad = 1;

            if ($pago->idservicio) {
                $servicio = $pago->getServicio();
                if ($servicio && !empty($servicio->idproducto)) {
                    $linea->idproducto = $servicio->idproducto;
                }
            }

            if (!$linea->save()) {
                $factura->delete();
                return null;
            }

            $lines = $factura->getLines();
            \FacturaScripts\Core\Lib\Calculator::calculate($factura, $lines, true);

            return $factura;
        } catch (Exception $e) {
            SolwedLogger::error('Exception creating factura from pago: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crea factura desde PaymentIntent directo
     */
    private function createFacturaFromPaymentIntent(object $paymentIntent, Contacto $contacto): ?FacturaCliente
    {
        if (empty($contacto->codcliente)) {
            return null;
        }

        $cliente = new Cliente();
        if (!$cliente->load($contacto->codcliente)) {
            return null;
        }

        try {
            $factura = new FacturaCliente();
            $factura->setSubject($cliente);
            $factura->observaciones = sprintf(
                'Factura automática Stripe | PaymentIntent: %s',
                $paymentIntent->id
            );

            $serieCodigo = StripeHelper::getSetting('serie_factura', 'A');
            $serie = new Serie();
            if ($serie->load($serieCodigo)) {
                $factura->codserie = $serie->codserie;
            }

            $factura->pc_paid = true;
            $factura->pc_created = true;
            $factura->pc_payment_intent_stripe = $paymentIntent->id;

            if (!$factura->save()) {
                return null;
            }

            $linea = $factura->getNewLine();
            $linea->descripcion = $paymentIntent->description ?? 'Pago Stripe';
            $linea->pvpunitario = $paymentIntent->amount / 100;
            $linea->cantidad = 1;

            if (!$linea->save()) {
                $factura->delete();
                return null;
            }

            $lines = $factura->getLines();
            \FacturaScripts\Core\Lib\Calculator::calculate($factura, $lines, true);

            return $factura;
        } catch (Exception $e) {
            SolwedLogger::error('Exception creating factura from payment intent: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca contacto por email
     */
    private function findContactoByEmail(string $email): ?Contacto
    {
        if (empty($email)) {
            return null;
        }

        $contacto = new Contacto();
        $where = [new DataBaseWhere('email', $email)];
        $contactos = $contacto->all($where, [], 0, 1);

        return $contactos[0] ?? null;
    }

    /**
     * Busca o crea contacto desde sesión de checkout
     */
    private function findOrCreateContactoFromSession(object $session): ?Contacto
    {
        $email = $session->customer_email ?? $session->customer_details->email ?? null;

        if (empty($email)) {
            return null;
        }

        // Buscar existente
        $contacto = $this->findContactoByEmail($email);
        if ($contacto) {
            return $contacto;
        }

        // Crear nuevo
        return $this->createContactoFromStripeData(
            $email,
            $session->customer_details->name ?? null,
            $session->customer ?? null,
            $session->customer_details ?? null
        );
    }

    /**
     * Busca o crea contacto desde invoice
     */
    private function findOrCreateContactoFromInvoice(object $invoice): ?Contacto
    {
        $email = $invoice->customer_email ?? '';

        if (empty($email)) {
            return null;
        }

        $contacto = $this->findContactoByEmail($email);
        if ($contacto) {
            return $contacto;
        }

        return $this->createContactoFromStripeData(
            $email,
            $invoice->customer_name ?? null,
            $invoice->customer ?? null,
            null
        );
    }

    /**
     * Crea contacto y cliente desde datos de Stripe
     */
    private function createContactoFromStripeData(string $email, ?string $name, ?string $stripeCustomerId, ?object $customerDetails): ?Contacto
    {
        $this->db->beginTransaction();

        try {
            // Extraer nombre
            $customerName = $name;
            if (empty($customerName)) {
                $parts = explode('@', $email);
                $customerName = ucwords(str_replace(['.', '_', '-'], ' ', $parts[0]));
            }

            // Crear cliente
            $cliente = new Cliente();
            $cliente->nombre = $customerName;
            $cliente->razonsocial = $customerName;
            $cliente->email = $email;
            $cliente->cifnif = 'PENDIENTE';

            if ($customerDetails) {
                $cliente->telefono1 = $customerDetails->phone ?? '';
                if (isset($customerDetails->address)) {
                    $addr = $customerDetails->address;
                    $cliente->direccion = $addr->line1 ?? '';
                    $cliente->ciudad = $addr->city ?? '';
                    $cliente->provincia = $addr->state ?? '';
                    $cliente->codpostal = $addr->postal_code ?? '';
                    $cliente->codpais = strtoupper($addr->country ?? 'ES');
                }
            }

            // Obtener CIF de Stripe si está disponible
            if ($stripeCustomerId) {
                $taxId = StripeHelper::getCustomerTaxId($stripeCustomerId);
                if ($taxId) {
                    $cliente->cifnif = $taxId;
                }
            }

            $cliente->observaciones = \sprintf(
                'Cliente creado automáticamente desde Stripe | Customer ID: %s | Fecha: %s',
                $stripeCustomerId ?? 'N/A',
                date('Y-m-d H:i:s')
            );

            if (!$cliente->save()) {
                throw new Exception('Could not save new client');
            }

            // Crear contacto
            $contacto = new Contacto();
            $contacto->codcliente = $cliente->codcliente;
            $contacto->nombre = $customerName;
            $contacto->email = $email;
            $contacto->cifnif = $cliente->cifnif;
            $contacto->telefono1 = $cliente->telefono1;
            $contacto->direccion = $cliente->direccion;
            $contacto->ciudad = $cliente->ciudad;
            $contacto->provincia = $cliente->provincia;
            $contacto->codpostal = $cliente->codpostal;
            $contacto->codpais = $cliente->codpais;
            $contacto->descripcion = 'Contacto principal (Stripe)';

            if (!$contacto->save()) {
                throw new Exception('Could not save contact');
            }

            $this->db->commit();
            SolwedLogger::stripe('Created client and contact: ' . $email);
            return $contacto;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            SolwedLogger::error('Error creating client/contact: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene concepto del pago desde sesión de checkout
     */
    private function getConceptoFromSession(object $session): string
    {
        // Intentar obtener de line_items
        $lineItems = $this->getLineItemsFromSession($session);
        if (!empty($lineItems)) {
            $descriptions = array_map(function ($item) {
                return $item['description'] ?? $item['name'] ?? 'Producto';
            }, $lineItems);
            return implode(', ', $descriptions);
        }

        // Fallback
        if ($session->mode === 'subscription') {
            return 'Suscripción SOLWED';
        }

        return 'Pago SOLWED';
    }

    /**
     * Obtiene concepto del pago desde invoice
     */
    private function getConceptoFromInvoice(object $invoice): string
    {
        if (isset($invoice->lines) && isset($invoice->lines->data) && !empty($invoice->lines->data)) {
            $descriptions = [];
            foreach ($invoice->lines->data as $line) {
                if (!empty($line->description)) {
                    $descriptions[] = $line->description;
                }
            }
            if (!empty($descriptions)) {
                return implode(', ', $descriptions);
            }
        }

        if (!empty($invoice->subscription)) {
            return 'Renovación suscripción';
        }

        return 'Pago Stripe';
    }

    /**
     * Obtiene line items desde checkout session
     */
    private function getLineItemsFromSession(object $session): array
    {
        $items = [];

        // Los line_items no vienen en el webhook, necesitamos obtenerlos via bridge
        if (!empty($session->id)) {
            try {
                $result = \FacturaScripts\Plugins\SolwedES\Lib\BridgeClient::get('/stripe/checkout-sessions/' . $session->id);
                $sessionData = $result['data'] ?? [];
                foreach (($sessionData['line_items']['data'] ?? []) as $item) {
                    $items[] = [
                        'name' => $item['description'] ?? 'Producto',
                        'description' => $item['description'] ?? '',
                        'quantity' => $item['quantity'] ?? 1,
                        'amount' => ($item['amount_total'] ?? 0) / 100,
                    ];
                }
            } catch (Exception $e) {
                SolwedLogger::stripe('Could not fetch line items: ' . $e->getMessage());
            }
        }

        return $items;
    }

    /**
     * Crea acceso a servicio si no existe
     */
    private function createServiceAccessIfNeeded(int $idcontacto, int $idservicio): void
    {
        $acceso = AccesoServicio::getByClienteServicio($idcontacto, $idservicio);
        if ($acceso) {
            return;
        }

        $servicio = new Servicio();
        if (!$servicio->load($idservicio)) {
            return;
        }

        $acceso = new AccesoServicio();
        $acceso->idcontacto = $idcontacto;
        $acceso->idservicio = $idservicio;
        $acceso->activo = true;
        $acceso->tipo_acceso = 'otro';
        $acceso->url_acceso = 'https://portal.solwed.es';
        $acceso->save();

        SolwedLogger::stripe('Service access created for one-time payment');
    }

    /**
     * Actualiza acceso al servicio cuando cambia el plan
     */
    private function updateServiceAccess(int $idcontacto, int $oldServiceId, int $newServiceId): void
    {
        // Desactivar acceso al servicio anterior
        $oldAcceso = AccesoServicio::getByClienteServicio($idcontacto, $oldServiceId);
        if ($oldAcceso) {
            $oldAcceso->activo = false;
            $oldAcceso->save();
        }

        // Crear o activar acceso al nuevo servicio
        $newAcceso = AccesoServicio::getByClienteServicio($idcontacto, $newServiceId);
        if (!$newAcceso) {
            $servicio = new Servicio();
            if ($servicio->load($newServiceId)) {
                $newAcceso = new AccesoServicio();
                $newAcceso->idcontacto = $idcontacto;
                $newAcceso->idservicio = $newServiceId;
                $newAcceso->activo = true;
                $newAcceso->tipo_acceso = 'otro';
                $newAcceso->url_acceso = 'https://portal.solwed.es';
                $newAcceso->save();
            }
        } else {
            $newAcceso->activo = true;
            $newAcceso->save();
        }

        SolwedLogger::stripe('Service access updated for plan change');
    }

    /**
     * Handles domain registration from checkout session
     *
     * Expected metadata:
     * - type: 'domain_registration'
     * - domain: Full domain name (e.g., 'example.com')
     * - idcontacto: FacturaScripts contact ID
     * - years: Registration years (optional, default 1)
     */
    private function handleDomainRegistration(object $session): array
    {
        SolwedLogger::stripe('=== HANDLING DOMAIN REGISTRATION ===');

        $domain = $session->metadata->domain ?? null;
        $idcontacto = $session->metadata->idcontacto ?? null;
        $years = (int)($session->metadata->years ?? 1);

        if (!$domain) {
            SolwedLogger::error('Domain registration: missing domain in metadata');
            return ['success' => false, 'error' => 'Missing domain in metadata'];
        }

        SolwedLogger::stripe(sprintf('Registering domain: %s for %d years', $domain, $years));

        // Find or create contact
        $contacto = null;
        if ($idcontacto) {
            $contacto = new Contacto();
            if (!$contacto->load($idcontacto)) {
                $contacto = null;
            }
        }

        if (!$contacto) {
            $contacto = $this->findOrCreateContactoFromSession($session);
        }

        if (!$contacto) {
            SolwedLogger::error('Domain registration: could not find or create contact');
            return ['success' => false, 'error' => 'Could not find or create contact'];
        }

        $idcontacto = $contacto->idcontacto;

        // Check if DonDominio is configured
        if (!DonDominioHelper::isConfigured()) {
            SolwedLogger::error('Domain registration: DonDominio not configured');
            // Still record payment but don't register domain
            $result = $this->recordDomainPayment($session, $contacto, $domain, 'domain_registration');
            $result['warning'] = 'DonDominio not configured - payment recorded but domain not registered';
            return $result;
        }

        // Convert FS contact to DonDominio format
        $ddContactData = DonDominioHelper::contactoToDD($contacto);

        // Get default nameservers from settings
        $defaultNs = DonDominioHelper::getSetting('dondominio_default_nameservers', 'ns1.solwed.es,ns2.solwed.es');

        // Register domain with DonDominio
        $registerResult = DonDominioHelper::registerDomain($domain, $ddContactData, $years, [
            'nameservers' => $defaultNs
        ]);

        if (!$registerResult['success']) {
            SolwedLogger::error('Domain registration failed: ' . $registerResult['error']);
            // Record payment anyway since it was already charged
            $result = $this->recordDomainPayment($session, $contacto, $domain, 'domain_registration');
            $result['domain_registration_error'] = $registerResult['error'];
            return $result;
        }

        SolwedLogger::stripe('Domain registered in DonDominio: ' . $registerResult['domain_id']);

        // Create Dominio record in FacturaScripts
        $dominio = new Dominio();

        // Parse domain name and TLD
        $lastDot = strrpos($domain, '.');
        $nombre = $lastDot !== false ? substr($domain, 0, $lastDot) : $domain;
        $tld = $lastDot !== false ? substr($domain, $lastDot) : '.com';

        $dominio->idcontacto = $idcontacto;
        $dominio->nombre = strtolower($nombre);
        $dominio->tld = strtolower($tld);
        $dominio->dondominio_id = $registerResult['domain_id'];
        $dominio->fecha_expiracion = $registerResult['expiration'];
        $dominio->fecha_registro = date('Y-m-d');
        $dominio->estado = 'active';
        $dominio->gestionado_solwed = true;
        $dominio->observaciones = 'Registered via portal checkout - Session: ' . $session->id;

        if (!$dominio->save()) {
            SolwedLogger::error('Could not save Dominio record');
        } else {
            SolwedLogger::stripe('Dominio record created: ' . $dominio->id);
        }

        // Record payment
        $result = $this->recordDomainPayment($session, $contacto, $domain, 'domain_registration', $dominio);
        $result['domain_id'] = $dominio->id ?? null;
        $result['dondominio_id'] = $registerResult['domain_id'];
        $result['expiration'] = $registerResult['expiration'];

        // Send notification if enabled
        if (DonDominioHelper::getSetting('dondominio_send_notifications', true)) {
            // TODO: Send domain registration confirmation email
            SolwedLogger::stripe('Domain registration notification should be sent');
        }

        return $result;
    }

    /**
     * Handles domain renewal from checkout session
     *
     * Expected metadata:
     * - type: 'domain_renewal'
     * - domain: Full domain name (e.g., 'example.com')
     * - idcontacto: FacturaScripts contact ID
     * - iddominio: FacturaScripts domain ID (optional)
     * - years: Renewal years (optional, default 1)
     */
    private function handleDomainRenewal(object $session): array
    {
        SolwedLogger::stripe('=== HANDLING DOMAIN RENEWAL ===');

        $domain = $session->metadata->domain ?? null;
        $idcontacto = $session->metadata->idcontacto ?? null;
        $iddominio = $session->metadata->iddominio ?? null;
        $years = (int)($session->metadata->years ?? 1);

        if (!$domain) {
            SolwedLogger::error('Domain renewal: missing domain in metadata');
            return ['success' => false, 'error' => 'Missing domain in metadata'];
        }

        SolwedLogger::stripe(sprintf('Renewing domain: %s for %d years', $domain, $years));

        // Find contact
        $contacto = null;
        if ($idcontacto) {
            $contacto = new Contacto();
            if (!$contacto->load($idcontacto)) {
                $contacto = null;
            }
        }

        if (!$contacto) {
            $contacto = $this->findOrCreateContactoFromSession($session);
        }

        if (!$contacto) {
            SolwedLogger::error('Domain renewal: could not find contact');
            return ['success' => false, 'error' => 'Could not find contact'];
        }

        $idcontacto = $contacto->idcontacto;

        // Find existing domain record
        $dominio = null;
        if ($iddominio) {
            $dominio = new Dominio();
            if (!$dominio->load($iddominio)) {
                $dominio = null;
            }
        }

        // If not found by ID, try to find by domain name
        if (!$dominio) {
            $dominio = Dominio::getByNombreCompleto($domain);
        }

        // Check if DonDominio is configured
        if (!DonDominioHelper::isConfigured()) {
            SolwedLogger::error('Domain renewal: DonDominio not configured');
            $result = $this->recordDomainPayment($session, $contacto, $domain, 'domain_renewal', $dominio);
            $result['warning'] = 'DonDominio not configured - payment recorded but domain not renewed';
            return $result;
        }

        // Renew domain with DonDominio
        $renewResult = DonDominioHelper::renewDomain($domain, $years);

        if (!$renewResult['success']) {
            SolwedLogger::error('Domain renewal failed: ' . $renewResult['error']);
            $result = $this->recordDomainPayment($session, $contacto, $domain, 'domain_renewal', $dominio);
            $result['domain_renewal_error'] = $renewResult['error'];
            return $result;
        }

        SolwedLogger::stripe('Domain renewed in DonDominio - New expiration: ' . $renewResult['new_expiration']);

        // Update Dominio record if we have it
        if ($dominio) {
            $dominio->fecha_expiracion = $renewResult['new_expiration'];
            $dominio->estado = 'active';
            $dominio->observaciones = ($dominio->observaciones ?? '') . "\nRenewed via portal checkout on " . date('Y-m-d');

            if (!$dominio->save()) {
                SolwedLogger::error('Could not update Dominio record');
            } else {
                SolwedLogger::stripe('Dominio record updated: ' . $dominio->id);
            }
        } else {
            // Create domain record if it doesn't exist
            $dominio = new Dominio();

            $lastDot = strrpos($domain, '.');
            $nombre = $lastDot !== false ? substr($domain, 0, $lastDot) : $domain;
            $tld = $lastDot !== false ? substr($domain, $lastDot) : '.com';

            $dominio->idcontacto = $idcontacto;
            $dominio->nombre = strtolower($nombre);
            $dominio->tld = strtolower($tld);
            $dominio->fecha_expiracion = $renewResult['new_expiration'];
            $dominio->estado = 'active';
            $dominio->gestionado_solwed = true;
            $dominio->observaciones = 'Created from renewal checkout - Session: ' . $session->id;

            if ($dominio->save()) {
                SolwedLogger::stripe('Dominio record created from renewal: ' . $dominio->id);
            }
        }

        // Record payment
        $result = $this->recordDomainPayment($session, $contacto, $domain, 'domain_renewal', $dominio);
        $result['new_expiration'] = $renewResult['new_expiration'];
        $result['domain_id'] = $dominio->id ?? null;

        // Send notification if enabled
        if (DonDominioHelper::getSetting('dondominio_send_notifications', true)) {
            // TODO: Send domain renewal confirmation email
            SolwedLogger::stripe('Domain renewal notification should be sent');
        }

        return $result;
    }

    /**
     * Records a domain-related payment
     */
    private function recordDomainPayment(
        object $session,
        Contacto $contacto,
        string $domain,
        string $type,
        ?Dominio $dominio = null
    ): array {
        $pago = new PagoStripe();
        $pago->idcontacto = $contacto->idcontacto;
        $pago->stripe_payment_intent = $session->payment_intent ?? 'checkout_' . $session->id;
        $pago->stripe_checkout_session = $session->id;
        $pago->stripe_customer_id = $session->customer ?? 'checkout_customer';
        $pago->tipo = PagoStripe::TIPO_ONE_TIME;
        $pago->concepto = $type === 'domain_registration'
            ? 'Registro de dominio: ' . $domain
            : 'Renovacion de dominio: ' . $domain;
        $pago->importe = ($session->amount_total ?? 0) / 100;
        $pago->moneda = strtoupper($session->currency ?? 'EUR');
        $pago->estado = PagoStripe::ESTADO_SUCCEEDED;
        $pago->fecha_pago = date('Y-m-d H:i:s');

        // Get payment method details
        if (!empty($session->payment_intent)) {
            $paymentDetails = StripeHelper::getPaymentMethodDetails($session->payment_intent);
            if ($paymentDetails['success']) {
                $pago->setMetodoPago($paymentDetails);
            }
        }

        // Save metadata
        $pago->setMetadata([
            'type' => $type,
            'domain' => $domain,
            'iddominio' => $dominio ? $dominio->id : null,
            'customer_email' => $session->customer_details->email ?? $session->customer_email ?? null
        ]);

        if (!$pago->save()) {
            SolwedLogger::error('Could not save PagoStripe for domain: ' . $domain);
            return ['success' => false, 'error' => 'Could not save payment'];
        }

        SolwedLogger::stripe('PagoStripe created for domain ' . $type . ': ' . $pago->id);

        $result = ['success' => true, 'pago_id' => $pago->id];

        // Create invoice if enabled
        if (StripeHelper::getSetting('crear_factura', true)) {
            $factura = $this->createFacturaFromPago($pago, $session);
            if ($factura) {
                $pago->linkToFactura($factura->idfactura);
                $result['factura'] = $factura->codigo;
                SolwedLogger::stripe('Factura created for domain: ' . $factura->codigo);

                // Send email
                if (StripeHelper::getSetting('enviar_email', true)) {
                    $paymentDetails = $this->getPaymentDetailsWithFallback($pago);
                    $emailResult = EmailManager::sendFacturaEmail($factura, $paymentDetails, $contacto);
                    $result['email_sent'] = $emailResult['success'] ?? false;
                }
            }
        }

        return $result;
    }

    /**
     * Handles WordPress hosting subscription checkout completion
     *
     * This is called when a customer purchases a WordPress hosting plan.
     * Creates Suscripcion, provisions hosting via Plesk, and sends credentials.
     */
    private function handleWordPressHostingCheckout(object $session): array
    {
        SolwedLogger::stripe('=== HANDLING WORDPRESS HOSTING CHECKOUT ===');
        SolwedLogger::stripe('DEBUG [WP-1]: Entered handleWordPressHostingCheckout');

        $idcontacto = $session->metadata->idcontacto ?? null;
        $idservicio = $session->metadata->idservicio ?? null;
        $domain = $session->metadata->domain ?? null;
        $plan = $session->metadata->plan ?? 'starter';
        $subscriptionId = $session->subscription ?? null;

        SolwedLogger::stripe('DEBUG [WP-2]: Extracted metadata:');
        SolwedLogger::stripe(sprintf(
            '  - Domain: %s, Plan: %s, Contact: %s, Service: %s, Subscription: %s',
            $domain ?? 'NULL',
            $plan,
            $idcontacto ?? 'NULL',
            $idservicio ?? 'NULL',
            $subscriptionId ?? 'NULL'
        ));

        // Validate required data
        if (!$domain) {
            SolwedLogger::error('DEBUG [WP-ERROR]: Missing domain in metadata');
            return ['success' => false, 'error' => 'Missing domain in metadata'];
        }

        SolwedLogger::stripe('DEBUG [WP-3]: Domain validated OK');

        if (!$idcontacto) {
            SolwedLogger::stripe('DEBUG [WP-4]: No idcontacto in metadata, trying to find/create from session');
            $contacto = $this->findOrCreateContactoFromSession($session);
            if ($contacto) {
                $idcontacto = $contacto->idcontacto;
                SolwedLogger::stripe('DEBUG [WP-4a]: Found/created contact: ' . $idcontacto);
            } else {
                SolwedLogger::stripe('DEBUG [WP-4b]: Could not find/create contact');
            }
        }

        if (!$idcontacto) {
            SolwedLogger::error('DEBUG [WP-ERROR]: No contact available');
            return ['success' => false, 'error' => 'Missing contact'];
        }

        SolwedLogger::stripe('DEBUG [WP-5]: Contact ID validated: ' . $idcontacto);

        // Check if contract already exists for this subscription
        if ($subscriptionId) {
            SolwedLogger::stripe('DEBUG [WP-6]: Checking for existing contract with subscription: ' . $subscriptionId);
            $existingContrato = Suscripcion::getByStripeSubscriptionId($subscriptionId);
            if ($existingContrato) {
                SolwedLogger::stripe('DEBUG [WP-6a]: Contract already exists: ' . $existingContrato->id);
                return ['success' => true, 'message' => 'Already configured'];
            }
            SolwedLogger::stripe('DEBUG [WP-6b]: No existing contract found');
        }

        // Load contact
        SolwedLogger::stripe('DEBUG [WP-7]: Loading contact from database');
        $contacto = new Contacto();
        if (!$contacto->load($idcontacto)) {
            SolwedLogger::error('DEBUG [WP-ERROR]: Contact not found in database: ' . $idcontacto);
            return ['success' => false, 'error' => 'Contact not found'];
        }
        SolwedLogger::stripe('DEBUG [WP-7a]: Contact loaded: ' . $contacto->fullName() . ' (' . $contacto->email . ')');

        // Get subscription details from Stripe
        $stripeSubscription = null;
        if ($subscriptionId) {
            SolwedLogger::stripe('DEBUG [WP-8]: Fetching subscription details from Stripe');
            $stripeSubscription = StripeSubscriptionManager::getSubscription($subscriptionId);
            SolwedLogger::stripe('DEBUG [WP-8a]: Subscription fetched: ' . ($stripeSubscription ? 'OK' : 'FAILED'));
        }

        // Calculate dates
        $fechaInicio = date('Y-m-d');
        $fechaVencimiento = StripeUtils::getSubscriptionEndDate($stripeSubscription);

        if ($stripeSubscription && isset($stripeSubscription->current_period_start) && $stripeSubscription->current_period_start > 0) {
            $fechaInicio = date('Y-m-d', $stripeSubscription->current_period_start);
        }

        SolwedLogger::stripe("DEBUG [WP-9]: Calculated dates - Start: {$fechaInicio}, End: {$fechaVencimiento}");

        // Start transaction
        SolwedLogger::stripe('DEBUG [WP-10]: Starting database transaction');
        $this->db->beginTransaction();

        try {
            // 1. Create Suscripcion with pending provisioning status
            SolwedLogger::stripe('DEBUG [WP-11]: Creating Suscripcion');
            $suscripcion = new Suscripcion();
            $suscripcion->idcontacto = (int)$idcontacto;
            $suscripcion->idservicio = $idservicio ? (int)$idservicio : null;
            $suscripcion->estado = Suscripcion::ESTADO_PENDIENTE;
            $suscripcion->provisioning_status = Suscripcion::PROV_PENDING;
            $suscripcion->fecha_inicio = $fechaInicio;
            $suscripcion->fecha_vencimiento = $fechaVencimiento;
            $suscripcion->fecha_ultimo_pago = $session->payment_status === 'paid' ? date('Y-m-d') : null;
            $suscripcion->fecha_proximo_pago = $fechaVencimiento;
            $suscripcion->metodo_pago = Suscripcion::METODO_STRIPE;
            $suscripcion->referencia_externa = $subscriptionId;
            $suscripcion->stripe_customer_id = $session->customer;
            $suscripcion->auto_renovar = !($stripeSubscription->cancel_at_period_end ?? false);
            $suscripcion->importe = $stripeSubscription
                ? (($stripeSubscription->items->data[0]->price->unit_amount ?? 0) / 100)
                : (($session->amount_total ?? 0) / 100);
            $suscripcion->notas = "WordPress Hosting: {$domain} (Plan: {$plan})";

            if (!$suscripcion->save()) {
                throw new Exception('Failed to create Suscripcion');
            }

            SolwedLogger::stripe('DEBUG [WP-11a]: Suscripcion created with ID: ' . $suscripcion->id);

            // 2. Create PagoStripe record
            SolwedLogger::stripe('DEBUG [WP-12]: Creating PagoStripe');
            $pago = new PagoStripe();
            $pago->idcontacto = (int)$idcontacto;
            $pago->idservicio = $idservicio ? (int)$idservicio : null;
            $pago->stripe_payment_intent = $session->payment_intent ?? 'pending_' . $session->id;
            $pago->stripe_checkout_session = $session->id;
            $pago->stripe_customer_id = $session->customer;
            $pago->tipo = PagoStripe::TIPO_SUBSCRIPTION;
            $pago->concepto = "WordPress Hosting {$plan}: {$domain}";
            $pago->importe = ($session->amount_total ?? 0) / 100;
            $pago->moneda = strtoupper($session->currency ?? 'EUR');
            $pago->estado = $session->payment_status === 'paid' ? PagoStripe::ESTADO_SUCCEEDED : PagoStripe::ESTADO_PENDING;

            if ($session->payment_status === 'paid') {
                $pago->fecha_pago = date('Y-m-d H:i:s');
            }

            if (!$pago->save()) {
                throw new Exception('Failed to create PagoStripe');
            }

            SolwedLogger::stripe('DEBUG [WP-12a]: PagoStripe created with ID: ' . $pago->id);

            // 3. Attempt provisioning (synchronous)
            SolwedLogger::stripe('DEBUG [WP-13]: Starting WordPress provisioning');
            SolwedLogger::stripe("DEBUG [WP-13a]: Calling WordPressProvisioner::provision({$domain}, {$plan})");
            $provisionResult = WordPressProvisioner::provision($suscripcion, $contacto, $domain, $plan);
            SolwedLogger::stripe('DEBUG [WP-13b]: Provisioning result: ' . ($provisionResult->success ? 'SUCCESS' : 'FAILED'));
            if (!$provisionResult->success) {
                SolwedLogger::stripe('DEBUG [WP-13c]: Provisioning error: ' . ($provisionResult->error ?? 'Unknown'));
            }

            if ($provisionResult->success) {
                // 4a. Provisioning successful - create AccesoServicio with real credentials
                SolwedLogger::stripe('DEBUG [WP-14]: Creating AccesoServicio with credentials');
                $acceso = new AccesoServicio();
                $acceso->idcontacto = (int)$idcontacto;
                $acceso->idservicio = $idservicio ? (int)$idservicio : null;
                $acceso->tipo_acceso = 'wordpress';
                $acceso->url_acceso = $provisionResult->adminUrl;
                $acceso->usuario = $provisionResult->username;
                $acceso->activo = true;
                $acceso->notas = "Domain: {$domain}, Plan: {$plan}";

                if (!$acceso->save()) {
                    SolwedLogger::error('DEBUG [WP-14a]: Failed to save AccesoServicio');
                } else {
                    SolwedLogger::stripe('DEBUG [WP-14b]: AccesoServicio created with ID: ' . $acceso->id);
                }

                // 5a. Update contract to active
                SolwedLogger::stripe('DEBUG [WP-15]: Updating contract status to ACTIVO');
                $suscripcion->provisioning_status = Suscripcion::PROV_COMPLETED;
                $suscripcion->estado = Suscripcion::ESTADO_ACTIVO;
                $suscripcion->save();

                SolwedLogger::stripe('DEBUG [WP-15a]: Contract updated successfully');

            } else {
                // 4b. Provisioning failed - keep contract as pending
                SolwedLogger::stripe('DEBUG [WP-16]: Provisioning FAILED - updating contract status');
                $suscripcion->provisioning_status = Suscripcion::PROV_FAILED;
                $suscripcion->estado = Suscripcion::ESTADO_PENDIENTE;
                $suscripcion->notas .= "\n\nProvisioning failed: " . $provisionResult->error;
                $suscripcion->save();

                // 5b. Alert admin
                SolwedLogger::stripe('DEBUG [WP-17]: Sending failure alert to admin');
                $alertSent = EmailManager::sendProvisioningFailureAlert(
                    $suscripcion,
                    $contacto,
                    $domain,
                    $provisionResult->error ?? 'Unknown error'
                );
                SolwedLogger::stripe('DEBUG [WP-17a]: Alert email sent: ' . ($alertSent ? 'YES' : 'NO'));

                SolwedLogger::error('DEBUG [WP-ERROR]: WordPress provisioning failed: ' . $provisionResult->error);
            }

            // Commit transaction
            SolwedLogger::stripe('DEBUG [WP-18]: Committing transaction');
            $this->db->commit();
            SolwedLogger::stripe('DEBUG [WP-18a]: Transaction committed successfully');

            $result = [
                'success' => true,
                'provisioned' => $provisionResult->success,
                'contrato_id' => $suscripcion->id,
                'pago_id' => $pago->id,
                'provisioning_error' => $provisionResult->success ? null : $provisionResult->error
            ];
            SolwedLogger::stripe('DEBUG [WP-19]: Returning result: ' . json_encode($result));
            return $result;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            SolwedLogger::error('WordPress hosting checkout failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handles domain auto-renewal subscription checkout completion
     *
     * This is called when a user sets up auto-renewal for a domain.
     * Creates a Suscripcion and links it to the domain.
     */
    private function handleDomainAutoRenewalCheckout(object $session): array
    {
        SolwedLogger::stripe('=== HANDLING DOMAIN AUTO-RENEWAL CHECKOUT ===');

        $iddominio = $session->metadata->iddominio ?? null;
        $idcontacto = $session->metadata->idcontacto ?? null;
        $domain = $session->metadata->domain ?? null;
        $subscriptionId = $session->subscription ?? null;

        SolwedLogger::stripe(sprintf(
            'Domain: %s, Domain ID: %s, Subscription: %s',
            $domain,
            $iddominio,
            $subscriptionId
        ));

        if (!$iddominio) {
            SolwedLogger::error('Domain auto-renewal checkout: missing domain ID');
            return ['success' => false, 'error' => 'Missing domain ID in metadata'];
        }

        if (!$subscriptionId) {
            SolwedLogger::error('Domain auto-renewal checkout: missing subscription ID');
            return ['success' => false, 'error' => 'Missing subscription ID'];
        }

        // Load domain
        $dominio = new Dominio();
        if (!$dominio->load($iddominio)) {
            SolwedLogger::error('Domain auto-renewal checkout: domain not found: ' . $iddominio);
            return ['success' => false, 'error' => 'Domain not found'];
        }

        // Check if contract already exists for this subscription
        $existingContrato = Suscripcion::getByStripeSubscriptionId($subscriptionId);
        if ($existingContrato) {
            SolwedLogger::stripe('Contract already exists for this subscription');
            return ['success' => true, 'message' => 'Already configured'];
        }

        // Find or create contact for payment record
        $contacto = null;
        if ($idcontacto) {
            $contacto = new Contacto();
            if (!$contacto->load($idcontacto)) {
                $contacto = null;
            }
        }

        if (!$contacto) {
            $contacto = $this->findOrCreateContactoFromSession($session);
        }

        $idcontacto = $contacto ? $contacto->idcontacto : ($dominio->idcontacto ?? 0);

        // Obtener detalles de la suscripción desde Stripe
        $stripeSubscription = StripeSubscriptionManager::getSubscription($subscriptionId);
        if (!$stripeSubscription) {
            SolwedLogger::error('Could not get subscription details from Stripe');
            return ['success' => false, 'error' => 'Could not get subscription details'];
        }

        // Calculate dates - handle newer Stripe API versions
        $fechaInicio = date('Y-m-d');
        $fechaVencimiento = date('Y-m-d', strtotime('+1 year')); // Default for domain renewals

        if (isset($stripeSubscription->current_period_start) && $stripeSubscription->current_period_start > 0) {
            $fechaInicio = date('Y-m-d', $stripeSubscription->current_period_start);
        }

        if (isset($stripeSubscription->current_period_end) && $stripeSubscription->current_period_end > 0) {
            $fechaVencimiento = date('Y-m-d', $stripeSubscription->current_period_end);
        } elseif (isset($stripeSubscription->billing_cycle_anchor)) {
            // Calculate from billing_cycle_anchor + 1 year (domain renewals are annual)
            $fechaVencimiento = date('Y-m-d', strtotime('+1 year', $stripeSubscription->billing_cycle_anchor));
        }

        // Create Suscripcion for domain auto-renewal
        $suscripcion = new Suscripcion();
        $suscripcion->idcontacto = $idcontacto;
        $suscripcion->estado = Suscripcion::ESTADO_ACTIVO;
        $suscripcion->fecha_inicio = $fechaInicio;
        $suscripcion->fecha_vencimiento = $fechaVencimiento;
        $suscripcion->fecha_ultimo_pago = $session->payment_status === 'paid' ? date('Y-m-d') : null;
        $suscripcion->fecha_proximo_pago = $fechaVencimiento;
        $suscripcion->metodo_pago = Suscripcion::METODO_STRIPE;
        $suscripcion->referencia_externa = $subscriptionId;
        $suscripcion->stripe_customer_id = $session->customer;
        $suscripcion->auto_renovar = true;
        $suscripcion->importe = ($stripeSubscription->items->data[0]->price->unit_amount ?? 0) / 100;
        $suscripcion->notas = 'Auto-renovación dominio: ' . $dominio->getNombreCompleto();

        if (!$suscripcion->save()) {
            SolwedLogger::error('Failed to create Suscripcion for domain auto-renewal');
            return ['success' => false, 'error' => 'Could not save contract'];
        }

        SolwedLogger::stripe('Suscripcion created for domain auto-renewal: ' . $suscripcion->id);

        // Link domain to contract
        $dominio->idcontrato = $suscripcion->id;
        $dominio->observaciones = ($dominio->observaciones ?? '') .
            "\n[" . date('Y-m-d') . "] Auto-renovación activada (Contrato: " . $suscripcion->id . ")";
        $dominio->save();

        SolwedLogger::stripe(sprintf(
            'Domain %s linked to contract %d',
            $dominio->getNombreCompleto(),
            $suscripcion->id
        ));

        // Create PagoStripe record for the initial payment
        $pago = new PagoStripe();
        $pago->idcontacto = $idcontacto;
        $pago->stripe_payment_intent = $session->payment_intent ?? 'sub_' . $session->id;
        $pago->stripe_checkout_session = $session->id;
        $pago->stripe_customer_id = $session->customer ?? '';
        $pago->tipo = PagoStripe::TIPO_SUBSCRIPTION;
        $pago->concepto = 'Auto-renovación dominio: ' . $dominio->getNombreCompleto();
        $pago->importe = ($session->amount_total ?? 0) / 100;
        $pago->moneda = strtoupper($session->currency ?? 'EUR');
        $pago->estado = $session->payment_status === 'paid' ? PagoStripe::ESTADO_SUCCEEDED : PagoStripe::ESTADO_PENDING;

        if ($session->payment_status === 'paid') {
            $pago->fecha_pago = date('Y-m-d H:i:s');
        }

        $pago->setMetadata([
            'type' => 'domain_auto_renewal',
            'domain' => $dominio->getNombreCompleto(),
            'iddominio' => $dominio->id,
            'idcontrato' => $suscripcion->id,
            'subscription_id' => $subscriptionId
        ]);

        $pago->save();
        SolwedLogger::stripe('PagoStripe created for domain auto-renewal: ' . $pago->id);

        $result = [
            'success' => true,
            'domain_id' => $dominio->id,
            'contrato_id' => $suscripcion->id,
            'subscription_id' => $subscriptionId,
            'pago_id' => $pago->id
        ];

        // Create factura if enabled and payment was completed
        if ($session->payment_status === 'paid' && StripeHelper::getSetting('crear_factura', true) && $contacto) {
            $factura = $this->createFacturaFromPago($pago, $session);
            if ($factura) {
                $pago->linkToFactura($factura->idfactura);
                $result['factura'] = $factura->codigo;
                SolwedLogger::stripe('Factura created for domain auto-renewal: ' . $factura->codigo);
            }
        }

        return $result;
    }

    /**
     * Handles domain subscription renewal (when invoice.paid fires for a domain subscription)
     *
     * This is called when Stripe charges the yearly subscription for domain renewal.
     * It actually renews the domain with DonDominio.
     */
    private function handleDomainSubscriptionRenewal(object $invoice, string $subscriptionId): array
    {
        SolwedLogger::stripe('=== HANDLING DOMAIN SUBSCRIPTION RENEWAL ===');
        SolwedLogger::stripe('Subscription ID: ' . $subscriptionId);

        // Find contract by subscription ID
        $suscripcion = Suscripcion::getByStripeSubscriptionId($subscriptionId);
        if (!$suscripcion) {
            SolwedLogger::stripe('No contract found for subscription: ' . $subscriptionId);
            return ['success' => true, 'message' => 'Not a domain subscription'];
        }

        // Get linked domain(s)
        $dominios = $suscripcion->getDominios();
        if (empty($dominios)) {
            SolwedLogger::stripe('No domains linked to contract: ' . $suscripcion->id);
            return ['success' => true, 'message' => 'No domains linked'];
        }

        // Use first domain (typically one contract = one domain for auto-renewal)
        $dominio = $dominios[0];
        SolwedLogger::stripe('Found domain: ' . $dominio->getNombreCompleto());

        // Check if DonDominio is configured
        if (!DonDominioHelper::isConfigured()) {
            SolwedLogger::error('DonDominio not configured - cannot renew domain automatically');
            // Still record payment but don't renew
            return $this->recordDomainSubscriptionPayment($invoice, $dominio, false, 'DonDominio not configured');
        }

        // Renew domain with DonDominio
        $renewResult = DonDominioHelper::renewDomain($dominio->getNombreCompleto(), 1);

        if (!$renewResult['success']) {
            $errorMsg = $renewResult['error'] ?? 'Unknown error';
            SolwedLogger::error("CRITICAL: Domain renewal failed for {$dominio->getNombreCompleto()}: $errorMsg");

            // Mark domain as needing attention
            $dominio->observaciones = ($dominio->observaciones ?? '') .
                "\n[" . date('Y-m-d') . "] ⚠️ RENOVACIÓN FALLIDA - Pago cobrado pero dominio NO renovado. Error: $errorMsg";
            $dominio->save();

            // Alert admin via email
            $this->sendDomainRenewalFailureAlert($dominio, $errorMsg, $invoice);

            return $this->recordDomainSubscriptionPayment($invoice, $dominio, false, $errorMsg);
        }

        SolwedLogger::stripe('Domain renewed in DonDominio - New expiration: ' . $renewResult['new_expiration']);

        // Update domain expiration date
        $dominio->fecha_expiracion = $renewResult['new_expiration'];
        $dominio->estado = Dominio::ESTADO_ACTIVE;
        $dominio->observaciones = ($dominio->observaciones ?? '') .
            "\n[" . date('Y-m-d') . "] Auto-renovación completada - Nueva expiración: " . $renewResult['new_expiration'];
        $dominio->save();

        // Record payment
        $result = $this->recordDomainSubscriptionPayment($invoice, $dominio, true);
        $result['new_expiration'] = $renewResult['new_expiration'];

        // Send notification if enabled
        if (DonDominioHelper::getSetting('dondominio_send_notifications', true)) {
            $contacto = $dominio->getContacto();
            if ($contacto) {
                // TODO: Send domain renewal confirmation email
                SolwedLogger::stripe('Domain renewal notification should be sent to: ' . $contacto->email);
            }
        }

        return $result;
    }

    /**
     * Records a payment for domain subscription renewal
     */
    private function recordDomainSubscriptionPayment(
        object $invoice,
        Dominio $dominio,
        bool $renewalSuccess,
        ?string $error = null
    ): array {
        $paymentIntentId = $invoice->payment_intent ?? '';

        $pago = new PagoStripe();
        $pago->idcontacto = $dominio->idcontacto ?? 0;
        $pago->stripe_payment_intent = $paymentIntentId ?: 'invoice_' . $invoice->id;
        $pago->stripe_invoice_id = $invoice->id;
        $pago->stripe_customer_id = $invoice->customer ?? '';
        $pago->tipo = PagoStripe::TIPO_SUBSCRIPTION;
        $pago->concepto = 'Renovación anual dominio: ' . $dominio->getNombreCompleto();
        $pago->importe = ($invoice->amount_paid ?? 0) / 100;
        $pago->moneda = strtoupper($invoice->currency ?? 'EUR');
        $pago->estado = PagoStripe::ESTADO_SUCCEEDED;
        $pago->fecha_pago = date('Y-m-d H:i:s');

        // Get payment method details
        if (!empty($paymentIntentId)) {
            $paymentDetails = StripeHelper::getPaymentMethodDetails($paymentIntentId);
            if ($paymentDetails['success']) {
                $pago->setMetodoPago($paymentDetails);
            }
        }

        $pago->setMetadata([
            'type' => 'domain_subscription_renewal',
            'domain' => $dominio->getNombreCompleto(),
            'iddominio' => $dominio->id,
            'renewal_success' => $renewalSuccess,
            'renewal_error' => $error
        ]);

        $pago->save();
        SolwedLogger::stripe('PagoStripe created for domain subscription renewal: ' . $pago->id);

        $result = [
            'success' => true,
            'pago_id' => $pago->id,
            'domain_id' => $dominio->id,
            'renewal_success' => $renewalSuccess
        ];

        if ($error) {
            $result['renewal_error'] = $error;
        }

        // Create factura if enabled
        if (StripeHelper::getSetting('crear_factura', true)) {
            $contacto = $dominio->getContacto();
            if ($contacto) {
                $factura = $this->createFacturaFromInvoice($invoice, $contacto, null);
                if ($factura) {
                    $pago->linkToFactura($factura->idfactura);
                    $result['factura'] = $factura->codigo;
                    SolwedLogger::stripe('Factura created for domain subscription renewal: ' . $factura->codigo);

                    // Send email
                    if (StripeHelper::getSetting('enviar_email', true)) {
                        $paymentDetails = $this->getPaymentDetailsWithFallback($pago);
                        $emailResult = EmailManager::sendFacturaEmail($factura, $paymentDetails, $contacto);
                        $result['email_sent'] = $emailResult['success'] ?? false;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Gets payment details from PagoStripe with fallback defaults
     */
    private function getPaymentDetailsWithFallback(PagoStripe $pago): array
    {
        $details = $pago->getMetodoPago();
        if (!empty($details)) {
            return $details;
        }

        return [
            'success' => true,
            'brand' => 'Tarjeta',
            'last4' => 'N/A',
            'fecha' => $pago->fecha_pago,
            'amount' => $pago->importe,
            'currency' => $pago->moneda
        ];
    }

    /**
     * Envía respuesta JSON
     */
    private function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
