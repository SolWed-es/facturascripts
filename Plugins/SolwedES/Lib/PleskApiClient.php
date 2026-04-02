<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Cliente para interactuar con la API de Plesk
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Plugins\SolwedES\Model\PleskConfig;
use FacturaScripts\Plugins\SolwedES\Lib\SolwedLogger;

/**
 * Cliente para interactuar con la API de Plesk (REST y XML-RPC)
 */
class PleskApiClient
{
    /** @var PleskConfig */
    private $config;

    /** @var int Timeout para requests en segundos */
    private const TIMEOUT = 30;

    public function __construct(PleskConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Prueba la conexión al servidor Plesk
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        SolwedLogger::stripe('DEBUG [PLESK-TEST-1]: testConnection called');
        SolwedLogger::stripe('DEBUG [PLESK-TEST-1a]: Server URL: ' . $this->config->server_url);
        SolwedLogger::stripe('DEBUG [PLESK-TEST-1b]: API Type: ' . $this->config->api_type);

        try {
            if ($this->config->api_type === 'rest') {
                SolwedLogger::stripe('DEBUG [PLESK-TEST-2]: Testing REST connection');
                $result = $this->testRESTConnection();
            } else {
                SolwedLogger::stripe('DEBUG [PLESK-TEST-2]: Testing XML-RPC connection');
                $result = $this->testXMLRPCConnection();
            }
            SolwedLogger::stripe('DEBUG [PLESK-TEST-3]: Connection test result: ' . ($result ? 'SUCCESS' : 'FAILED'));
            return $result;
        } catch (\Throwable $e) {
            SolwedLogger::stripe('DEBUG [PLESK-TEST-ERROR]: Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene lista de dominios del servidor
     *
     * @return array
     */
    public function getDomains(): array
    {
        try {
            if ($this->config->api_type === 'rest') {
                return $this->getDomainsREST();
            } else {
                return $this->getDomainsXMLRPC();
            }
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Obtiene aplicaciones instaladas en un dominio
     *
     * @param string $domain
     * @return array
     */
    public function getApplications(string $domain): array
    {
        try {
            if ($this->config->api_type === 'rest') {
                return $this->getApplicationsREST($domain);
            } else {
                return $this->getApplicationsXMLRPC($domain);
            }
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Obtiene cuentas de correo de un dominio
     *
     * @param string $domain
     * @return array
     */
    public function getEmailAccounts(string $domain): array
    {
        try {
            if ($this->config->api_type === 'rest') {
                return $this->getEmailAccountsREST($domain);
            } else {
                return $this->getEmailAccountsXMLRPC($domain);
            }
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Obtiene instalaciones de FacturaScripts en el servidor
     *
     * @return array
     */
    public function getFacturaScriptsInstances(): array
    {
        try {
            $domains = $this->getDomains();
            $instances = [];

            foreach ($domains as $domain) {
                // Buscar directorios que parezcan FacturaScripts
                // Esto es una aproximación simple, se podría mejorar
                $apps = $this->getApplications($domain['name']);

                foreach ($apps as $app) {
                    // Detectar si es FacturaScripts por path o nombre
                    if (stripos($app['path'], 'facturascripts') !== false ||
                        stripos($app['name'], 'facturascripts') !== false ||
                        stripos($app['type'], 'facturascripts') !== false) {
                        $instances[] = [
                            'domain' => $domain['name'],
                            'path' => $app['path'] ?? '',
                            'version' => $app['version'] ?? 'Unknown',
                            'name' => $app['name'] ?? 'FacturaScripts'
                        ];
                    }
                }
            }

            return $instances;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ==================== WRITE OPERATIONS ====================

    /**
     * Creates a new webspace (hosting subscription) for a domain
     *
     * @param string $domain Domain name (e.g., example.com)
     * @param array $params Optional parameters:
     *   - plan: Plesk service plan name (default: 'Default Domain')
     *   - ip: IP address to use
     *   - ftp_user: FTP username (auto-generated if not provided)
     *   - ftp_password: FTP password (auto-generated if not provided)
     * @return array ['success' => bool, 'webspace_id' => string|null, 'ftp_user' => string|null, 'error' => string|null]
     */
    public function createWebspace(string $domain, array $params = []): array
    {
        SolwedLogger::stripe('DEBUG [PLESK-WS-1]: createWebspace called');
        SolwedLogger::stripe("DEBUG [PLESK-WS-1a]: Domain: {$domain}");
        SolwedLogger::stripe('DEBUG [PLESK-WS-1b]: Params: ' . json_encode($params));
        SolwedLogger::stripe('DEBUG [PLESK-WS-1c]: API Type: ' . $this->config->api_type);

        try {
            if ($this->config->api_type === 'rest') {
                SolwedLogger::stripe('DEBUG [PLESK-WS-2]: Using REST API');
                $result = $this->createWebspaceREST($domain, $params);
            } else {
                SolwedLogger::stripe('DEBUG [PLESK-WS-2]: Using XML-RPC API');
                $result = $this->createWebspaceXMLRPC($domain, $params);
            }
            SolwedLogger::stripe('DEBUG [PLESK-WS-3]: Result: ' . json_encode($result));
            return $result;
        } catch (\Throwable $e) {
            SolwedLogger::stripe('DEBUG [PLESK-WS-ERROR]: Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'webspace_id' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Installs WordPress on a domain
     *
     * @param string $domain Domain where to install WordPress
     * @param array $config Configuration:
     *   - admin_user: WordPress admin username (required)
     *   - admin_password: WordPress admin password (required)
     *   - admin_email: WordPress admin email (required)
     *   - site_title: Site title (default: domain name)
     *   - path: Installation path (default: '' for root)
     * @return array ['success' => bool, 'admin_url' => string|null, 'error' => string|null]
     */
    public function installWordPress(string $domain, array $config): array
    {
        SolwedLogger::stripe('DEBUG [PLESK-WP-1]: installWordPress called');
        SolwedLogger::stripe("DEBUG [PLESK-WP-1a]: Domain: {$domain}");
        // Don't log passwords
        $safeConfig = $config;
        if (isset($safeConfig['admin_password'])) {
            $safeConfig['admin_password'] = '***HIDDEN***';
        }
        SolwedLogger::stripe('DEBUG [PLESK-WP-1b]: Config: ' . json_encode($safeConfig));
        SolwedLogger::stripe('DEBUG [PLESK-WP-1c]: API Type: ' . $this->config->api_type);

        try {
            if ($this->config->api_type === 'rest') {
                SolwedLogger::stripe('DEBUG [PLESK-WP-2]: Using REST API');
                $result = $this->installWordPressREST($domain, $config);
            } else {
                SolwedLogger::stripe('DEBUG [PLESK-WP-2]: Using XML-RPC API');
                $result = $this->installWordPressXMLRPC($domain, $config);
            }
            SolwedLogger::stripe('DEBUG [PLESK-WP-3]: Result: ' . json_encode($result));
            return $result;
        } catch (\Throwable $e) {
            SolwedLogger::stripe('DEBUG [PLESK-WP-ERROR]: Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'admin_url' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Creates a database for the domain
     *
     * @param string $domain Domain name
     * @param string $dbName Database name
     * @param string $dbUser Database user
     * @param string $dbPass Database password
     * @return array ['success' => bool, 'db_name' => string|null, 'error' => string|null]
     */
    public function createDatabase(string $domain, string $dbName, string $dbUser, string $dbPass): array
    {
        try {
            if ($this->config->api_type === 'rest') {
                return $this->createDatabaseREST($domain, $dbName, $dbUser, $dbPass);
            } else {
                return $this->createDatabaseXMLRPC($domain, $dbName, $dbUser, $dbPass);
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'db_name' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    // ==================== REST API ====================

    /**
     * Prueba conexión REST
     *
     * @return bool
     */
    private function testRESTConnection(): bool
    {
        $response = $this->makeRESTRequest('/api/v2/server');
        return !empty($response);
    }

    /**
     * Obtiene dominios vía REST
     *
     * @return array
     */
    private function getDomainsREST(): array
    {
        $response = $this->makeRESTRequest('/api/v2/domains');

        if (empty($response)) {
            return [];
        }

        $domains = [];
        // El formato puede variar según la versión de Plesk
        $data = $response['data'] ?? $response;

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $domain) {
            if (!is_array($domain)) {
                continue;
            }

            $domains[] = [
                'name' => $domain['name'] ?? $domain['ascii-name'] ?? '',
                'status' => $domain['status'] ?? 'active',
                'ip' => $domain['ip_address'] ?? $domain['hst_info']['ip'] ?? ''
            ];
        }

        return $domains;
    }

    /**
     * Obtiene aplicaciones vía REST
     *
     * @param string $domain
     * @return array
     */
    private function getApplicationsREST(string $domain): array
    {
        // Intentar endpoint de Site Applications
        $response = $this->makeRESTRequest("/api/v2/domains/{$domain}/site-apps");

        if (empty($response)) {
            return [];
        }

        $apps = [];
        $data = $response['data'] ?? $response;

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $app) {
            if (!is_array($app)) {
                continue;
            }

            $apps[] = [
                'name' => $app['name'] ?? $app['title'] ?? '',
                'type' => $app['type'] ?? $app['package_id'] ?? '',
                'version' => $app['version'] ?? '',
                'path' => $app['path'] ?? $app['install_dir'] ?? ''
            ];
        }

        return $apps;
    }

    /**
     * Obtiene cuentas de correo vía REST
     *
     * @param string $domain
     * @return array
     */
    private function getEmailAccountsREST(string $domain): array
    {
        $response = $this->makeRESTRequest("/api/v2/mail/{$domain}/mailboxes");

        if (empty($response)) {
            return [];
        }

        $accounts = [];
        $data = $response['data'] ?? $response;

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $account) {
            if (!is_array($account)) {
                continue;
            }

            $accounts[] = [
                'email' => $account['email'] ?? $account['name'] . '@' . $domain,
                'quota' => $account['quota'] ?? 0,
                'used' => $account['used'] ?? $account['used_space'] ?? 0
            ];
        }

        return $accounts;
    }

    /**
     * Creates webspace via REST API
     *
     * @param string $domain
     * @param array $params
     * @return array
     */
    private function createWebspaceREST(string $domain, array $params): array
    {
        $plan = $params['plan'] ?? 'Default Domain';
        $ftpUser = $params['ftp_user'] ?? $this->generateFtpUsername($domain);
        $ftpPassword = $params['ftp_password'] ?? $this->generateSecurePassword();

        $data = [
            'name' => $domain,
            'hosting_type' => 'virtual',
            'hosting_settings' => [
                'ftp_login' => $ftpUser,
                'ftp_password' => $ftpPassword
            ],
            'plan' => [
                'name' => $plan
            ]
        ];

        // Add IP if specified
        if (!empty($params['ip'])) {
            $data['ipv4'] = [$params['ip']];
        }

        $response = $this->makeRESTRequest('/api/v2/domains', 'POST', $data);

        if (empty($response) || !isset($response['id'])) {
            return [
                'success' => false,
                'webspace_id' => null,
                'error' => 'Failed to create webspace: ' . json_encode($response)
            ];
        }

        return [
            'success' => true,
            'webspace_id' => (string)$response['id'],
            'ftp_user' => $ftpUser,
            'ftp_password' => $ftpPassword,
            'error' => null
        ];
    }

    /**
     * Installs WordPress via REST API
     *
     * @param string $domain
     * @param array $config
     * @return array
     */
    private function installWordPressREST(string $domain, array $config): array
    {
        // Validate required config
        if (empty($config['admin_user']) || empty($config['admin_password']) || empty($config['admin_email'])) {
            return [
                'success' => false,
                'admin_url' => null,
                'error' => 'Missing required config: admin_user, admin_password, admin_email'
            ];
        }

        $data = [
            'type' => 'wordpress',
            'settings' => [
                'admin_login' => $config['admin_user'],
                'admin_password' => $config['admin_password'],
                'admin_email' => $config['admin_email'],
                'site_name' => $config['site_title'] ?? $domain
            ]
        ];

        // Installation path (empty for root)
        if (!empty($config['path'])) {
            $data['path'] = $config['path'];
        }

        $response = $this->makeRESTRequest("/api/v2/domains/{$domain}/site-apps", 'POST', $data);

        if (empty($response) || isset($response['error'])) {
            return [
                'success' => false,
                'admin_url' => null,
                'error' => 'Failed to install WordPress: ' . json_encode($response)
            ];
        }

        $path = $config['path'] ?? '';
        $adminUrl = 'https://' . $domain . ($path ? '/' . $path : '') . '/wp-admin/';

        return [
            'success' => true,
            'admin_url' => $adminUrl,
            'error' => null
        ];
    }

    /**
     * Creates database via REST API
     *
     * @param string $domain
     * @param string $dbName
     * @param string $dbUser
     * @param string $dbPass
     * @return array
     */
    private function createDatabaseREST(string $domain, string $dbName, string $dbUser, string $dbPass): array
    {
        $data = [
            'name' => $dbName,
            'type' => 'mysql',
            'server_id' => 'localhost'
        ];

        $response = $this->makeRESTRequest("/api/v2/domains/{$domain}/databases", 'POST', $data);

        if (empty($response) || !isset($response['id'])) {
            return [
                'success' => false,
                'db_name' => null,
                'error' => 'Failed to create database: ' . json_encode($response)
            ];
        }

        // Create database user
        $dbId = $response['id'];
        $userData = [
            'login' => $dbUser,
            'password' => $dbPass
        ];

        $userResponse = $this->makeRESTRequest("/api/v2/databases/{$dbId}/users", 'POST', $userData);

        if (empty($userResponse)) {
            return [
                'success' => false,
                'db_name' => null,
                'error' => 'Database created but failed to create user'
            ];
        }

        return [
            'success' => true,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'error' => null
        ];
    }

    /**
     * Realiza un request REST a la API
     *
     * @param string $endpoint
     * @param string $method
     * @param array $data
     * @return array
     * @throws \Exception
     */
    private function makeRESTRequest(string $endpoint, string $method = 'GET', array $data = []): array
    {
        $url = rtrim($this->config->server_url, '/') . $endpoint;

        SolwedLogger::stripe("DEBUG [PLESK-HTTP]: {$method} {$url}");
        if (!empty($data)) {
            // Sanitize any passwords before logging
            $logData = $data;
            foreach (['ftp_password', 'admin_password', 'password'] as $key) {
                if (isset($logData[$key])) {
                    $logData[$key] = '***HIDDEN***';
                }
                if (isset($logData['hosting'][$key])) {
                    $logData['hosting'][$key] = '***HIDDEN***';
                }
                if (isset($logData['settings'][$key])) {
                    $logData['settings'][$key] = '***HIDDEN***';
                }
            }
            SolwedLogger::stripe('DEBUG [PLESK-HTTP]: Request body: ' . json_encode($logData));
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-Key: ' . $this->config->api_token,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        if (!$this->config->verify_ssl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        SolwedLogger::stripe("DEBUG [PLESK-HTTP]: Response code: {$httpCode}");
        if ($error) {
            SolwedLogger::stripe("DEBUG [PLESK-HTTP]: cURL error: {$error}");
            throw new \Exception("Plesk API cURL error: {$error}");
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            SolwedLogger::stripe("DEBUG [PLESK-HTTP]: Non-success response: " . substr($response, 0, 500));
            return [];
        }

        SolwedLogger::stripe("DEBUG [PLESK-HTTP]: Response (truncated): " . substr($response, 0, 500));

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ==================== XML-RPC API ====================

    /**
     * Prueba conexión XML-RPC
     *
     * @return bool
     */
    private function testXMLRPCConnection(): bool
    {
        try {
            $xml = $this->buildXMLRequest('server.get');
            $response = $this->makeXMLRPCRequest($xml);
            return !empty($response);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Obtiene dominios vía XML-RPC
     *
     * @return array
     */
    private function getDomainsXMLRPC(): array
    {
        try {
            $xml = $this->buildXMLRequest('webspace.get');
            $response = $this->makeXMLRPCRequest($xml);
            return $this->parseDomainsFromXML($response);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Obtiene aplicaciones vía XML-RPC
     *
     * @param string $domain
     * @return array
     */
    private function getApplicationsXMLRPC(string $domain): array
    {
        try {
            $xml = $this->buildXMLRequest('site-app.get', ['domain' => $domain]);
            $response = $this->makeXMLRPCRequest($xml);
            return $this->parseApplicationsFromXML($response);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Obtiene cuentas de correo vía XML-RPC
     *
     * @param string $domain
     * @return array
     */
    private function getEmailAccountsXMLRPC(string $domain): array
    {
        try {
            $xml = $this->buildXMLRequest('mail.get', ['domain' => $domain]);
            $response = $this->makeXMLRPCRequest($xml);
            return $this->parseEmailsFromXML($response);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Construye un request XML
     *
     * @param string $method
     * @param array $params
     * @return string
     */
    private function buildXMLRequest(string $method, array $params = []): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<packet version="1.6.8.0">';
        $xml .= "<{$method}>";
        $xml .= '<filter/>';

        foreach ($params as $key => $value) {
            $xml .= "<{$key}>" . htmlspecialchars($value) . "</{$key}>";
        }

        $xml .= "</{$method}>";
        $xml .= '</packet>';

        return $xml;
    }

    /**
     * Realiza un request XML-RPC
     *
     * @param string $xmlData
     * @return array
     * @throws \Exception
     */
    private function makeXMLRPCRequest(string $xmlData): array
    {
        $url = rtrim($this->config->server_url, '/') . '/enterprise/control/agent.php';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'HTTP_AUTH_LOGIN: admin',
            'HTTP_AUTH_PASSWD: ' . $this->config->api_token,
            'Content-Type: text/xml',
            'Content-Length: ' . strlen($xmlData)
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);

        if (!$this->config->verify_ssl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Plesk XML-RPC cURL error: {$error}");
        }

        if ($httpCode !== 200) {
            return [];
        }

        return $this->parseXMLResponse($response);
    }

    /**
     * Parsea respuesta XML
     *
     * @param string $xml
     * @return array
     */
    private function parseXMLResponse(string $xml): array
    {
        try {
            libxml_use_internal_errors(true);
            $data = simplexml_load_string($xml);
            libxml_clear_errors();

            if ($data === false) {
                return [];
            }

            return json_decode(json_encode($data), true) ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Parsea dominios desde XML
     *
     * @param array $data
     * @return array
     */
    private function parseDomainsFromXML(array $data): array
    {
        // Implementación básica - ajustar según estructura real de respuesta
        $domains = [];

        if (isset($data['webspace'])) {
            $webspaces = is_array($data['webspace']) ? $data['webspace'] : [$data['webspace']];

            foreach ($webspaces as $ws) {
                if (isset($ws['data']['gen_info']['name'])) {
                    $domains[] = [
                        'name' => $ws['data']['gen_info']['name'],
                        'status' => $ws['data']['gen_info']['status'] ?? 'active',
                        'ip' => $ws['data']['gen_info']['dns_ip_address'] ?? ''
                    ];
                }
            }
        }

        return $domains;
    }

    /**
     * Parsea aplicaciones desde XML
     *
     * @param array $data
     * @return array
     */
    private function parseApplicationsFromXML(array $data): array
    {
        $apps = [];

        if (isset($data['site-app'])) {
            $siteApps = is_array($data['site-app']) ? $data['site-app'] : [$data['site-app']];

            foreach ($siteApps as $app) {
                if (isset($app['name'])) {
                    $apps[] = [
                        'name' => $app['name'],
                        'type' => $app['package'] ?? '',
                        'version' => $app['version'] ?? '',
                        'path' => $app['path'] ?? ''
                    ];
                }
            }
        }

        return $apps;
    }

    /**
     * Parsea cuentas de correo desde XML
     *
     * @param array $data
     * @return array
     */
    private function parseEmailsFromXML(array $data): array
    {
        $emails = [];

        if (isset($data['mailname'])) {
            $mailnames = is_array($data['mailname']) ? $data['mailname'] : [$data['mailname']];

            foreach ($mailnames as $mail) {
                if (isset($mail['name'])) {
                    $emails[] = [
                        'email' => $mail['name'],
                        'quota' => $mail['mailbox']['quota'] ?? 0,
                        'used' => $mail['mailbox']['used'] ?? 0
                    ];
                }
            }
        }

        return $emails;
    }

    // ==================== XML-RPC WRITE OPERATIONS ====================

    /**
     * Creates webspace via XML-RPC API
     *
     * @param string $domain
     * @param array $params
     * @return array
     */
    private function createWebspaceXMLRPC(string $domain, array $params): array
    {
        $ftpUser = $params['ftp_user'] ?? $this->generateFtpUsername($domain);
        $ftpPassword = $params['ftp_password'] ?? $this->generateSecurePassword();
        $plan = $params['plan'] ?? 'Default Domain';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<packet version="1.6.8.0">';
        $xml .= '<webspace>';
        $xml .= '<add>';
        $xml .= '<gen_setup>';
        $xml .= '<name>' . htmlspecialchars($domain) . '</name>';
        $xml .= '<htype>vrt_hst</htype>';
        if (!empty($params['ip'])) {
            $xml .= '<ip_address>' . htmlspecialchars($params['ip']) . '</ip_address>';
        }
        $xml .= '</gen_setup>';
        $xml .= '<hosting>';
        $xml .= '<vrt_hst>';
        $xml .= '<property><name>ftp_login</name><value>' . htmlspecialchars($ftpUser) . '</value></property>';
        $xml .= '<property><name>ftp_password</name><value>' . htmlspecialchars($ftpPassword) . '</value></property>';
        $xml .= '</vrt_hst>';
        $xml .= '</hosting>';
        $xml .= '</add>';
        $xml .= '</webspace>';
        $xml .= '</packet>';

        $response = $this->makeXMLRPCRequest($xml);

        // Check for success in response
        if (isset($response['webspace']['add']['result']['status']) &&
            $response['webspace']['add']['result']['status'] === 'ok') {
            return [
                'success' => true,
                'webspace_id' => $response['webspace']['add']['result']['id'] ?? null,
                'ftp_user' => $ftpUser,
                'ftp_password' => $ftpPassword,
                'error' => null
            ];
        }

        $errorText = $response['webspace']['add']['result']['errtext'] ?? 'Unknown error';
        return [
            'success' => false,
            'webspace_id' => null,
            'error' => 'Failed to create webspace: ' . $errorText
        ];
    }

    /**
     * Installs WordPress via XML-RPC API
     *
     * @param string $domain
     * @param array $config
     * @return array
     */
    private function installWordPressXMLRPC(string $domain, array $config): array
    {
        if (empty($config['admin_user']) || empty($config['admin_password']) || empty($config['admin_email'])) {
            return [
                'success' => false,
                'admin_url' => null,
                'error' => 'Missing required config: admin_user, admin_password, admin_email'
            ];
        }

        $path = $config['path'] ?? '';
        $siteTitle = $config['site_title'] ?? $domain;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<packet version="1.6.8.0">';
        $xml .= '<site-app>';
        $xml .= '<install>';
        $xml .= '<filter>';
        $xml .= '<site-name>' . htmlspecialchars($domain) . '</site-name>';
        $xml .= '</filter>';
        $xml .= '<package>wordpress</package>';
        $xml .= '<settings>';
        $xml .= '<setting><name>admin_login</name><value>' . htmlspecialchars($config['admin_user']) . '</value></setting>';
        $xml .= '<setting><name>admin_password</name><value>' . htmlspecialchars($config['admin_password']) . '</value></setting>';
        $xml .= '<setting><name>admin_email</name><value>' . htmlspecialchars($config['admin_email']) . '</value></setting>';
        $xml .= '<setting><name>site_name</name><value>' . htmlspecialchars($siteTitle) . '</value></setting>';
        if (!empty($path)) {
            $xml .= '<setting><name>install_path</name><value>' . htmlspecialchars($path) . '</value></setting>';
        }
        $xml .= '</settings>';
        $xml .= '</install>';
        $xml .= '</site-app>';
        $xml .= '</packet>';

        $response = $this->makeXMLRPCRequest($xml);

        // Check for success
        if (isset($response['site-app']['install']['result']['status']) &&
            $response['site-app']['install']['result']['status'] === 'ok') {
            $adminUrl = 'https://' . $domain . ($path ? '/' . $path : '') . '/wp-admin/';
            return [
                'success' => true,
                'admin_url' => $adminUrl,
                'error' => null
            ];
        }

        $errorText = $response['site-app']['install']['result']['errtext'] ?? 'Unknown error';
        return [
            'success' => false,
            'admin_url' => null,
            'error' => 'Failed to install WordPress: ' . $errorText
        ];
    }

    /**
     * Creates database via XML-RPC API
     *
     * @param string $domain
     * @param string $dbName
     * @param string $dbUser
     * @param string $dbPass
     * @return array
     */
    private function createDatabaseXMLRPC(string $domain, string $dbName, string $dbUser, string $dbPass): array
    {
        // Step 1: Create database
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<packet version="1.6.8.0">';
        $xml .= '<database>';
        $xml .= '<add-db>';
        $xml .= '<webspace-name>' . htmlspecialchars($domain) . '</webspace-name>';
        $xml .= '<name>' . htmlspecialchars($dbName) . '</name>';
        $xml .= '<type>mysql</type>';
        $xml .= '</add-db>';
        $xml .= '</database>';
        $xml .= '</packet>';

        $response = $this->makeXMLRPCRequest($xml);

        if (!isset($response['database']['add-db']['result']['status']) ||
            $response['database']['add-db']['result']['status'] !== 'ok') {
            $errorText = $response['database']['add-db']['result']['errtext'] ?? 'Unknown error';
            return [
                'success' => false,
                'db_name' => null,
                'error' => 'Failed to create database: ' . $errorText
            ];
        }

        $dbId = $response['database']['add-db']['result']['id'] ?? null;

        // Step 2: Create database user
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<packet version="1.6.8.0">';
        $xml .= '<database>';
        $xml .= '<add-db-user>';
        $xml .= '<db-id>' . $dbId . '</db-id>';
        $xml .= '<login>' . htmlspecialchars($dbUser) . '</login>';
        $xml .= '<password>' . htmlspecialchars($dbPass) . '</password>';
        $xml .= '</add-db-user>';
        $xml .= '</database>';
        $xml .= '</packet>';

        $response = $this->makeXMLRPCRequest($xml);

        if (!isset($response['database']['add-db-user']['result']['status']) ||
            $response['database']['add-db-user']['result']['status'] !== 'ok') {
            return [
                'success' => false,
                'db_name' => null,
                'error' => 'Database created but failed to create user'
            ];
        }

        return [
            'success' => true,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'error' => null
        ];
    }

    // ==================== HELPER METHODS ====================

    /**
     * Generates a secure random password
     *
     * @param int $length Password length (default: 16)
     * @return string
     */
    private function generateSecurePassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';

        // Ensure at least one of each type
        $password .= $chars[random_int(0, 25)];         // lowercase
        $password .= $chars[random_int(26, 51)];        // uppercase
        $password .= $chars[random_int(52, 61)];        // digit
        $password .= $chars[random_int(62, strlen($chars) - 1)]; // special

        // Fill remaining length
        for ($i = 4; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // Shuffle the password
        return str_shuffle($password);
    }

    /**
     * Generates FTP username from domain
     *
     * @param string $domain
     * @return string
     */
    private function generateFtpUsername(string $domain): string
    {
        // Remove TLD and special characters, limit length
        $parts = explode('.', $domain);
        $name = preg_replace('/[^a-zA-Z0-9]/', '', $parts[0]);
        $name = strtolower(substr($name, 0, 8));

        // Add random suffix for uniqueness
        $suffix = substr(bin2hex(random_bytes(2)), 0, 4);

        return $name . '_' . $suffix;
    }
}
