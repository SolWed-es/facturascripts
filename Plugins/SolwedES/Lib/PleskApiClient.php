<?php

/**
 * Plugin SolwedES - Plesk API client
 *
 * Reads cached data from Redis (populated by solwed-bridge PleskSync worker).
 * Write operations go through BridgeClient → solwed-bridge → Plesk API.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Plugins\SolwedES\Model\PleskConfig;

class PleskApiClient
{
    private PleskConfig $config;

    public function __construct(PleskConfig $config)
    {
        $this->config = $config;
    }

    // ─── READ operations (Redis cache) ───────────────────────

    /**
     * Prueba la conexión al servidor Plesk
     */
    public function testConnection(): bool
    {
        $result = BridgeClient::get('/plesk/server');
        return ($result['ok'] ?? false) && !empty($result['data']);
    }

    /**
     * Obtiene lista de dominios del servidor
     */
    public function getDomains(): array
    {
        $cached = RedisReader::getList('plesk:sites');
        if (!empty($cached)) {
            return $cached;
        }

        // Fallback to bridge
        $result = BridgeClient::get('/plesk/sites');
        return $result['data'] ?? [];
    }

    /**
     * Obtiene informacion de un dominio
     */
    public function getSiteInfo(string $domain): ?array
    {
        $cached = RedisReader::get("plesk:site:{$domain}");
        if ($cached !== null) {
            return $cached;
        }

        $result = BridgeClient::get("/plesk/sites/{$domain}");
        return ($result['ok'] ?? false) ? ($result['data'] ?? null) : null;
    }

    /**
     * Obtiene aplicaciones instaladas en un dominio
     */
    public function getApplications(string $domain): array
    {
        // Not cached in Redis — go through bridge
        // Bridge CLI can list applications
        $result = BridgeClient::post('/plesk/cli', [
            'path' => '/usr/local/psa/bin/site',
            'params' => ['--list-apps', '-name', $domain],
        ]);
        return ($result['ok'] ?? false) ? ($result['data'] ?? []) : [];
    }

    /**
     * Obtiene cuentas de correo de un dominio
     */
    public function getEmailAccounts(string $domain): array
    {
        $cached = RedisReader::get("plesk:mail:{$domain}");
        if ($cached !== null) {
            return $cached;
        }

        $result = BridgeClient::get("/plesk/sites/{$domain}/mailboxes");
        return ($result['ok'] ?? false) ? ($result['data'] ?? []) : [];
    }

    /**
     * Obtiene configuracion PHP de un dominio
     */
    public function getPHPSettings(string $domain): array
    {
        $cached = RedisReader::get("plesk:php:{$domain}");
        if ($cached !== null) {
            return $cached;
        }

        $result = BridgeClient::get("/plesk/sites/{$domain}/php");
        return ($result['ok'] ?? false) ? ($result['data'] ?? []) : [];
    }

    /**
     * Obtiene informacion SSL de un dominio
     */
    public function getCertificateInfo(string $domain): ?array
    {
        $cached = RedisReader::get("plesk:ssl:{$domain}");
        if ($cached !== null) {
            return $cached;
        }

        $result = BridgeClient::get("/plesk/sites/{$domain}/ssl");
        return ($result['ok'] ?? false) ? ($result['data'] ?? null) : null;
    }

    /**
     * Obtiene instalaciones de FacturaScripts en el servidor
     */
    public function getFacturaScriptsInstances(): array
    {
        $domains = $this->getDomains();
        $instances = [];

        foreach ($domains as $domain) {
            $apps = $this->getApplications($domain['name'] ?? '');
            foreach ($apps as $app) {
                if (stripos($app['path'] ?? '', 'facturascripts') !== false ||
                    stripos($app['name'] ?? '', 'facturascripts') !== false) {
                    $instances[] = [
                        'domain' => $domain['name'] ?? '',
                        'path' => $app['path'] ?? '',
                        'version' => $app['version'] ?? 'Unknown',
                        'name' => $app['name'] ?? 'FacturaScripts',
                    ];
                }
            }
        }

        return $instances;
    }

    // ─── WRITE operations (always via bridge) ────────────────

    /**
     * Creates a new webspace (hosting subscription) for a domain
     */
    public function createWebspace(string $domain, array $params = []): array
    {
        $result = BridgeClient::post('/plesk/sites', array_merge(['domain' => $domain], $params));

        if (!($result['ok'] ?? false)) {
            return [
                'success' => false,
                'webspace_id' => null,
                'error' => $result['error'] ?? 'Bridge error',
            ];
        }

        return [
            'success' => true,
            'webspace_id' => $result['data']['id'] ?? null,
            'ftp_user' => $params['ftp_user'] ?? null,
            'error' => null,
        ];
    }

    /**
     * Installs WordPress on a domain via Plesk CLI
     */
    public function installWordPress(string $domain, array $config): array
    {
        $cliParams = [
            '--install', '-domain-name', $domain,
            '-admin-name', $config['admin_user'] ?? 'admin',
            '-passwd', $config['admin_password'] ?? '',
            '-admin-email', $config['admin_email'] ?? '',
            '-site-title', $config['site_title'] ?? $domain,
        ];

        if (!empty($config['path'])) {
            $cliParams[] = '-path';
            $cliParams[] = $config['path'];
        }

        $result = BridgeClient::post('/plesk/cli', [
            'path' => '/usr/local/psa/bin/wp-toolkit',
            'params' => $cliParams,
        ]);

        if (!($result['ok'] ?? false)) {
            return [
                'success' => false,
                'admin_url' => null,
                'error' => $result['error'] ?? 'Bridge error',
            ];
        }

        return [
            'success' => true,
            'admin_url' => "https://{$domain}/wp-admin/",
            'error' => null,
        ];
    }

    /**
     * Creates a database for the domain
     */
    public function createDatabase(string $domain, string $dbName, string $dbUser, string $dbPass): array
    {
        $result = BridgeClient::post("/plesk/sites/{$domain}/databases", [
            'name' => $dbName,
            'user' => $dbUser,
            'password' => $dbPass,
        ]);

        if (!($result['ok'] ?? false)) {
            return ['success' => false, 'db_name' => null, 'error' => $result['error'] ?? 'Bridge error'];
        }

        return ['success' => true, 'db_name' => $dbName, 'error' => null];
    }
}
