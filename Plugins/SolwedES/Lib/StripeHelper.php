<?php

/**
 * Plugin SolwedES - Helper para interactuar con Stripe API via Bridge
 *
 * All Stripe API calls go through solwed-bridge.
 * Webhook signature verification uses native PHP hash_hmac.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use Exception;
use FacturaScripts\Core\Tools;

class StripeHelper
{
    public static function getSetting(string $key, $default = '')
    {
        return Tools::settings('stripe', $key, $default);
    }

    public static function isConfigured(): bool
    {
        return !empty(Tools::settings('solwed', 'bridge_url'));
    }

    public static function isTestMode(): bool
    {
        $secretKey = self::getSetting('stripe_secret_key', '');
        return strpos($secretKey, 'test') !== false;
    }

    /**
     * Obtiene detalles del método de pago desde un PaymentIntent
     */
    public static function getPaymentMethodDetails(string $paymentIntentId): array
    {
        $result = BridgeClient::get('/stripe/payment-intents/' . $paymentIntentId);
        if (!($result['ok'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'Bridge error'];
        }

        $pi = $result['data'] ?? [];
        $pm = $pi['payment_method'] ?? null;

        $response = [
            'success' => true,
            'type' => $pm['type'] ?? 'unknown',
            'fecha' => isset($pi['created']) ? date('Y-m-d H:i:s', $pi['created']) : date('Y-m-d H:i:s'),
            'amount' => ($pi['amount'] ?? 0) / 100,
            'currency' => strtoupper($pi['currency'] ?? 'eur'),
        ];

        if (!$pm) {
            $response['brand'] = 'Desconocido';
            $response['last4'] = 'N/A';
            return $response;
        }

        if (($pm['type'] ?? '') === 'card' && !empty($pm['card'])) {
            $response['brand'] = ucfirst($pm['card']['brand'] ?? 'unknown');
            $response['last4'] = $pm['card']['last4'] ?? 'N/A';
            $response['exp_month'] = $pm['card']['exp_month'] ?? null;
            $response['exp_year'] = $pm['card']['exp_year'] ?? null;
            $response['country'] = $pm['card']['country'] ?? null;
        } elseif (($pm['type'] ?? '') === 'sepa_debit' && !empty($pm['sepa_debit'])) {
            $response['brand'] = 'SEPA';
            $response['last4'] = $pm['sepa_debit']['last4'] ?? 'N/A';
            $response['country'] = $pm['sepa_debit']['country'] ?? null;
        } else {
            $response['brand'] = ucfirst($pm['type'] ?? 'unknown');
            $response['last4'] = 'N/A';
        }

        return $response;
    }

    /**
     * Verifica la firma del webhook de Stripe usando hash_hmac nativo
     * No necesita el SDK de Stripe.
     *
     * @throws Exception Si la firma no es válida
     */
    public static function verifyWebhookSignature(string $payload, string $signatureHeader): object
    {
        $webhookSecret = self::getSetting('stripe_webhook_secret');
        if (empty($webhookSecret)) {
            throw new Exception('Webhook secret not configured');
        }

        // Parse the Stripe-Signature header
        $parts = [];
        foreach (explode(',', $signatureHeader) as $item) {
            $kv = explode('=', trim($item), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (empty($timestamp) || empty($signature)) {
            throw new Exception('Invalid signature header format');
        }

        // Tolerance: 5 minutes
        if (abs(time() - (int)$timestamp) > 300) {
            throw new Exception('Webhook timestamp too old');
        }

        // Compute expected signature
        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);

        if (!hash_equals($expected, $signature)) {
            throw new Exception('Invalid webhook signature');
        }

        // Return parsed event object
        $event = json_decode($payload);
        if (!$event || empty($event->type)) {
            throw new Exception('Invalid event payload');
        }

        Tools::log('solwed')->info('Webhook signature verified successfully');
        return $event;
    }

    /**
     * Obtiene el Tax ID (CIF/NIF) del cliente de Stripe
     */
    public static function getCustomerTaxId(string $customerId): ?string
    {
        if (empty($customerId)) {
            return null;
        }

        $result = BridgeClient::get("/stripe/customers/{$customerId}/tax-ids");
        if (!($result['ok'] ?? false)) {
            return null;
        }

        $taxIds = $result['data'] ?? [];
        return !empty($taxIds[0]['value']) ? $taxIds[0]['value'] : null;
    }

    /**
     * Busca o crea un cliente en Stripe
     */
    public static function findOrCreateCustomer(string $email, string $name = '', array $metadata = []): ?array
    {
        // Search by email
        $result = BridgeClient::post('/stripe/customers/search', ['email' => $email]);
        if (($result['ok'] ?? false) && !empty($result['data'])) {
            return $result['data'];
        }

        // Create new customer
        $result = BridgeClient::post('/stripe/customers', [
            'name' => $name ?: $email,
            'email' => $email,
            'metadata' => $metadata,
        ]);

        if (!($result['ok'] ?? false)) {
            Tools::log('solwed')->error('Error with Stripe customer: ' . ($result['error'] ?? 'unknown'));
            return null;
        }

        return $result['data'] ?? null;
    }

    /**
     * Crea un Tax ID para un cliente de Stripe
     */
    public static function createCustomerTaxId(string $customerId, string $taxId, string $country = 'ES'): bool
    {
        if (empty($customerId) || empty($taxId)) {
            return false;
        }

        $taxIdType = self::determineTaxIdType($taxId, $country);
        if (empty($taxIdType)) {
            return false;
        }

        $cleanTaxId = preg_replace('/[\s\-]/', '', $taxId);
        $result = BridgeClient::post("/stripe/customers/{$customerId}/tax-ids", [
            'type' => $taxIdType,
            'value' => $cleanTaxId,
        ]);

        return ($result['ok'] ?? false);
    }

    private static function determineTaxIdType(string $taxId, string $country): ?string
    {
        $country = strtoupper($country);
        $taxId = strtoupper(preg_replace('/[\s\-]/', '', $taxId));

        if ($country === 'ES') {
            if (preg_match('/^[ABCDEFGHJKLMNPQRSUVW]\d{7}[0-9A-J]$/', $taxId) ||
                preg_match('/^\d{8}[A-Z]$/', $taxId) ||
                preg_match('/^[XYZ]\d{7}[A-Z]$/', $taxId)) {
                return 'es_cif';
            }
        }

        $euCountries = ['AT','BE','BG','CY','CZ','DE','DK','EE','EL','FI','FR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'];
        if (in_array($country, $euCountries)) {
            return 'eu_vat';
        }

        if ($country === 'GB') {
            return 'gb_vat';
        }

        return null;
    }
}
