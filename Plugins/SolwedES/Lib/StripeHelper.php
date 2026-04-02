<?php

/**
 * Plugin SolwedES - Helper para interactuar con Stripe API
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use Exception;
use FacturaScripts\Core\Tools;

/**
 * Helper para centralizar interacción con Stripe API
 * Usa Tools::settings('stripe', ...) como fuente de credenciales
 */
class StripeHelper
{
    /**
     * Obtiene un valor de configuración de Stripe
     *
     * @param string $key Clave de configuración
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    public static function getSetting(string $key, $default = '')
    {
        return Tools::settings('stripe', $key, $default);
    }

    /**
     * Verifica si la configuración de Stripe está completa
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $secretKey = self::getSetting('stripe_secret_key');
        $webhookSecret = self::getSetting('stripe_webhook_secret');
        return !empty($secretKey) && !empty($webhookSecret);
    }

    /**
     * Verifica si estamos en modo test
     *
     * @return bool
     */
    public static function isTestMode(): bool
    {
        $secretKey = self::getSetting('stripe_secret_key', '');
        return strpos($secretKey, 'test') !== false;
    }

    /**
     * Inicializa Stripe SDK con las credenciales configuradas
     *
     * @return bool True si se inicializó correctamente
     */
    public static function initStripe(): bool
    {
        $secretKey = self::getSetting('stripe_secret_key');

        if (empty($secretKey)) {
            Tools::log('solwed')->error('Stripe secret key not configured');
            return false;
        }

        try {
            \Stripe\Stripe::setApiKey($secretKey);
            \Stripe\Stripe::setApiVersion('2025-12-15.clover');
            #\Stripe\Stripe::setApiVersion('2023-10-16');
            return true;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error initializing Stripe: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene detalles del método de pago desde un PaymentIntent
     *
     * @param string $paymentIntentId ID del PaymentIntent de Stripe
     * @return array Detalles del método de pago
     */
    public static function getPaymentMethodDetails(string $paymentIntentId): array
    {
        if (!self::initStripe()) {
            return [
                'success' => false,
                'error' => 'Stripe not initialized'
            ];
        }

        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId, [
                'expand' => ['payment_method']
            ]);

            if (!$paymentIntent->payment_method) {
                return [
                    'success' => true,
                    'type' => 'unknown',
                    'brand' => 'Desconocido',
                    'last4' => 'N/A',
                    'fecha' => date('Y-m-d H:i:s', $paymentIntent->created),
                    'amount' => $paymentIntent->amount / 100,
                    'currency' => strtoupper($paymentIntent->currency)
                ];
            }

            $pm = $paymentIntent->payment_method;
            $result = [
                'success' => true,
                'type' => $pm->type,
                'fecha' => date('Y-m-d H:i:s', $paymentIntent->created),
                'amount' => $paymentIntent->amount / 100,
                'currency' => strtoupper($paymentIntent->currency)
            ];

            if ($pm->type === 'card' && $pm->card) {
                $result['brand'] = ucfirst($pm->card->brand);
                $result['last4'] = $pm->card->last4;
                $result['exp_month'] = $pm->card->exp_month;
                $result['exp_year'] = $pm->card->exp_year;
                $result['country'] = $pm->card->country;
            } elseif ($pm->type === 'sepa_debit' && $pm->sepa_debit) {
                $result['brand'] = 'SEPA';
                $result['last4'] = $pm->sepa_debit->last4;
                $result['country'] = $pm->sepa_debit->country;
            } else {
                $result['brand'] = ucfirst($pm->type);
                $result['last4'] = 'N/A';
            }

            Tools::log('solwed')->info(sprintf(
                'Payment method details retrieved: %s **** %s',
                $result['brand'] ?? 'unknown',
                $result['last4'] ?? 'N/A'
            ));

            return $result;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error getting payment method details: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica la firma del webhook de Stripe
     *
     * @param string $payload Payload del webhook (body raw)
     * @param string $signature Firma del header HTTP_STRIPE_SIGNATURE
     * @return object Evento de Stripe verificado
     * @throws Exception Si la firma no es válida
     */
    public static function verifyWebhookSignature(string $payload, string $signature): object
    {
        $webhookSecret = self::getSetting('stripe_webhook_secret');

        if (empty($webhookSecret)) {
            throw new Exception('Webhook secret not configured');
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );

            Tools::log('solwed')->info('Webhook signature verified successfully');
            return $event;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Tools::log('solwed')->error('Invalid webhook signature: ' . $e->getMessage());
            throw new Exception('Invalid webhook signature');
        }
    }

    /**
     * Obtiene el Tax ID (CIF/NIF) del cliente de Stripe
     *
     * @param string $customerId ID del cliente en Stripe
     * @return string|null
     */
    public static function getCustomerTaxId(string $customerId): ?string
    {
        if (empty($customerId) || !self::initStripe()) {
            return null;
        }

        try {
            $taxIds = \Stripe\Customer::allTaxIds($customerId, ["limit" => 1]);
            if (!empty($taxIds->data)) {
                return $taxIds->data[0]->value;
            }
            return null;
        } catch (Exception $e) {
            Tools::log('solwed')->warning('Could not get customer tax ID: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca o crea un cliente en Stripe
     *
     * @param string $email Email del cliente
     * @param string $name Nombre del cliente
     * @param array $metadata Metadatos adicionales
     * @return \Stripe\Customer|null
     */
    public static function findOrCreateCustomer(string $email, string $name = '', array $metadata = []): ?\Stripe\Customer
    {
        if (!self::initStripe()) {
            return null;
        }

        try {
            // Buscar por email
            $search = \Stripe\Customer::search([
                'query' => 'email:"' . $email . '"',
                'limit' => 1
            ]);

            if (!empty($search->data)) {
                return $search->data[0];
            }

            // Crear nuevo cliente
            return \Stripe\Customer::create([
                'name' => $name ?: $email,
                'email' => $email,
                'metadata' => $metadata
            ]);
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error with Stripe customer: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crea un Tax ID para un cliente de Stripe
     *
     * @param string $customerId ID del cliente en Stripe
     * @param string $taxId CIF/NIF/VAT del cliente
     * @param string $country Código de país (ES, FR, etc.)
     * @return bool
     */
    public static function createCustomerTaxId(string $customerId, string $taxId, string $country = 'ES'): bool
    {
        if (empty($customerId) || empty($taxId) || !self::initStripe()) {
            return false;
        }

        try {
            // Determinar el tipo de Tax ID según el país y formato
            $taxIdType = self::determineTaxIdType($taxId, $country);

            if (empty($taxIdType)) {
                Tools::log('solwed')->warning(sprintf(
                    'Could not determine tax ID type for: %s (country: %s)',
                    $taxId,
                    $country
                ));
                return false;
            }

            // Limpiar el tax ID (quitar espacios y guiones)
            $cleanTaxId = preg_replace('/[\s\-]/', '', $taxId);

            \Stripe\Customer::createTaxId($customerId, [
                'type' => $taxIdType,
                'value' => $cleanTaxId
            ]);

            Tools::log('solwed')->info(sprintf(
                'Tax ID created for customer %s: %s (%s)',
                $customerId,
                $cleanTaxId,
                $taxIdType
            ));

            return true;
        } catch (Exception $e) {
            // No es crítico si falla la creación del Tax ID
            Tools::log('solwed')->warning('Error creating tax ID: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Determina el tipo de Tax ID según el formato y país
     *
     * @param string $taxId Tax ID a analizar
     * @param string $country Código de país
     * @return string|null Tipo de Tax ID para Stripe o null si no se puede determinar
     */
    private static function determineTaxIdType(string $taxId, string $country): ?string
    {
        $country = strtoupper($country);
        $taxId = strtoupper(preg_replace('/[\s\-]/', '', $taxId));

        // España
        if ($country === 'ES') {
            // CIF (empresas): empieza con letra, 7 dígitos, letra/dígito
            if (preg_match('/^[ABCDEFGHJKLMNPQRSUVW]\d{7}[0-9A-J]$/', $taxId)) {
                return 'es_cif';
            }
            // NIF (personas): 8 dígitos + letra
            if (preg_match('/^\d{8}[A-Z]$/', $taxId)) {
                return 'es_cif'; // Stripe usa es_cif para ambos
            }
            // NIE (extranjeros): X/Y/Z + 7 dígitos + letra
            if (preg_match('/^[XYZ]\d{7}[A-Z]$/', $taxId)) {
                return 'es_cif';
            }
        }

        // Países de la UE - VAT
        $euCountries = [
            'AT',
            'BE',
            'BG',
            'CY',
            'CZ',
            'DE',
            'DK',
            'EE',
            'EL',
            'FI',
            'FR',
            'HR',
            'HU',
            'IE',
            'IT',
            'LT',
            'LU',
            'LV',
            'MT',
            'NL',
            'PL',
            'PT',
            'RO',
            'SE',
            'SI',
            'SK'
        ];

        if (in_array($country, $euCountries)) {
            // VAT europeo: código país + número
            if (preg_match('/^[A-Z]{2}/', $taxId)) {
                return 'eu_vat';
            }
            // Si no tiene prefijo de país, añadirlo mentalmente para validar
            return 'eu_vat';
        }

        // Reino Unido
        if ($country === 'GB') {
            return 'gb_vat';
        }

        // Por defecto, intentar con eu_vat para países europeos
        return null;
    }

}
