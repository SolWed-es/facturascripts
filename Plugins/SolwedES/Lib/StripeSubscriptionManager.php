<?php
/**
 * Plugin SolwedES - Gestión de Suscripciones Stripe
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use Exception;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Model\ContratServicio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\Price;
use Stripe\Product;
use Stripe\Subscription;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;

/**
 * Gestiona suscripciones de servicios con Stripe
 * Usa StripeHelper como fuente única de credenciales
 */
class StripeSubscriptionManager
{
    /**
     * Inicializa Stripe con las credenciales de Settings
     * Delega a StripeHelper para usar configuración unificada
     */
    private static function init(): bool
    {
        return StripeHelper::initStripe();
    }

    /**
     * Sincroniza un servicio con Stripe (crea Product y Price)
     *
     * - Si el producto no existe, lo crea
     * - Si el precio cambió, archiva el anterior y crea uno nuevo
     * - Los precios en Stripe son inmutables, por eso se archivan
     */
    public static function syncProductToStripe(Servicio $servicio): bool
    {
        if (!self::init()) {
            return false;
        }

        // Validar que tenga recurrencia válida
        if ($servicio->meses_recurrencia <= 0) {
            Tools::log('solwed')->warning('Servicio sin recurrencia válida, no se sincroniza con Stripe');
            return false;
        }

        try {
            // Determinar intervalo según meses_recurrencia
            $interval = 'month';
            $intervalCount = 1;
            if ($servicio->meses_recurrencia >= 12) {
                $interval = 'year';
                $intervalCount = 1;
            } elseif ($servicio->meses_recurrencia > 1) {
                $intervalCount = $servicio->meses_recurrencia;
            }

            // Crear o actualizar producto en Stripe
            if (empty($servicio->stripe_product_id)) {
                $product = Product::create([
                    'name' => $servicio->nombre,
                    'description' => $servicio->descripcion ?? '',
                    'metadata' => [
                        'servicio_id' => $servicio->id,
                        'categoria' => $servicio->categoria
                    ]
                ]);
                $servicio->stripe_product_id = $product->id;
                Tools::log('solwed')->info(sprintf('Producto Stripe creado: %s', $product->id));
            } else {
                Product::update($servicio->stripe_product_id, [
                    'name' => $servicio->nombre,
                    'description' => $servicio->descripcion ?? ''
                ]);
            }

            // Calcular nuevo monto en céntimos
            $newAmount = (int)($servicio->precio * 100);
            $needNewPrice = true;

            // Si ya existe un precio, verificar si cambió
            if (!empty($servicio->stripe_price_id)) {
                try {
                    $oldPrice = Price::retrieve($servicio->stripe_price_id);

                    // Comparar monto e intervalo
                    $sameAmount = $oldPrice->unit_amount === $newAmount;
                    $sameInterval = $oldPrice->recurring->interval === $interval;
                    $sameIntervalCount = $oldPrice->recurring->interval_count === $intervalCount;

                    if ($sameAmount && $sameInterval && $sameIntervalCount) {
                        // No hay cambios, no crear nuevo precio
                        $needNewPrice = false;
                        Tools::log('solwed')->info('Precio sin cambios, no se crea nuevo');
                    } else {
                        // Archivar precio anterior
                        Price::update($servicio->stripe_price_id, ['active' => false]);
                        Tools::log('solwed')->info(sprintf(
                            'Precio anterior archivado: %s (cambio de %d a %d céntimos)',
                            $servicio->stripe_price_id,
                            $oldPrice->unit_amount,
                            $newAmount
                        ));
                    }
                } catch (Exception $e) {
                    // Precio no existe o error, crear nuevo
                    Tools::log('solwed')->warning('Precio anterior no encontrado: ' . $e->getMessage());
                }
            }

            // Crear nuevo precio si es necesario
            // IMPORTANTE: tax_behavior=exclusive para que el IVA se añada al precio base
            if ($needNewPrice) {
                $price = Price::create([
                    'product' => $servicio->stripe_product_id,
                    'unit_amount' => $newAmount,
                    'currency' => 'eur',
                    'tax_behavior' => 'exclusive',
                    'recurring' => [
                        'interval' => $interval,
                        'interval_count' => $intervalCount
                    ],
                    'metadata' => [
                        'servicio_id' => $servicio->id,
                        'servicio_nombre' => $servicio->nombre
                    ]
                ]);
                $servicio->stripe_price_id = $price->id;
                Tools::log('solwed')->info(sprintf('Nuevo precio Stripe creado: %s', $price->id));
            }

            return $servicio->save();
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error syncing service to Stripe: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Crea una sesión de checkout para suscripción
     */
    public static function createCheckoutSession(int $idcontacto, Servicio $servicio, string $successUrl, string $cancelUrl): array
    {
        if (!self::init()) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        try {
            $contacto = new Contacto();
            if (!$contacto->load($idcontacto)) {
                return ['success' => false, 'error' => 'Contact not found'];
            }

            if (empty($contacto->email)) {
                return ['success' => false, 'error' => 'Contact email required'];
            }

            // Buscar o crear cliente en Stripe
            $customer = self::findOrCreateCustomer($contacto);
            if (!$customer) {
                return ['success' => false, 'error' => 'Could not create Stripe customer'];
            }

            // Verificar que el servicio tenga precio en Stripe
            if (empty($servicio->stripe_price_id)) {
                // Intentar sincronizar
                if (!self::syncProductToStripe($servicio)) {
                    return ['success' => false, 'error' => 'Service not synced with Stripe'];
                }
            }

            // Crear sesión de checkout para suscripción
            // IMPORTANTE: automatic_tax SIEMPRE activado para aplicar IVA (21% España)
            $session = Session::create([
                'customer' => $customer->id,
                'mode' => 'subscription',
                'automatic_tax' => ['enabled' => true],
                'line_items' => [[
                    'price' => $servicio->stripe_price_id,
                    'quantity' => 1
                ]],
                'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'idcontacto' => $idcontacto,
                    'idservicio' => $servicio->id,
                    'servicio_nombre' => $servicio->nombre
                ],
                'subscription_data' => [
                    'automatic_tax' => ['enabled' => true],
                    'metadata' => [
                        'idcontacto' => $idcontacto,
                        'idservicio' => $servicio->id
                    ]
                ]
            ]);

            return [
                'success' => true,
                'session_id' => $session->id,
                'url' => $session->url
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error creating checkout session: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Busca o crea un cliente en Stripe
     */
    private static function findOrCreateCustomer(Contacto $contacto): ?Customer
    {
        try {
            // Buscar por email
            $search = Customer::search([
                'query' => 'email:"' . $contacto->email . '"',
                'limit' => 1
            ]);

            if (!empty($search->data)) {
                return $search->data[0];
            }

            // Crear nuevo cliente
            return Customer::create([
                'name' => $contacto->fullName(),
                'email' => $contacto->email,
                'metadata' => [
                    'idcontacto' => $contacto->idcontacto,
                    'codcliente' => $contacto->codcliente ?? ''
                ]
            ]);
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error with Stripe customer: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene una suscripción de Stripe
     */
    public static function getSubscription(string $subscriptionId): ?Subscription
    {
        if (!self::init()) {
            return null;
        }

        try {
            return Subscription::retrieve($subscriptionId);
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error retrieving subscription: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cancela una suscripción en Stripe
     */
    public static function cancelSubscription(string $subscriptionId, bool $immediately = false): bool
    {
        if (!self::init()) {
            return false;
        }

        try {
            $subscription = Subscription::retrieve($subscriptionId);

            if ($immediately) {
                $subscription->cancel();
            } else {
                // Cancelar al final del período
                $subscription->update($subscriptionId, [
                    'cancel_at_period_end' => true
                ]);
            }

            // Actualizar ContratServicio
            $contrato = ContratServicio::getByStripeSubscriptionId($subscriptionId);
            if ($contrato) {
                $contrato->estado = ContratServicio::ESTADO_CANCELADO;
                $contrato->save();
            }

            return true;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error canceling subscription: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Procesa una sesión de checkout completada
     */
    public static function processCompletedCheckout(string $sessionId): array
    {
        if (!self::init()) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        try {
            $session = Session::retrieve($sessionId, [
                'expand' => ['subscription', 'customer']
            ]);

            if ($session->payment_status !== 'paid') {
                return ['success' => false, 'error' => 'Payment not completed'];
            }

            $idcontacto = $session->metadata->idcontacto ?? null;
            $idservicio = $session->metadata->idservicio ?? null;

            if (!$idcontacto || !$idservicio) {
                return ['success' => false, 'error' => 'Missing metadata'];
            }

            // Calculate dates - handle API versions that may not include current_period_end
            $sub = $session->subscription;
            $fechaInicio = date('Y-m-d');
            $fechaVencimiento = date('Y-m-d', strtotime('+1 month'));

            if (isset($sub->current_period_start) && $sub->current_period_start > 0) {
                $fechaInicio = date('Y-m-d', $sub->current_period_start);
            }
            if (isset($sub->current_period_end) && $sub->current_period_end > 0) {
                $fechaVencimiento = date('Y-m-d', $sub->current_period_end);
            }

            // Crear ContratServicio (modelo unificado)
            $contrato = new ContratServicio();
            $contrato->idcontacto = (int)$idcontacto;
            $contrato->idservicio = (int)$idservicio;
            $contrato->estado = ContratServicio::ESTADO_ACTIVO;
            $contrato->fecha_inicio = $fechaInicio;
            $contrato->fecha_vencimiento = $fechaVencimiento;
            $contrato->fecha_ultimo_pago = date('Y-m-d');
            $contrato->fecha_proximo_pago = $fechaVencimiento;
            $contrato->metodo_pago = ContratServicio::METODO_STRIPE;
            $contrato->referencia_externa = $sub->id;
            $contrato->stripe_customer_id = $session->customer->id;
            $contrato->auto_renovar = !($sub->cancel_at_period_end ?? false);
            $contrato->importe = ($sub->items->data[0]->price->unit_amount ?? 0) / 100;

            if (!$contrato->save()) {
                return ['success' => false, 'error' => 'Could not save contract'];
            }

            return [
                'success' => true,
                'contrato' => $contrato,
                'subscription_id' => $session->subscription->id
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error processing checkout: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


    // =========================================================================
    // MÉTODOS PARA UPGRADES/DOWNGRADES
    // =========================================================================

    /**
     * Actualiza una suscripción a un nuevo plan (upgrade/downgrade)
     *
     * @param string $subscriptionId ID de suscripción Stripe
     * @param Servicio $nuevoServicio Nuevo servicio/plan
     * @param bool $prorate Aplicar prorrateo (default: true)
     * @return array ['success' => bool, 'subscription' => Subscription|null, 'error' => string|null]
     */
    public static function updateSubscriptionPlan(
        string $subscriptionId,
        Servicio $nuevoServicio,
        bool $prorate = true
    ): array {
        if (!self::init()) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        try {
            // Sincronizar nuevo servicio si no tiene precio en Stripe
            if (empty($nuevoServicio->stripe_price_id)) {
                if (!self::syncProductToStripe($nuevoServicio)) {
                    return ['success' => false, 'error' => 'Could not sync new service to Stripe'];
                }
                // Recargar para obtener IDs actualizados
                $nuevoServicio->load($nuevoServicio->id);
            }

            // Obtener suscripción actual
            $subscription = Subscription::retrieve($subscriptionId);

            // Obtener el item de la suscripción (asumimos 1 item)
            $items = $subscription->items->data;
            if (empty($items)) {
                return ['success' => false, 'error' => 'Subscription has no items'];
            }
            $itemId = $items[0]->id;

            // Actualizar suscripción con nuevo precio
            $updatedSubscription = Subscription::update($subscriptionId, [
                'items' => [[
                    'id' => $itemId,
                    'price' => $nuevoServicio->stripe_price_id,
                ]],
                'proration_behavior' => $prorate ? 'create_prorations' : 'none',
                'metadata' => [
                    'idservicio' => $nuevoServicio->id,
                    'servicio_nombre' => $nuevoServicio->nombre,
                    'upgrade_date' => date('Y-m-d H:i:s')
                ]
            ]);

            // Actualizar ContratServicio
            $contrato = ContratServicio::getByStripeSubscriptionId($subscriptionId);
            if ($contrato) {
                $contrato->idservicio = $nuevoServicio->id;
                // Update dates if available
                if (isset($updatedSubscription->current_period_end) && $updatedSubscription->current_period_end > 0) {
                    $contrato->fecha_proximo_pago = date('Y-m-d', $updatedSubscription->current_period_end);
                    $contrato->fecha_vencimiento = date('Y-m-d', $updatedSubscription->current_period_end);
                }
                $contrato->importe = ($nuevoServicio->precio ?? 0);
                $contrato->save();
            }

            Tools::log('solwed')->info(sprintf(
                'Subscription %s upgraded to service %s (%s)',
                $subscriptionId,
                $nuevoServicio->id,
                $nuevoServicio->nombre
            ));

            return [
                'success' => true,
                'subscription' => $updatedSubscription,
                'proration_amount' => self::getProrationAmount($subscriptionId)
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error updating subscription: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene el monto de prorrateo pendiente para una suscripción
     *
     * @param string $subscriptionId ID de suscripción Stripe
     * @return float Monto en EUR (puede ser positivo o negativo)
     */
    public static function getProrationAmount(string $subscriptionId): float
    {
        if (!self::init()) {
            return 0;
        }

        try {
            $upcomingInvoice = Invoice::upcoming([
                'subscription' => $subscriptionId
            ]);

            $prorationAmount = 0;
            foreach ($upcomingInvoice->lines->data as $line) {
                if ($line->proration) {
                    $prorationAmount += $line->amount;
                }
            }

            return $prorationAmount / 100; // Convertir de céntimos a EUR
        } catch (Exception $e) {
            Tools::log('solwed')->warning('Could not get proration amount: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Obtiene opciones de upgrade/downgrade disponibles para una suscripción
     *
     * @param int $idservicioActual ID del servicio actual
     * @return array ['upgrades' => [...], 'downgrades' => [...]]
     */
    public static function getUpgradeOptions(int $idservicioActual): array
    {
        $servicioActual = new Servicio();
        if (!$servicioActual->load($idservicioActual)) {
            return ['upgrades' => [], 'downgrades' => []];
        }

        // Obtener servicios de la misma categoría con suscripción Stripe
        $servicioModel = new Servicio();
        $where = [
            new DataBaseWhere('activo', true),
            new DataBaseWhere('genera_suscripcion', true),
            new DataBaseWhere('id', $idservicioActual, '!=')
        ];

        // Si tiene categoría, filtrar por ella
        if (!empty($servicioActual->categoria)) {
            $where[] = new DataBaseWhere('categoria', $servicioActual->categoria);
        }

        $servicios = $servicioModel->all($where, ['precio' => 'ASC']);

        $upgrades = [];
        $downgrades = [];

        foreach ($servicios as $servicio) {
            $data = [
                'id' => $servicio->id,
                'nombre' => $servicio->nombre,
                'descripcion' => $servicio->descripcion,
                'precio' => $servicio->precio,
                'diferencia_precio' => round($servicio->precio - $servicioActual->precio, 2),
                'icono' => $servicio->icono,
                'color' => $servicio->color,
                'caracteristicas' => $servicio->getCaracteristicas()
            ];

            if ($servicio->precio > $servicioActual->precio) {
                $upgrades[] = $data;
            } else {
                $downgrades[] = $data;
            }
        }

        return ['upgrades' => $upgrades, 'downgrades' => $downgrades];
    }

    // =========================================================================
    // MÉTODOS PARA BILLING PORTAL
    // =========================================================================

    /**
     * Crea una sesión del portal de facturación de Stripe
     *
     * @param string $stripeCustomerId ID del cliente en Stripe
     * @param string $returnUrl URL de retorno después de gestionar
     * @return array ['success' => bool, 'url' => string|null, 'error' => string|null]
     */
    public static function createBillingPortalSession(
        string $stripeCustomerId,
        string $returnUrl
    ): array {
        if (!self::init()) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        try {
            $session = BillingPortalSession::create([
                'customer' => $stripeCustomerId,
                'return_url' => $returnUrl,
            ]);

            Tools::log('solwed')->info(sprintf(
                'Billing portal session created for customer %s',
                $stripeCustomerId
            ));

            return [
                'success' => true,
                'url' => $session->url
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error creating billing portal session: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene información detallada de una suscripción para mostrar en el portal
     *
     * @param string $subscriptionId ID de suscripción Stripe
     * @return array Detalles de la suscripción
     */
    public static function getSubscriptionDetails(string $subscriptionId): array
    {
        if (!self::init()) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        try {
            $subscription = Subscription::retrieve($subscriptionId, [
                'expand' => ['latest_invoice', 'customer', 'default_payment_method']
            ]);

            $paymentMethod = null;
            if ($subscription->default_payment_method) {
                $pm = $subscription->default_payment_method;
                if ($pm->type === 'card' && $pm->card) {
                    $paymentMethod = [
                        'type' => 'card',
                        'last4' => $pm->card->last4,
                        'brand' => $pm->card->brand,
                        'exp_month' => $pm->card->exp_month,
                        'exp_year' => $pm->card->exp_year
                    ];
                } else {
                    $paymentMethod = [
                        'type' => $pm->type,
                        'last4' => null,
                        'brand' => null
                    ];
                }
            }

            return [
                'success' => true,
                'status' => $subscription->status,
                'current_period_start' => isset($subscription->current_period_start) && $subscription->current_period_start > 0
                    ? date('Y-m-d', $subscription->current_period_start)
                    : date('Y-m-d'),
                'current_period_end' => isset($subscription->current_period_end) && $subscription->current_period_end > 0
                    ? date('Y-m-d', $subscription->current_period_end)
                    : null,
                'cancel_at_period_end' => $subscription->cancel_at_period_end ?? false,
                'next_invoice_amount' => $subscription->latest_invoice
                    ? $subscription->latest_invoice->amount_due / 100
                    : null,
                'payment_method' => $paymentMethod,
                'created' => date('Y-m-d', $subscription->created)
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error getting subscription details: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // MÉTODOS PARA SUSCRIPCIONES DE DOMINIOS (AUTO-RENOVACIÓN)
    // =========================================================================

    /**
     * Crea una sesión de checkout para suscripción de auto-renovación de dominio
     *
     * @param Dominio $dominio El dominio a renovar automáticamente
     * @param Contacto $contacto El contacto propietario
     * @param string $successUrl URL de retorno en caso de éxito
     * @param string $cancelUrl URL de retorno en caso de cancelación
     * @return array ['success' => bool, 'url' => string|null, 'session_id' => string|null, 'error' => string|null]
     */
    public static function createDomainAutoRenewalCheckout(
        Dominio $dominio,
        Contacto $contacto,
        string $successUrl,
        string $cancelUrl
    ): array {
        if (!self::init()) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        try {
            if (empty($contacto->email)) {
                return ['success' => false, 'error' => 'Contact email required'];
            }

            // Buscar o crear cliente en Stripe
            $customer = self::findOrCreateCustomer($contacto);
            if (!$customer) {
                return ['success' => false, 'error' => 'Could not create Stripe customer'];
            }

            // Obtener o crear el precio para renovación de dominio
            $priceId = self::getOrCreateDomainRenewalPrice($dominio);
            if (!$priceId) {
                return ['success' => false, 'error' => 'Could not get domain renewal price'];
            }

            $nombreCompleto = $dominio->getNombreCompleto();

            // Calcular billing_cycle_anchor alineado con la fecha de expiración del dominio
            $billingAnchor = null;
            if (!empty($dominio->fecha_expiracion)) {
                $expDate = new \DateTime($dominio->fecha_expiracion);
                // Ajustar a 30 días antes de la expiración para dar margen
                $expDate->sub(new \DateInterval('P30D'));
                $billingAnchor = $expDate->getTimestamp();

                // Si la fecha ya pasó, usar la fecha de expiración directamente
                if ($billingAnchor < time()) {
                    $expDate = new \DateTime($dominio->fecha_expiracion);
                    $billingAnchor = $expDate->getTimestamp();
                }

                // Si aún está en el pasado, no usar anchor (cobro inmediato)
                if ($billingAnchor < time()) {
                    $billingAnchor = null;
                }
            }

            // Crear sesión de checkout para suscripción
            $sessionParams = [
                'customer' => $customer->id,
                'mode' => 'subscription',
                'automatic_tax' => ['enabled' => true],
                'line_items' => [[
                    'price' => $priceId,
                    'quantity' => 1
                ]],
                'success_url' => $successUrl . (strpos($successUrl, '?') !== false ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'type' => 'domain_auto_renewal',
                    'idcontacto' => $contacto->idcontacto,
                    'iddominio' => $dominio->id,
                    'domain' => $nombreCompleto
                ],
                'subscription_data' => [
                    'metadata' => [
                        'type' => 'domain_auto_renewal',
                        'idcontacto' => $contacto->idcontacto,
                        'iddominio' => $dominio->id,
                        'domain' => $nombreCompleto
                    ]
                ]
            ];

            // Añadir billing_cycle_anchor si está disponible
            if ($billingAnchor) {
                $sessionParams['subscription_data']['billing_cycle_anchor'] = $billingAnchor;
                $sessionParams['subscription_data']['proration_behavior'] = 'none';
            }

            $session = Session::create($sessionParams);

            Tools::log('solwed')->info(sprintf(
                'Domain auto-renewal checkout session created: %s for domain %s',
                $session->id,
                $nombreCompleto
            ));

            return [
                'success' => true,
                'session_id' => $session->id,
                'url' => $session->url
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error creating domain auto-renewal checkout: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene o crea un precio de Stripe para renovación de dominio
     *
     * @param Dominio $dominio El dominio
     * @return string|null El ID del precio de Stripe
     */
    private static function getOrCreateDomainRenewalPrice(Dominio $dominio): ?string
    {
        try {
            // Obtener precio de renovación según TLD desde settings
            $tld = ltrim($dominio->tld, '.');
            $priceKey = 'domain_renewal_price_' . str_replace('.', '_', $tld);
            $renewalPrice = (float) Tools::settings('dondominio', $priceKey, 0);

            // Si no hay precio específico para el TLD, usar precio por defecto
            if ($renewalPrice <= 0) {
                $renewalPrice = (float) Tools::settings('dondominio', 'domain_renewal_price_default', 15.00);
            }

            // Convertir a céntimos
            $priceInCents = (int) ($renewalPrice * 100);

            // Buscar producto existente para renovaciones de dominio
            $productId = Tools::settings('stripe', 'domain_renewal_product_id', '');

            if (empty($productId)) {
                // Crear producto para renovaciones de dominio
                $product = Product::create([
                    'name' => 'Renovación de Dominio',
                    'description' => 'Renovación anual automática de dominio',
                    'metadata' => [
                        'type' => 'domain_renewal'
                    ]
                ]);
                $productId = $product->id;
                Tools::settingsSet('stripe', 'domain_renewal_product_id', $productId);
                Tools::settingsSave();
                Tools::log('solwed')->info('Domain renewal product created: ' . $productId);
            }

            // Buscar precio existente para este TLD
            $priceIdKey = 'domain_renewal_stripe_price_' . str_replace('.', '_', $tld);
            $existingPriceId = Tools::settings('stripe', $priceIdKey, '');

            if (!empty($existingPriceId)) {
                // Verificar que el precio existe y tiene el monto correcto
                try {
                    $existingPrice = Price::retrieve($existingPriceId);
                    if ($existingPrice->active && $existingPrice->unit_amount === $priceInCents) {
                        return $existingPriceId;
                    }
                    // Archivar precio anterior si el monto cambió
                    Price::update($existingPriceId, ['active' => false]);
                } catch (Exception $e) {
                    // Precio no existe, crear uno nuevo
                }
            }

            // Crear nuevo precio para este TLD
            $price = Price::create([
                'product' => $productId,
                'unit_amount' => $priceInCents,
                'currency' => 'eur',
                'tax_behavior' => 'exclusive',
                'recurring' => [
                    'interval' => 'year',
                    'interval_count' => 1
                ],
                'metadata' => [
                    'type' => 'domain_renewal',
                    'tld' => $tld
                ]
            ]);

            // Guardar precio ID
            Tools::settingsSet('stripe', $priceIdKey, $price->id);
            Tools::settingsSave();

            Tools::log('solwed')->info(sprintf(
                'Domain renewal price created for .%s: %s (€%.2f/year)',
                $tld,
                $price->id,
                $renewalPrice
            ));

            return $price->id;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error getting/creating domain renewal price: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cancela la suscripción de auto-renovación de un dominio
     *
     * @param Dominio $dominio El dominio
     * @param bool $immediately Si true, cancela inmediatamente; si false, al final del período
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function cancelDomainAutoRenewal(Dominio $dominio, bool $immediately = false): array
    {
        if (!self::init()) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        // Get contract linked to domain
        $contrato = $dominio->getContrato();
        if (!$contrato || empty($contrato->referencia_externa)) {
            // No contract or no Stripe subscription
            $dominio->observaciones = ($dominio->observaciones ?? '') .
                "\n[" . date('Y-m-d') . "] Auto-renovación desactivada (sin suscripción)";
            $dominio->save();
            return ['success' => true, 'message' => 'Auto-renewal disabled'];
        }

        $subscriptionId = $contrato->referencia_externa;

        try {
            $subscription = Subscription::retrieve($subscriptionId);

            if ($immediately) {
                $subscription->cancel();
            } else {
                Subscription::update($subscriptionId, [
                    'cancel_at_period_end' => true
                ]);
            }

            // Update contract
            if ($immediately) {
                $contrato->estado = ContratServicio::ESTADO_CANCELADO;
                $contrato->auto_renovar = false;
                $contrato->save();

                $dominio->idcontrato = null;
                $dominio->observaciones = ($dominio->observaciones ?? '') .
                    "\n[" . date('Y-m-d') . "] Auto-renovación cancelada inmediatamente";
                $dominio->save();
            } else {
                // Keep contract until period ends
                $contrato->auto_renovar = false;
                $contrato->save();

                $cancelDate = isset($subscription->current_period_end) && $subscription->current_period_end > 0
                    ? date('Y-m-d', $subscription->current_period_end)
                    : 'fecha de vencimiento';
                $dominio->observaciones = ($dominio->observaciones ?? '') .
                    "\n[" . date('Y-m-d') . "] Auto-renovación cancelada - se desactivará el " . $cancelDate;
                $dominio->save();
            }

            Tools::log('solwed')->info(sprintf(
                'Domain auto-renewal cancelled for %s (contract: %d, immediately: %s)',
                $dominio->getNombreCompleto(),
                $contrato->id,
                $immediately ? 'yes' : 'no'
            ));

            return ['success' => true];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error cancelling domain auto-renewal: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene el precio de renovación para un TLD
     *
     * @param string $tld TLD (ej: .com, .es)
     * @return float Precio en EUR
     */
    public static function getDomainRenewalPrice(string $tld): float
    {
        $tld = ltrim($tld, '.');
        $priceKey = 'domain_renewal_price_' . str_replace('.', '_', $tld);
        $price = (float) Tools::settings('dondominio', $priceKey, 0);

        if ($price <= 0) {
            $price = (float) Tools::settings('dondominio', 'domain_renewal_price_default', 15.00);
        }

        return $price;
    }
}
