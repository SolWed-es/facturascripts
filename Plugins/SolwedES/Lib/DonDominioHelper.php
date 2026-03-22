<?php

/**
 * Plugin SolwedES - Helper para interactuar con DonDominio API
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use Exception;
use FacturaScripts\Core\Tools;
use Dondominio\API\API as DonDominioAPI;

/**
 * Helper para centralizar interaccion con DonDominio API
 * Usa Tools::settings('dondominio', ...) como fuente de credenciales
 */
class DonDominioHelper
{
    /** @var DonDominioAPI|null */
    private static ?DonDominioAPI $apiInstance = null;

    /**
     * Obtiene un valor de configuracion de DonDominio
     *
     * @param string $key Clave de configuracion
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    public static function getSetting(string $key, $default = '')
    {
        return Tools::settings('dondominio', $key, $default);
    }

    /**
     * Guarda un valor de configuracion de DonDominio
     *
     * @param string $key Clave de configuracion
     * @param mixed $value Valor a guardar
     * @return void
     */
    public static function setSetting(string $key, $value): void
    {
        Tools::settingsSet('dondominio', $key, $value);
        Tools::settingsSave();
    }

    /**
     * Verifica si la configuracion de DonDominio esta completa
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $apiUser = self::getSetting('dondominio_api_user');
        $apiPass = self::getSetting('dondominio_api_pass');
        return !empty($apiUser) && !empty($apiPass);
    }

    /**
     * Inicializa la API de DonDominio
     *
     * @return DonDominioAPI|null API instance or null if not configured
     */
    public static function initAPI(): ?DonDominioAPI
    {
        if (self::$apiInstance !== null) {
            return self::$apiInstance;
        }

        if (!self::isConfigured()) {
            Tools::log('solwed')->error('DonDominio API credentials not configured');
            return null;
        }

        // Check if SDK is available
        if (!class_exists(DonDominioAPI::class)) {
            Tools::log('solwed')->error('DonDominio SDK not available. Run composer install in SolwedES plugin.');
            return null;
        }

        try {
            self::$apiInstance = new DonDominioAPI([
                'apiuser' => self::getSetting('dondominio_api_user'),
                'apipasswd' => self::getSetting('dondominio_api_pass'),
            ]);
            return self::$apiInstance;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error initializing DonDominio API: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verifica disponibilidad de un dominio
     *
     * @param string $domain Nombre completo del dominio (ej: example.com)
     * @return array ['available' => bool, 'price' => float|null, 'currency' => string|null, 'error' => string|null]
     */
    public static function checkAvailability(string $domain): array
    {
        $api = self::initAPI();
        if (!$api) {
            return [
                'available' => false,
                'price' => null,
                'currency' => null,
                'error' => 'DonDominio API not configured'
            ];
        }

        try {
            $response = $api->domain_check($domain);
            $data = $response->getResponseData();

            // Check for API error
            if (!$response->getSuccess()) {
                return [
                    'available' => false,
                    'price' => null,
                    'currency' => null,
                    'error' => $response->getErrorCodeMsg() ?? 'API Error: ' . $response->getErrorCode()
                ];
            }

            // SDK returns domain data directly in responseData
            $available = $data['available'] ?? false;
            $price = isset($data['price']) ? floatval($data['price']) : null;
            $currency = $data['currency'] ?? 'EUR';

            Tools::log('solwed')->info(sprintf(
                'Domain check: %s is %s (Price: %s %s)',
                $domain,
                $available ? 'available' : 'not available',
                $price ?? 'N/A',
                $currency
            ));

            return [
                'available' => (bool)$available,
                'price' => $price,
                'currency' => $currency,
                'error' => null
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error checking domain availability: ' . $e->getMessage());
            return [
                'available' => false,
                'price' => null,
                'currency' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Registra un nuevo dominio
     *
     * @param string $domain Nombre completo del dominio
     * @param array $contactData Datos del contacto propietario
     * @param int $years Anos de registro (1-10)
     * @param array $options Opciones adicionales (nameservers, whois_privacy, etc)
     * @return array ['success' => bool, 'domain_id' => int|null, 'expiration' => string|null, 'error' => string|null]
     */
    public static function registerDomain(string $domain, array $contactData, int $years = 1, array $options = []): array
    {
        $api = self::initAPI();
        if (!$api) {
            return [
                'success' => false,
                'domain_id' => null,
                'expiration' => null,
                'error' => 'DonDominio API not configured'
            ];
        }

        try {
            // Validate years
            $years = max(1, min(10, $years));

            // Format phone number to international format +DD.DDDDDDDD
            $phone = self::formatPhoneNumber($contactData['phone'] ?? '', $contactData['country'] ?? 'ES');

            // Build registration params with correct SDK field names
            $params = [
                'period' => $years,
                // Contact data - SDK field names
                'ownerContactType' => $contactData['type'] ?? 'individual',
                'ownerContactFirstName' => $contactData['firstName'] ?? '',
                'ownerContactLastName' => $contactData['lastName'] ?? '',
                'ownerContactIdentNumber' => $contactData['identNumber'] ?? '', // CIF/NIF
                'ownerContactEmail' => $contactData['email'] ?? '',
                'ownerContactPhone' => $phone,
                'ownerContactAddress' => $contactData['address'] ?? '',
                'ownerContactCity' => $contactData['city'] ?? '',
                'ownerContactPostalCode' => $contactData['postalCode'] ?? '',
                'ownerContactState' => $contactData['state'] ?? '',
                'ownerContactCountry' => $contactData['country'] ?? 'ES',
            ];

            // Add organization name only if type is organization
            if (($contactData['type'] ?? 'individual') === 'organization' && !empty($contactData['orgName'])) {
                $params['ownerContactOrgName'] = $contactData['orgName'];
            }

            // Add nameservers if provided (SDK accepts array format)
            $nameservers = [];
            if (!empty($options['nameservers'])) {
                $ns = is_array($options['nameservers']) ? $options['nameservers'] : explode(',', $options['nameservers']);
                $nameservers = array_map('trim', $ns);
            } else {
                // Use default nameservers from settings
                $defaultNs = self::getSetting('dondominio_default_nameservers', '');
                if (!empty($defaultNs)) {
                    $nameservers = array_map('trim', explode(',', $defaultNs));
                }
            }
            if (!empty($nameservers)) {
                $params['nameservers'] = $nameservers;
            }

            // WHOIS privacy
            if (!empty($options['whois_privacy'])) {
                $params['privacy'] = true;
            }

            Tools::log('solwed')->info('Registering domain: ' . $domain);

            $response = $api->domain_create($domain, $params);
            $data = $response->getResponseData();

            // Check for API error
            if (!$response->getSuccess()) {
                $error = $response->getErrorCodeMsg() ?? 'API Error: ' . $response->getErrorCode();
                Tools::log('solwed')->error('Domain registration failed: ' . $error);
                return [
                    'success' => false,
                    'domain_id' => null,
                    'expiration' => null,
                    'error' => $error
                ];
            }

            $domainId = $data['domainID'] ?? null;
            $expiration = $data['tsExpir'] ?? null;

            Tools::log('solwed')->notice(sprintf(
                'Domain registered successfully: %s (ID: %s, Expires: %s)',
                $domain,
                $domainId,
                $expiration
            ));

            return [
                'success' => true,
                'domain_id' => $domainId,
                'expiration' => $expiration,
                'error' => null
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Domain registration error: ' . $e->getMessage());
            return [
                'success' => false,
                'domain_id' => null,
                'expiration' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Renueva un dominio existente
     *
     * @param string $domain Nombre completo del dominio
     * @param int $years Anos de renovacion (1-10)
     * @param string|null $currentExpiration Fecha de expiracion actual (YYYY-MM-DD) - Required by SDK
     * @return array ['success' => bool, 'new_expiration' => string|null, 'error' => string|null]
     */
    public static function renewDomain(string $domain, int $years = 1, ?string $currentExpiration = null): array
    {
        $api = self::initAPI();
        if (!$api) {
            return [
                'success' => false,
                'new_expiration' => null,
                'error' => 'DonDominio API not configured'
            ];
        }

        try {
            $years = max(1, min(10, $years));

            // If no current expiration provided, fetch it from API
            if (empty($currentExpiration)) {
                $domainInfo = self::getDomainInfo($domain);
                if (!$domainInfo['success']) {
                    return [
                        'success' => false,
                        'new_expiration' => null,
                        'error' => 'Could not get current expiration date: ' . ($domainInfo['error'] ?? 'Unknown error')
                    ];
                }
                $currentExpiration = $domainInfo['data']['expiration'] ?? null;
                if (empty($currentExpiration)) {
                    return [
                        'success' => false,
                        'new_expiration' => null,
                        'error' => 'Current expiration date not available'
                    ];
                }
            }

            // Format expiration date to YYYY-MM-DD if it's a timestamp
            if (strtotime($currentExpiration) !== false) {
                $currentExpiration = date('Y-m-d', strtotime($currentExpiration));
            }

            Tools::log('solwed')->info(sprintf('Renewing domain: %s for %d years (current exp: %s)', $domain, $years, $currentExpiration));

            $response = $api->domain_renew($domain, [
                'curExpDate' => $currentExpiration,  // Required by SDK
                'period' => $years
            ]);
            $data = $response->getResponseData();

            // Check for API error
            if (!$response->getSuccess()) {
                $error = $response->getErrorCodeMsg() ?? 'API Error: ' . $response->getErrorCode();
                Tools::log('solwed')->error('Domain renewal failed: ' . $error);
                return [
                    'success' => false,
                    'new_expiration' => null,
                    'error' => $error
                ];
            }

            $newExpiration = $data['tsExpir'] ?? null;

            Tools::log('solwed')->notice(sprintf(
                'Domain renewed successfully: %s (New expiration: %s)',
                $domain,
                $newExpiration
            ));

            return [
                'success' => true,
                'new_expiration' => $newExpiration,
                'error' => null
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Domain renewal error: ' . $e->getMessage());
            return [
                'success' => false,
                'new_expiration' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene informacion detallada de un dominio
     *
     * @param string $domain Nombre completo del dominio
     * @param string $infoType Tipos de informacion a obtener (status, contact, nameservers, authcode, service, gluerecords, dnssec)
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public static function getDomainInfo(string $domain, string $infoType = 'status'): array
    {
        $api = self::initAPI();
        if (!$api) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'DonDominio API not configured'
            ];
        }

        try {
            $response = $api->domain_getInfo($domain, [
                'infoType' => $infoType
            ]);
            $data = $response->getResponseData();

            // Check for API error
            if (!$response->getSuccess()) {
                return [
                    'success' => false,
                    'data' => null,
                    'error' => $response->getErrorCodeMsg() ?? 'API Error: ' . $response->getErrorCode()
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'domain_id' => $data['domainID'] ?? null,
                    'name' => $data['name'] ?? null,
                    'status' => $data['status'] ?? null,
                    'expiration' => $data['tsExpir'] ?? null,
                    'creation' => $data['tsCreate'] ?? null,
                    'nameservers' => $data['nameservers'] ?? null,
                    'contacts' => $data['contacts'] ?? null,
                    'authcode' => $data['authcode'] ?? null,
                ],
                'error' => null
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error getting domain info: ' . $e->getMessage());
            return [
                'success' => false,
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Lista todos los dominios de la cuenta
     *
     * @param array $filters Filtros opcionales (pageLength, page, domain, word, tld, renewable, infoType, owner, tag, status, renewalMode)
     * @return array ['success' => bool, 'domains' => array, 'total' => int, 'error' => string|null]
     */
    public static function listDomains(array $filters = []): array
    {
        $api = self::initAPI();
        if (!$api) {
            return [
                'success' => false,
                'domains' => [],
                'total' => 0,
                'error' => 'DonDominio API not configured'
            ];
        }

        try {
            // Default params with pagination
            $params = array_merge([
                'pageLength' => 999,
                'page' => 1,
                'infoType' => 'status'
            ], $filters);

            // Use correct SDK method: domain_getList (not domain_list)
            $response = $api->domain_getList($params);
            $data = $response->getResponseData();

            // Check for API error
            if (!$response->getSuccess()) {
                return [
                    'success' => false,
                    'domains' => [],
                    'total' => 0,
                    'error' => $response->getErrorCodeMsg() ?? 'API Error: ' . $response->getErrorCode()
                ];
            }

            return [
                'success' => true,
                'domains' => $data['domains'] ?? [],
                'total' => $data['queryInfo']['total'] ?? count($data['domains'] ?? []),
                'error' => null
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error listing domains: ' . $e->getMessage());
            return [
                'success' => false,
                'domains' => [],
                'total' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene el saldo disponible en la cuenta de DonDominio
     *
     * @return array ['success' => bool, 'balance' => float|null, 'currency' => string|null, 'threshold' => float|null, 'error' => string|null]
     */
    public static function getAccountBalance(): array
    {
        $api = self::initAPI();
        if (!$api) {
            return [
                'success' => false,
                'balance' => null,
                'currency' => null,
                'threshold' => null,
                'error' => 'DonDominio API not configured'
            ];
        }

        try {
            $response = $api->account_info();
            $data = $response->getResponseData();

            // Check for API error
            if (!$response->getSuccess()) {
                return [
                    'success' => false,
                    'balance' => null,
                    'currency' => null,
                    'threshold' => null,
                    'error' => $response->getErrorCodeMsg() ?? 'API Error: ' . $response->getErrorCode()
                ];
            }

            // SDK returns: clientName, apiuser, balance, threshold, currency, ip
            $balance = $data['balance'] ?? null;

            if ($balance !== null) {
                return [
                    'success' => true,
                    'balance' => floatval($balance),
                    'currency' => $data['currency'] ?? 'EUR',
                    'threshold' => isset($data['threshold']) ? floatval($data['threshold']) : null,
                    'error' => null
                ];
            }

            return [
                'success' => false,
                'balance' => null,
                'currency' => null,
                'threshold' => null,
                'error' => 'No balance returned from API'
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error getting account balance: ' . $e->getMessage());
            return [
                'success' => false,
                'balance' => null,
                'currency' => null,
                'threshold' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene el precio de registro/renovacion de un TLD
     *
     * @param string $tld TLD sin punto (ej: 'com', 'es')
     * @return array ['success' => bool, 'prices' => array|null, 'error' => string|null]
     */
    public static function getTldPricing(string $tld): array
    {
        $api = self::initAPI();
        if (!$api) {
            return [
                'success' => false,
                'prices' => null,
                'error' => 'DonDominio API not configured'
            ];
        }

        try {
            // Remove leading dot if present
            $tld = ltrim($tld, '.');

            // Use account_zones with tld filter (tool_tldGetInfo doesn't exist)
            $response = $api->account_zones([
                'tld' => $tld
            ]);
            $data = $response->getResponseData();

            // Check for API error
            if (!$response->getSuccess()) {
                return [
                    'success' => false,
                    'prices' => null,
                    'error' => $response->getErrorCodeMsg() ?? 'API Error: ' . $response->getErrorCode()
                ];
            }

            // zones array contains pricing for create/renew/transfer
            $zones = $data['zones'] ?? [];
            if (empty($zones)) {
                return [
                    'success' => false,
                    'prices' => null,
                    'error' => 'TLD not found: ' . $tld
                ];
            }

            // Find the matching TLD in zones array
            $tldData = null;
            foreach ($zones as $zone) {
                if (strcasecmp($zone['tld'] ?? '', $tld) === 0) {
                    $tldData = $zone;
                    break;
                }
            }

            if (!$tldData) {
                $tldData = $zones[0]; // Use first result if exact match not found
            }

            return [
                'success' => true,
                'prices' => [
                    'register' => $tldData['create'] ?? $tldData['register_price'] ?? null,
                    'renew' => $tldData['renew'] ?? $tldData['renew_price'] ?? null,
                    'transfer' => $tldData['transfer'] ?? $tldData['transfer_price'] ?? null,
                    'currency' => $data['currency'] ?? 'EUR',
                    'tld' => $tldData['tld'] ?? $tld,
                ],
                'error' => null
            ];
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error getting TLD pricing: ' . $e->getMessage());
            return [
                'success' => false,
                'prices' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Convierte datos de contacto de FacturaScripts a formato DonDominio
     *
     * @param \FacturaScripts\Dinamic\Model\Contacto $contacto
     * @return array Datos formateados para DonDominio SDK
     */
    public static function contactoToDD($contacto): array
    {
        // Determine contact type
        $isCompany = !empty($contacto->empresa);
        $type = $isCompany ? 'organization' : 'individual';

        // Parse name - SDK requires firstName and lastName
        $fullName = trim($contacto->nombre ?? '');
        $nameParts = explode(' ', $fullName, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? $firstName; // SDK requires lastName, use firstName if not available

        // Get country code (default to ES)
        $country = $contacto->codpais ?? 'ES';

        // Format phone number to SDK required format: +DD.DDDDDDDD
        $phone = self::formatPhoneNumber(
            $contacto->telefono1 ?? $contacto->telefono2 ?? '',
            $country
        );

        // Parse address
        $address = trim($contacto->direccion ?? '');

        // Build contact data - SDK field names (no orgType field exists in SDK)
        $data = [
            'type' => $type,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'identNumber' => $contacto->cifnif ?? '',
            'email' => $contacto->email ?? '',
            'phone' => $phone,
            'address' => $address,
            'city' => $contacto->ciudad ?? '',
            'postalCode' => $contacto->codpostal ?? '',
            'state' => $contacto->provincia ?? '',
            'country' => $country,
        ];

        // Add organization name only if type is organization
        if ($isCompany) {
            $data['orgName'] = $contacto->empresa;
        }

        return $data;
    }

    /**
     * Formatea un numero de telefono al formato internacional requerido por DonDominio SDK
     * Formato: +DD.DDDDDDDD (ej: +34.123456789)
     *
     * @param string $phone Numero de telefono original
     * @param string $countryCode Codigo de pais (ES, FR, etc)
     * @return string Telefono formateado
     */
    public static function formatPhoneNumber(string $phone, string $countryCode = 'ES'): string
    {
        // Remove all non-numeric characters except +
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        if (empty($phone)) {
            return '';
        }

        // Country calling codes map
        $countryCodes = [
            'ES' => '34',
            'FR' => '33',
            'DE' => '49',
            'IT' => '39',
            'PT' => '351',
            'GB' => '44',
            'UK' => '44',
            'US' => '1',
            'MX' => '52',
            'AR' => '54',
            'CL' => '56',
            'CO' => '57',
            'PE' => '51',
            'BR' => '55',
            'NL' => '31',
            'BE' => '32',
            'AT' => '43',
            'CH' => '41',
        ];

        // Get calling code for country (default to Spain)
        $callingCode = $countryCodes[strtoupper($countryCode)] ?? '34';

        // If phone already starts with +, parse and reformat
        if (strpos($phone, '+') === 0) {
            $phone = substr($phone, 1);
            // Check if it already has the country code
            if (strpos($phone, $callingCode) === 0) {
                $phone = substr($phone, strlen($callingCode));
            }
        }

        // Remove leading zeros (common in local formats)
        $phone = ltrim($phone, '0');

        // Format: +DD.DDDDDDDD
        return '+' . $callingCode . '.' . $phone;
    }

    /**
     * Mapea estado de DonDominio a estado de FacturaScripts
     *
     * @param string $ddStatus Estado de DonDominio
     * @return string Estado para FacturaScripts
     */
    public static function mapStatus(string $ddStatus): string
    {
        $map = [
            // Active states
            'active' => 'active',
            'renewed' => 'active',

            // Registration states
            'register-init' => 'inactive',
            'register-pending' => 'inactive',
            'register-cancel' => 'inactive',

            // Transfer states
            'transfer-init' => 'pending_transfer',
            'transfer-pending' => 'pending_transfer',
            'transfer-cancel' => 'inactive',

            // Expired states
            'expired-renewgrace' => 'expired',
            'expired-redemption' => 'redemption',
            'expired-pendingdelete' => 'expired',

            // Inactive
            'inactive' => 'inactive',
        ];

        return $map[$ddStatus] ?? 'inactive';
    }
}
