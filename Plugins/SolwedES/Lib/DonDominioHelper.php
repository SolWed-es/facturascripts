<?php

/**
 * Plugin SolwedES - Helper para interactuar con DonDominio API
 *
 * Reads cached data from Redis (populated by solwed-bridge sync workers).
 * Write operations go through BridgeClient → solwed-bridge → DonDominio API.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Core\Tools;

class DonDominioHelper
{
    // ─── Settings ────────────────────────────────────────────

    public static function getSetting(string $key, $default = '')
    {
        return Tools::settings('dondominio', $key, $default);
    }

    public static function setSetting(string $key, $value): void
    {
        Tools::settingsSet('dondominio', $key, $value);
        Tools::settingsSave();
    }

    public static function isConfigured(): bool
    {
        // Bridge handles credentials — we just check bridge connectivity
        return !empty(Tools::settings('solwed', 'bridge_url'));
    }

    // ─── READ operations (Redis cache) ───────────────────────

    /**
     * Lista todos los dominios (from Redis cache, synced every 30 min)
     */
    public static function listDomains(array $filters = []): array
    {
        $domains = RedisReader::getList('dd:domains');

        if (empty($domains)) {
            // Fallback: fetch from bridge if Redis is empty
            $result = BridgeClient::post('/domains', ['action' => 'list']);
            return [
                'success' => $result['ok'] ?? false,
                'domains' => $result['data']['domains'] ?? $result['data'] ?? [],
                'total' => count($result['data']['domains'] ?? $result['data'] ?? []),
                'error' => $result['error'] ?? null,
            ];
        }

        return [
            'success' => true,
            'domains' => $domains,
            'total' => count($domains),
            'error' => null,
        ];
    }

    /**
     * Obtiene informacion detallada de un dominio (from Redis cache)
     */
    public static function getDomainInfo(string $domain, string $infoType = 'status'): array
    {
        $cached = RedisReader::get("dd:domain:{$domain}");

        if ($cached !== null) {
            return [
                'success' => true,
                'data' => [
                    'domain_id' => $cached['domainID'] ?? $cached['domain_id'] ?? null,
                    'name' => $cached['name'] ?? $domain,
                    'status' => $cached['status'] ?? null,
                    'expiration' => $cached['tsExpir'] ?? $cached['expiration'] ?? null,
                    'creation' => $cached['tsCreate'] ?? $cached['creation'] ?? null,
                    'nameservers' => $cached['nameservers'] ?? null,
                    'contacts' => $cached['contacts'] ?? null,
                    'authcode' => $cached['authcode'] ?? null,
                ],
                'error' => null,
            ];
        }

        // Fallback: fetch from bridge
        $result = BridgeClient::post('/domains', ['action' => 'info', 'domain' => $domain]);
        if (!($result['ok'] ?? false)) {
            return ['success' => false, 'data' => null, 'error' => $result['error'] ?? 'Bridge error'];
        }

        $data = $result['data'] ?? [];
        return [
            'success' => true,
            'data' => [
                'domain_id' => $data['domainID'] ?? $data['domain_id'] ?? null,
                'name' => $data['name'] ?? $domain,
                'status' => $data['status'] ?? null,
                'expiration' => $data['tsExpir'] ?? $data['expiration'] ?? null,
                'creation' => $data['tsCreate'] ?? $data['creation'] ?? null,
                'nameservers' => $data['nameservers'] ?? null,
                'contacts' => $data['contacts'] ?? null,
                'authcode' => $data['authcode'] ?? null,
            ],
            'error' => null,
        ];
    }

    // ─── REAL-TIME READ operations (always via bridge) ───────

    /**
     * Verifica disponibilidad de un dominio (real-time, not cached)
     */
    public static function checkAvailability(string $domain): array
    {
        $result = BridgeClient::post('/domains', ['action' => 'check', 'domain' => $domain]);
        if (!($result['ok'] ?? false)) {
            return [
                'available' => false,
                'price' => null,
                'currency' => null,
                'error' => $result['error'] ?? 'Bridge error',
            ];
        }

        $data = $result['data'] ?? [];
        return [
            'available' => (bool)($data['available'] ?? false),
            'price' => isset($data['price']) ? floatval($data['price']) : null,
            'currency' => $data['currency'] ?? 'EUR',
            'error' => null,
        ];
    }

    /**
     * Obtiene el saldo disponible en la cuenta de DonDominio
     */
    public static function getAccountBalance(): array
    {
        // This is not available in bridge yet — pass through as generic proxy
        $result = BridgeClient::post('/domains', ['action' => 'balance']);
        if (!($result['ok'] ?? false)) {
            return [
                'success' => false,
                'balance' => null,
                'currency' => null,
                'threshold' => null,
                'error' => $result['error'] ?? 'Bridge error',
            ];
        }

        $data = $result['data'] ?? [];
        return [
            'success' => true,
            'balance' => isset($data['balance']) ? floatval($data['balance']) : null,
            'currency' => $data['currency'] ?? 'EUR',
            'threshold' => isset($data['threshold']) ? floatval($data['threshold']) : null,
            'error' => null,
        ];
    }

    /**
     * Obtiene el precio de registro/renovacion de un TLD
     */
    public static function getTldPricing(string $tld): array
    {
        $result = BridgeClient::post('/domains', ['action' => 'pricing', 'tld' => ltrim($tld, '.')]);
        if (!($result['ok'] ?? false)) {
            return ['success' => false, 'prices' => null, 'error' => $result['error'] ?? 'Bridge error'];
        }
        return ['success' => true, 'prices' => $result['data'] ?? null, 'error' => null];
    }

    // ─── WRITE operations (always via bridge) ────────────────

    /**
     * Registra un nuevo dominio
     */
    public static function registerDomain(string $domain, array $contactData, int $years = 1, array $options = []): array
    {
        $phone = self::formatPhoneNumber($contactData['phone'] ?? '', $contactData['country'] ?? 'ES');

        $params = [
            'domain' => $domain,
            'options' => array_merge([
                'period' => max(1, min(10, $years)),
                'ownerContactType' => $contactData['type'] ?? 'individual',
                'ownerContactFirstName' => $contactData['firstName'] ?? '',
                'ownerContactLastName' => $contactData['lastName'] ?? '',
                'ownerContactIdentNumber' => $contactData['identNumber'] ?? '',
                'ownerContactEmail' => $contactData['email'] ?? '',
                'ownerContactPhone' => $phone,
                'ownerContactAddress' => $contactData['address'] ?? '',
                'ownerContactCity' => $contactData['city'] ?? '',
                'ownerContactPostalCode' => $contactData['postalCode'] ?? '',
                'ownerContactState' => $contactData['state'] ?? '',
                'ownerContactCountry' => $contactData['country'] ?? 'ES',
            ], $options),
        ];

        if (($contactData['type'] ?? 'individual') === 'organization' && !empty($contactData['orgName'])) {
            $params['options']['ownerContactOrgName'] = $contactData['orgName'];
        }

        $result = BridgeClient::post('/domains/register', $params);

        if (!($result['ok'] ?? false)) {
            Tools::log('solwed')->error('Domain registration failed: ' . ($result['error'] ?? 'Unknown'));
            return ['success' => false, 'domain_id' => null, 'expiration' => null, 'error' => $result['error'] ?? 'Bridge error'];
        }

        $data = $result['data'] ?? [];
        Tools::log('solwed')->notice("Domain registered: {$domain}");
        return [
            'success' => true,
            'domain_id' => $data['domainID'] ?? null,
            'expiration' => $data['tsExpir'] ?? null,
            'error' => null,
        ];
    }

    /**
     * Renueva un dominio existente
     */
    public static function renewDomain(string $domain, int $years = 1, ?string $currentExpiration = null): array
    {
        if (empty($currentExpiration)) {
            $domainInfo = self::getDomainInfo($domain);
            if (!$domainInfo['success']) {
                return ['success' => false, 'new_expiration' => null, 'error' => 'Could not get expiration: ' . ($domainInfo['error'] ?? '')];
            }
            $currentExpiration = $domainInfo['data']['expiration'] ?? null;
            if (empty($currentExpiration)) {
                return ['success' => false, 'new_expiration' => null, 'error' => 'Current expiration date not available'];
            }
        }

        if (strtotime($currentExpiration) !== false) {
            $currentExpiration = date('Y-m-d', strtotime($currentExpiration));
        }

        $result = BridgeClient::post('/domains/renew', [
            'domain' => $domain,
            'period' => max(1, min(10, $years)),
            'curExpDate' => $currentExpiration,
        ]);

        if (!($result['ok'] ?? false)) {
            Tools::log('solwed')->error('Domain renewal failed: ' . ($result['error'] ?? 'Unknown'));
            return ['success' => false, 'new_expiration' => null, 'error' => $result['error'] ?? 'Bridge error'];
        }

        Tools::log('solwed')->notice("Domain renewed: {$domain}");
        return [
            'success' => true,
            'new_expiration' => $result['data']['tsExpir'] ?? null,
            'error' => null,
        ];
    }

    // ─── Data conversion utilities (no API calls) ────────────

    /**
     * Convierte datos de contacto de FacturaScripts a formato DonDominio
     */
    public static function contactoToDD($contacto): array
    {
        $isCompany = !empty($contacto->empresa);
        $type = $isCompany ? 'organization' : 'individual';
        $fullName = trim($contacto->nombre ?? '');
        $nameParts = explode(' ', $fullName, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? $firstName;
        $country = $contacto->codpais ?? 'ES';
        $phone = self::formatPhoneNumber($contacto->telefono1 ?? $contacto->telefono2 ?? '', $country);

        $data = [
            'type' => $type,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'identNumber' => $contacto->cifnif ?? '',
            'email' => $contacto->email ?? '',
            'phone' => $phone,
            'address' => trim($contacto->direccion ?? ''),
            'city' => $contacto->ciudad ?? '',
            'postalCode' => $contacto->codpostal ?? '',
            'state' => $contacto->provincia ?? '',
            'country' => $country,
        ];

        if ($isCompany) {
            $data['orgName'] = $contacto->empresa;
        }

        return $data;
    }

    public static function formatPhoneNumber(string $phone, string $countryCode = 'ES'): string
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (empty($phone)) {
            return '';
        }

        $countryCodes = [
            'ES' => '34', 'FR' => '33', 'DE' => '49', 'IT' => '39', 'PT' => '351',
            'GB' => '44', 'UK' => '44', 'US' => '1', 'MX' => '52', 'AR' => '54',
            'CL' => '56', 'CO' => '57', 'PE' => '51', 'BR' => '55', 'NL' => '31',
            'BE' => '32', 'AT' => '43', 'CH' => '41',
        ];

        $callingCode = $countryCodes[strtoupper($countryCode)] ?? '34';

        if (strpos($phone, '+') === 0) {
            $phone = substr($phone, 1);
            if (strpos($phone, $callingCode) === 0) {
                $phone = substr($phone, strlen($callingCode));
            }
        }

        $phone = ltrim($phone, '0');
        return '+' . $callingCode . '.' . $phone;
    }

    public static function mapStatus(string $ddStatus): string
    {
        $map = [
            'active' => 'active', 'renewed' => 'active',
            'register-init' => 'inactive', 'register-pending' => 'inactive', 'register-cancel' => 'inactive',
            'transfer-init' => 'pending_transfer', 'transfer-pending' => 'pending_transfer', 'transfer-cancel' => 'inactive',
            'expired-renewgrace' => 'expired', 'expired-redemption' => 'redemption', 'expired-pendingdelete' => 'expired',
            'inactive' => 'inactive',
        ];
        return $map[$ddStatus] ?? 'inactive';
    }
}
