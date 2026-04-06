<?php

/**
 * Plugin SolwedES - Gestión de Suscripciones Stripe
 *
 * Business logic for managing subscriptions stays here.
 * All Stripe API calls go through BridgeClient → solwed-bridge.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use Exception;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;

class StripeSubscriptionManager
{
    /**
     * Sincroniza un servicio con Stripe (crea Product y Price)
     */
    public static function syncProductToStripe(Servicio $servicio): bool
    {
        if ($servicio->meses_recurrencia <= 0) {
            Tools::log('solwed')->warning('Servicio sin recurrencia válida, no se sincroniza con Stripe');
            return false;
        }

        try {
            $interval = 'month';
            $intervalCount = 1;
            if ($servicio->meses_recurrencia >= 12) {
                $interval = 'year';
                $intervalCount = 1;
            } elseif ($servicio->meses_recurrencia > 1) {
                $intervalCount = $servicio->meses_recurrencia;
            }

            // Create or update product
            if (empty($servicio->stripe_product_id)) {
                $result = BridgeClient::post('/stripe/products', [
                    'name' => $servicio->nombre,
                    'description' => $servicio->descripcion ?? '',
                    'metadata' => [
                        'servicio_id' => (string)$servicio->id,
                        'categoria' => $servicio->categoria ?? '',
                    ],
                ]);
                if (!($result['ok'] ?? false)) {
                    Tools::log('solwed')->error('Failed to create Stripe product: ' . ($result['error'] ?? ''));
                    return false;
                }
                $servicio->stripe_product_id = $result['data']['id'] ?? '';
            } else {
                BridgeClient::put('/stripe/products/' . $servicio->stripe_product_id, [
                    'name' => $servicio->nombre,
                    'description' => $servicio->descripcion ?? '',
                ]);
            }

            $newAmount = (int)($servicio->precio * 100);
            $needNewPrice = true;

            // Check if existing price needs updating
            if (!empty($servicio->stripe_price_id)) {
                // We can't easily retrieve a price via bridge without a dedicated endpoint,
                // so we always create a new price if the amount changed.
                // Archive old price
                BridgeClient::post('/stripe/prices/' . $servicio->stripe_price_id . '/archive', []);
            }

            if ($needNewPrice) {
                $result = BridgeClient::post('/stripe/prices', [
                    'product' => $servicio->stripe_product_id,
                    'unit_amount' => (string)$newAmount,
                    'currency' => 'eur',
                    'tax_behavior' => 'exclusive',
                    'recurring' => [
                        'interval' => $interval,
                        'interval_count' => (string)$intervalCount,
                    ],
                    'metadata' => [
                        'servicio_id' => (string)$servicio->id,
                        'servicio_nombre' => $servicio->nombre,
                    ],
                ]);
                if (!($result['ok'] ?? false)) {
                    Tools::log('solwed')->error('Failed to create Stripe price: ' . ($result['error'] ?? ''));
                    return false;
                }
                $servicio->stripe_price_id = $result['data']['id'] ?? '';
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
        try {
            $contacto = new Contacto();
            if (!$contacto->load($idcontacto)) {
                return ['success' => false, 'error' => 'Contact not found'];
            }
            if (empty($contacto->email)) {
                return ['success' => false, 'error' => 'Contact email required'];
            }

            $customer = self::findOrCreateCustomer($contacto);
            if (!$customer) {
                return ['success' => false, 'error' => 'Could not create Stripe customer'];
            }

            if (empty($servicio->stripe_price_id)) {
                if (!self::syncProductToStripe($servicio)) {
                    return ['success' => false, 'error' => 'Service not synced with Stripe'];
                }
            }

            $result = BridgeClient::post('/stripe/checkout-sessions', [
                'customer' => $customer['id'],
                'mode' => 'subscription',
                'automatic_tax' => ['enabled' => 'true'],
                'line_items' => [['price' => $servicio->stripe_price_id, 'quantity' => '1']],
                'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'idcontacto' => (string)$idcontacto,
                    'idservicio' => (string)$servicio->id,
                    'servicio_nombre' => $servicio->nombre,
                ],
                'subscription_data' => [
                    'metadata' => [
                        'idcontacto' => (string)$idcontacto,
                        'idservicio' => (string)$servicio->id,
                    ],
                ],
            ]);

            if (!($result['ok'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'Bridge error'];
            }

            return [
                'success' => true,
                'session_id' => $result['data']['id'] ?? '',
                'url' => $result['data']['url'] ?? '',
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error creating checkout session: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene una suscripción de Stripe
     */
    public static function getSubscription(string $subscriptionId): ?array
    {
        $result = BridgeClient::get('/stripe/subscriptions/' . $subscriptionId);
        return ($result['ok'] ?? false) ? ($result['data'] ?? null) : null;
    }

    /**
     * Cancela una suscripción en Stripe
     */
    public static function cancelSubscription(string $subscriptionId, bool $immediately = false): bool
    {
        try {
            $result = BridgeClient::post('/stripe/subscriptions/' . $subscriptionId . '/cancel', [
                'immediately' => $immediately,
            ]);

            if (!($result['ok'] ?? false)) {
                return false;
            }

            $suscripcion = Suscripcion::getByStripeSubscriptionId($subscriptionId);
            if ($suscripcion) {
                $suscripcion->estado = Suscripcion::ESTADO_CANCELADO;
                $suscripcion->save();
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
        try {
            $result = BridgeClient::get('/stripe/checkout-sessions/' . $sessionId);
            if (!($result['ok'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'Bridge error'];
            }

            $session = $result['data'] ?? [];

            if (($session['payment_status'] ?? '') !== 'paid') {
                return ['success' => false, 'error' => 'Payment not completed'];
            }

            $idcontacto = $session['metadata']['idcontacto'] ?? null;
            $idservicio = $session['metadata']['idservicio'] ?? null;

            if (!$idcontacto || !$idservicio) {
                return ['success' => false, 'error' => 'Missing metadata'];
            }

            $sub = $session['subscription'] ?? [];
            $fechaInicio = date('Y-m-d');
            $fechaVencimiento = date('Y-m-d', strtotime('+1 month'));

            if (!empty($sub['current_period_start']) && $sub['current_period_start'] > 0) {
                $fechaInicio = date('Y-m-d', $sub['current_period_start']);
            }
            if (!empty($sub['current_period_end']) && $sub['current_period_end'] > 0) {
                $fechaVencimiento = date('Y-m-d', $sub['current_period_end']);
            }

            $suscripcion = new Suscripcion();
            $suscripcion->idcontacto = (int)$idcontacto;
            $suscripcion->idservicio = (int)$idservicio;
            $suscripcion->estado = Suscripcion::ESTADO_ACTIVO;
            $suscripcion->fecha_inicio = $fechaInicio;
            $suscripcion->fecha_vencimiento = $fechaVencimiento;
            $suscripcion->fecha_ultimo_pago = date('Y-m-d');
            $suscripcion->fecha_proximo_pago = $fechaVencimiento;
            $suscripcion->metodo_pago = Suscripcion::METODO_STRIPE;
            $suscripcion->referencia_externa = $sub['id'] ?? '';
            $suscripcion->stripe_customer_id = $session['customer']['id'] ?? $session['customer'] ?? '';
            $suscripcion->auto_renovar = !($sub['cancel_at_period_end'] ?? false);
            $suscripcion->importe = ($sub['items']['data'][0]['price']['unit_amount'] ?? 0) / 100;

            if (!$suscripcion->save()) {
                return ['success' => false, 'error' => 'Could not save contract'];
            }

            return [
                'success' => true,
                'contrato' => $suscripcion,
                'subscription_id' => $sub['id'] ?? '',
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error processing checkout: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Actualiza una suscripción a un nuevo plan (upgrade/downgrade)
     */
    public static function updateSubscriptionPlan(
        string $subscriptionId,
        Servicio $nuevoServicio,
        bool $prorate = true
    ): array {
        try {
            if (empty($nuevoServicio->stripe_price_id)) {
                if (!self::syncProductToStripe($nuevoServicio)) {
                    return ['success' => false, 'error' => 'Could not sync new service to Stripe'];
                }
                $nuevoServicio->load($nuevoServicio->id);
            }

            // Get current subscription to find item ID
            $subData = self::getSubscription($subscriptionId);
            if (!$subData) {
                return ['success' => false, 'error' => 'Subscription not found'];
            }

            $items = $subData['items']['data'] ?? [];
            if (empty($items)) {
                return ['success' => false, 'error' => 'Subscription has no items'];
            }
            $itemId = $items[0]['id'];

            $result = BridgeClient::put('/stripe/subscriptions/' . $subscriptionId, [
                'items' => [['id' => $itemId, 'price' => $nuevoServicio->stripe_price_id]],
                'proration_behavior' => $prorate ? 'create_prorations' : 'none',
                'metadata' => [
                    'idservicio' => (string)$nuevoServicio->id,
                    'servicio_nombre' => $nuevoServicio->nombre,
                    'upgrade_date' => date('Y-m-d H:i:s'),
                ],
            ]);

            if (!($result['ok'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'Bridge error'];
            }

            // Update local record
            $suscripcion = Suscripcion::getByStripeSubscriptionId($subscriptionId);
            if ($suscripcion) {
                $suscripcion->idservicio = $nuevoServicio->id;
                $updatedSub = $result['data'] ?? [];
                if (!empty($updatedSub['current_period_end']) && $updatedSub['current_period_end'] > 0) {
                    $suscripcion->fecha_proximo_pago = date('Y-m-d', $updatedSub['current_period_end']);
                    $suscripcion->fecha_vencimiento = date('Y-m-d', $updatedSub['current_period_end']);
                }
                $suscripcion->importe = $nuevoServicio->precio ?? 0;
                $suscripcion->save();
            }

            return ['success' => true, 'subscription' => $result['data'] ?? null];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error updating subscription: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene el monto de prorrateo pendiente para una suscripción
     */
    public static function getProrationAmount(string $subscriptionId): float
    {
        // Bridge generic proxy for upcoming invoice
        $result = BridgeClient::get('/stripe/invoices/upcoming', ['subscription' => $subscriptionId]);
        if (!($result['ok'] ?? false)) {
            return 0;
        }

        $prorationAmount = 0;
        foreach (($result['data']['lines']['data'] ?? []) as $line) {
            if ($line['proration'] ?? false) {
                $prorationAmount += $line['amount'] ?? 0;
            }
        }

        return $prorationAmount / 100;
    }

    /**
     * Obtiene opciones de upgrade/downgrade disponibles
     */
    public static function getUpgradeOptions(int $idservicioActual): array
    {
        $servicioActual = new Servicio();
        if (!$servicioActual->load($idservicioActual)) {
            return ['upgrades' => [], 'downgrades' => []];
        }

        $servicioModel = new Servicio();
        $where = [
            new DataBaseWhere('activo', true),
            new DataBaseWhere('genera_suscripcion', true),
            new DataBaseWhere('id', $idservicioActual, '!='),
        ];

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
                'caracteristicas' => $servicio->getCaracteristicas(),
            ];

            if ($servicio->precio > $servicioActual->precio) {
                $upgrades[] = $data;
            } else {
                $downgrades[] = $data;
            }
        }

        return ['upgrades' => $upgrades, 'downgrades' => $downgrades];
    }

    /**
     * Crea una sesión del portal de facturación de Stripe
     */
    public static function createBillingPortalSession(string $stripeCustomerId, string $returnUrl): array
    {
        $result = BridgeClient::post('/stripe/billing-portal', [
            'customerId' => $stripeCustomerId,
            'returnUrl' => $returnUrl,
        ]);

        if (!($result['ok'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'Bridge error'];
        }

        return ['success' => true, 'url' => $result['data']['url'] ?? ''];
    }

    /**
     * Obtiene información detallada de una suscripción para el portal
     */
    public static function getSubscriptionDetails(string $subscriptionId): array
    {
        $subData = self::getSubscription($subscriptionId);
        if (!$subData) {
            return ['success' => false, 'error' => 'Subscription not found'];
        }

        $paymentMethod = null;
        $pm = $subData['default_payment_method'] ?? null;
        if ($pm) {
            if (($pm['type'] ?? '') === 'card' && !empty($pm['card'])) {
                $paymentMethod = [
                    'type' => 'card',
                    'last4' => $pm['card']['last4'] ?? null,
                    'brand' => $pm['card']['brand'] ?? null,
                    'exp_month' => $pm['card']['exp_month'] ?? null,
                    'exp_year' => $pm['card']['exp_year'] ?? null,
                ];
            } else {
                $paymentMethod = ['type' => $pm['type'] ?? 'unknown', 'last4' => null, 'brand' => null];
            }
        }

        return [
            'success' => true,
            'status' => $subData['status'] ?? 'unknown',
            'current_period_start' => !empty($subData['current_period_start']) ? date('Y-m-d', $subData['current_period_start']) : date('Y-m-d'),
            'current_period_end' => !empty($subData['current_period_end']) ? date('Y-m-d', $subData['current_period_end']) : null,
            'cancel_at_period_end' => $subData['cancel_at_period_end'] ?? false,
            'payment_method' => $paymentMethod,
            'created' => isset($subData['created']) ? date('Y-m-d', $subData['created']) : null,
        ];
    }

    // ─── Domain auto-renewal ─────────────────────────────────

    /**
     * Crea una sesión de checkout para auto-renovación de dominio
     */
    public static function createDomainAutoRenewalCheckout(
        Dominio $dominio,
        Contacto $contacto,
        string $successUrl,
        string $cancelUrl
    ): array {
        try {
            if (empty($contacto->email)) {
                return ['success' => false, 'error' => 'Contact email required'];
            }

            $customer = self::findOrCreateCustomer($contacto);
            if (!$customer) {
                return ['success' => false, 'error' => 'Could not create Stripe customer'];
            }

            $priceId = self::getOrCreateDomainRenewalPrice($dominio);
            if (!$priceId) {
                return ['success' => false, 'error' => 'Could not get domain renewal price'];
            }

            $nombreCompleto = $dominio->getNombreCompleto();

            // Calculate billing anchor
            $subscriptionData = [
                'metadata' => [
                    'type' => 'domain_auto_renewal',
                    'idcontacto' => (string)$contacto->idcontacto,
                    'iddominio' => (string)$dominio->id,
                    'domain' => $nombreCompleto,
                ],
            ];

            if (!empty($dominio->fecha_expiracion)) {
                $expDate = new \DateTime($dominio->fecha_expiracion);
                $expDate->sub(new \DateInterval('P30D'));
                $billingAnchor = $expDate->getTimestamp();

                if ($billingAnchor < time()) {
                    $expDate = new \DateTime($dominio->fecha_expiracion);
                    $billingAnchor = $expDate->getTimestamp();
                }

                if ($billingAnchor > time()) {
                    $subscriptionData['billing_cycle_anchor'] = (string)$billingAnchor;
                    $subscriptionData['proration_behavior'] = 'none';
                }
            }

            $result = BridgeClient::post('/stripe/checkout-sessions', [
                'customer' => $customer['id'],
                'mode' => 'subscription',
                'automatic_tax' => ['enabled' => 'true'],
                'line_items' => [['price' => $priceId, 'quantity' => '1']],
                'success_url' => $successUrl . (strpos($successUrl, '?') !== false ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'type' => 'domain_auto_renewal',
                    'idcontacto' => (string)$contacto->idcontacto,
                    'iddominio' => (string)$dominio->id,
                    'domain' => $nombreCompleto,
                ],
                'subscription_data' => $subscriptionData,
            ]);

            if (!($result['ok'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'Bridge error'];
            }

            return [
                'success' => true,
                'session_id' => $result['data']['id'] ?? '',
                'url' => $result['data']['url'] ?? '',
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error creating domain auto-renewal checkout: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cancela la suscripción de auto-renovación de un dominio
     */
    public static function cancelDomainAutoRenewal(Dominio $dominio, bool $immediately = false): array
    {
        $suscripcion = $dominio->getSuscripcion();
        if (!$suscripcion || empty($suscripcion->referencia_externa)) {
            $dominio->observaciones = ($dominio->observaciones ?? '') .
                "\n[" . date('Y-m-d') . "] Auto-renovación desactivada (sin suscripción)";
            $dominio->save();
            return ['success' => true, 'message' => 'Auto-renewal disabled'];
        }

        $subscriptionId = $suscripcion->referencia_externa;

        try {
            $result = BridgeClient::post('/stripe/subscriptions/' . $subscriptionId . '/cancel', [
                'immediately' => $immediately,
            ]);

            if (!($result['ok'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'Bridge error'];
            }

            if ($immediately) {
                $suscripcion->estado = Suscripcion::ESTADO_CANCELADO;
                $suscripcion->auto_renovar = false;
                $suscripcion->save();

                $dominio->idsuscripcion = null;
                $dominio->observaciones = ($dominio->observaciones ?? '') .
                    "\n[" . date('Y-m-d') . "] Auto-renovación cancelada inmediatamente";
                $dominio->save();
            } else {
                $suscripcion->auto_renovar = false;
                $suscripcion->save();

                $sub = $result['data'] ?? [];
                $cancelDate = !empty($sub['current_period_end']) ? date('Y-m-d', $sub['current_period_end']) : 'fecha de vencimiento';
                $dominio->observaciones = ($dominio->observaciones ?? '') .
                    "\n[" . date('Y-m-d') . "] Auto-renovación cancelada - se desactivará el " . $cancelDate;
                $dominio->save();
            }

            return ['success' => true];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error cancelling domain auto-renewal: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obtiene el precio de renovación para un TLD
     */
    public static function getDomainRenewalPrice(string $tld): float
    {
        $tld = ltrim($tld, '.');
        $priceKey = 'domain_renewal_price_' . str_replace('.', '_', $tld);
        $price = (float)Tools::settings('dondominio', $priceKey, 0);
        if ($price <= 0) {
            $price = (float)Tools::settings('dondominio', 'domain_renewal_price_default', 15.00);
        }
        return $price;
    }

    // ─── Private helpers ─────────────────────────────────────

    private static function findOrCreateCustomer(Contacto $contacto): ?array
    {
        return StripeHelper::findOrCreateCustomer(
            $contacto->email,
            $contacto->fullName(),
            [
                'idcontacto' => (string)$contacto->idcontacto,
                'codcliente' => $contacto->codcliente ?? '',
            ]
        );
    }

    private static function getOrCreateDomainRenewalPrice(Dominio $dominio): ?string
    {
        try {
            $tld = ltrim($dominio->tld, '.');
            $renewalPrice = self::getDomainRenewalPrice($tld);
            $priceInCents = (int)($renewalPrice * 100);

            $productId = Tools::settings('stripe', 'domain_renewal_product_id', '');
            if (empty($productId)) {
                $result = BridgeClient::post('/stripe/products', [
                    'name' => 'Renovación de Dominio',
                    'description' => 'Renovación anual automática de dominio',
                    'metadata' => ['type' => 'domain_renewal'],
                ]);
                if (!($result['ok'] ?? false)) {
                    return null;
                }
                $productId = $result['data']['id'] ?? '';
                Tools::settingsSet('stripe', 'domain_renewal_product_id', $productId);
                Tools::settingsSave();
            }

            $priceIdKey = 'domain_renewal_stripe_price_' . str_replace('.', '_', $tld);
            $existingPriceId = Tools::settings('stripe', $priceIdKey, '');

            if (!empty($existingPriceId)) {
                // Assume the price is still valid — bridge will error if not
                return $existingPriceId;
            }

            $result = BridgeClient::post('/stripe/prices', [
                'product' => $productId,
                'unit_amount' => (string)$priceInCents,
                'currency' => 'eur',
                'tax_behavior' => 'exclusive',
                'recurring' => ['interval' => 'year', 'interval_count' => '1'],
                'metadata' => ['type' => 'domain_renewal', 'tld' => $tld],
            ]);

            if (!($result['ok'] ?? false)) {
                return null;
            }

            $priceId = $result['data']['id'] ?? '';
            Tools::settingsSet('stripe', $priceIdKey, $priceId);
            Tools::settingsSave();

            return $priceId;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error getting/creating domain renewal price: ' . $e->getMessage());
            return null;
        }
    }
}
