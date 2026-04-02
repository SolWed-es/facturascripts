<?php

/**
 * Plugin SolwedES - API Health Check
 *
 * Comprehensive health monitoring for all SOLWED infrastructure and client services.
 *
 * Endpoints:
 *   GET /ApiHealth?action=all           → full health check (infra + clients)
 *   GET /ApiHealth?action=infra         → only infrastructure services
 *   GET /ApiHealth?action=clients       → only client services (WP/ERP ping)
 *   GET /ApiHealth?action=client&id=X   → single client service detail
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Dinamic\Model\Contacto;

class ApiHealth extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Health';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $token = $_SERVER['HTTP_TOKEN'] ?? '';
        $apiKey = Tools::settings('default', 'apikey', '');
        if (empty($token) || $token !== $apiKey) {
            $this->jsonResponse(['error' => 'Token inválido'], 401);
            return;
        }

        $action = $this->request->get('action', 'all');

        try {
            switch ($action) {
                case 'all':
                    $infra = $this->checkInfrastructure();
                    $clients = $this->checkClientServices();
                    $this->jsonResponse([
                        'success' => true,
                        'data' => [
                            'timestamp' => date('c'),
                            'infrastructure' => $infra,
                            'clients' => $clients,
                            'summary' => $this->buildSummary($infra, $clients),
                        ],
                    ]);
                    break;
                case 'infra':
                    $this->jsonResponse(['success' => true, 'data' => $this->checkInfrastructure()]);
                    break;
                case 'clients':
                    $this->jsonResponse(['success' => true, 'data' => $this->checkClientServices()]);
                    break;
                case 'client':
                    $id = (int)$this->request->get('id', '0');
                    $this->jsonResponse(['success' => true, 'data' => $this->checkSingleClient($id)]);
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action'], 400);
            }
        } catch (Exception $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ── Infrastructure checks ───────────────────────────────────────────────

    private function checkInfrastructure(): array
    {
        $services = [];

        // FacturaScripts (self — always OK if we're responding)
        $services[] = [
            'name' => 'FacturaScripts ERP',
            'type' => 'erp',
            'status' => 'ok',
            'latency_ms' => 0,
            'url' => Tools::settings('solwed', 'portal_url', 'https://erp.solwed.es'),
            'version' => Tools::config('version', '?'),
        ];

        // Mind API
        $services[] = $this->pingService(
            'Mind API',
            'ai',
            Tools::settings('solwed', 'mind_api_url', 'https://mind.solwed.es/api') . '/admin/health'
        );

        // Kolab Mail
        $kolabUrl = Tools::settings('solwed', 'kolab_api_url', '');
        if ($kolabUrl) {
            $services[] = $this->pingService('Kolab Mail', 'email', $kolabUrl);
        }

        // Cloudflare
        $cfToken = Tools::settings('solwed', 'cloudflare_api_token', '');
        if ($cfToken) {
            $services[] = $this->pingServiceWithAuth(
                'Cloudflare',
                'dns',
                'https://api.cloudflare.com/client/v4/user/tokens/verify',
                ['Authorization' => 'Bearer ' . $cfToken]
            );
        }

        // DonDominio
        $ddUser = Tools::settings('solwed', 'dondominio_api_user', '');
        if ($ddUser) {
            $services[] = $this->pingDonDominio($ddUser);
        }

        // Twilio
        $twilioSid = Tools::settings('solwed', 'twilio_account_sid', '');
        if ($twilioSid) {
            $services[] = [
                'name' => 'Twilio',
                'type' => 'messaging',
                'status' => 'configured',
                'latency_ms' => null,
                'details' => 'SID: ' . substr($twilioSid, 0, 10) . '...',
            ];
        }

        // Redis
        $redisUrl = Tools::settings('solwed', 'redis_url', '');
        if ($redisUrl) {
            $services[] = $this->checkRedis($redisUrl);
        }

        return $services;
    }

    private function pingService(string $name, string $type, string $url): array
    {
        $start = microtime(true);
        try {
            $ctx = stream_context_create([
                'http' => ['timeout' => 5, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => false],
            ]);
            $result = @file_get_contents($url, false, $ctx);
            $latency = round((microtime(true) - $start) * 1000);
            $status = $result !== false ? 'ok' : 'error';

            return [
                'name' => $name,
                'type' => $type,
                'status' => $status,
                'latency_ms' => $latency,
                'url' => $url,
            ];
        } catch (Exception $e) {
            return [
                'name' => $name,
                'type' => $type,
                'status' => 'error',
                'latency_ms' => round((microtime(true) - $start) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function pingServiceWithAuth(string $name, string $type, string $url, array $headers): array
    {
        $start = microtime(true);
        try {
            $headerStr = '';
            foreach ($headers as $k => $v) {
                $headerStr .= "$k: $v\r\n";
            }
            $ctx = stream_context_create([
                'http' => ['timeout' => 5, 'header' => $headerStr, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => true],
            ]);
            $result = @file_get_contents($url, false, $ctx);
            $latency = round((microtime(true) - $start) * 1000);

            $ok = false;
            if ($result) {
                $json = json_decode($result, true);
                $ok = isset($json['success']) ? (bool)$json['success'] : ($result !== false);
            }

            return [
                'name' => $name,
                'type' => $type,
                'status' => $ok ? 'ok' : 'error',
                'latency_ms' => $latency,
            ];
        } catch (Exception $e) {
            return [
                'name' => $name,
                'type' => $type,
                'status' => 'error',
                'latency_ms' => round((microtime(true) - $start) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function pingDonDominio(string $user): array
    {
        $start = microtime(true);
        try {
            $passwd = Tools::settings('solwed', 'dondominio_api_password', '');
            $endpoint = Tools::settings('solwed', 'dondominio_endpoint', 'https://simple-api.dondominio.net');
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'timeout' => 5,
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query(['apiuser' => $user, 'apipasswd' => $passwd]),
                ],
            ]);
            $result = @file_get_contents($endpoint . '/tool/hello/', false, $ctx);
            $latency = round((microtime(true) - $start) * 1000);
            $ok = $result && strpos($result, '"success":true') !== false;

            return [
                'name' => 'DonDominio',
                'type' => 'domains',
                'status' => $ok ? 'ok' : 'error',
                'latency_ms' => $latency,
            ];
        } catch (Exception $e) {
            return [
                'name' => 'DonDominio',
                'type' => 'domains',
                'status' => 'error',
                'latency_ms' => round((microtime(true) - $start) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkRedis(string $url): array
    {
        // Basic TCP ping to Redis host
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 6379;

        $start = microtime(true);
        $sock = @fsockopen($host, $port, $errno, $errstr, 3);
        $latency = round((microtime(true) - $start) * 1000);

        if ($sock) {
            fclose($sock);
            return ['name' => 'Redis', 'type' => 'cache', 'status' => 'ok', 'latency_ms' => $latency];
        }

        return ['name' => 'Redis', 'type' => 'cache', 'status' => 'error', 'latency_ms' => $latency, 'error' => $errstr];
    }

    /**
     * SSRF protection: only allow HTTPS URLs pointing to public IPs
     */
    private function isUrlSafe(string $url): bool
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? '';
        $host = $parsed['host'] ?? '';

        if ($scheme !== 'https' || !$host) return false;

        // Block obvious internal hostnames
        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '169.254.169.254', 'metadata.google'];
        foreach ($blocked as $b) {
            if (stripos($host, $b) !== false) return false;
        }

        // Resolve and check for private IPs
        $ip = @gethostbyname($host);
        if ($ip === $host) return true; // DNS failed, allow (will fail on connect anyway)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    // ── Client service checks ───────────────────────────────────────────────

    private function checkClientServices(): array
    {
        $accesos = (new AccesoServicio())->all(
            [Where::isEqual('activo', true)],
            ['idcontacto' => 'ASC'],
            0,
            200
        );

        $results = [];
        foreach ($accesos as $acceso) {
            if (!$acceso->url_acceso || strpos($acceso->url_acceso, 'pendiente') !== false) {
                continue;
            }
            if (!$this->isUrlSafe($acceso->url_acceso)) {
                continue; // Skip internal/private URLs (SSRF protection)
            }

            $results[] = $this->checkClientAccess($acceso);
        }

        return $results;
    }

    private function checkSingleClient(int $id): array
    {
        $acceso = new AccesoServicio();
        if (!$acceso->loadFromCode($id)) {
            return ['error' => 'Not found'];
        }
        return $this->checkClientAccess($acceso);
    }

    private function checkClientAccess(AccesoServicio $acceso): array
    {
        $contacto = new Contacto();
        $clienteName = '';
        if ($contacto->loadFromCode($acceso->idcontacto)) {
            $clienteName = trim($contacto->nombre . ' ' . ($contacto->apellidos ?? ''));
        }

        $servicioName = '';
        $servicio = new Servicio();
        if ($servicio->loadFromCode($acceso->idservicio)) {
            $servicioName = $servicio->nombre;
        }

        $result = [
            'id' => $acceso->id,
            'cliente' => $clienteName,
            'idcontacto' => $acceso->idcontacto,
            'servicio' => $servicioName,
            'tipo' => $acceso->tipo_acceso,
            'url' => $acceso->url_acceso,
            'last_access' => $acceso->last_access,
        ];

        // Ping the service
        $start = microtime(true);
        try {
            $ctx = stream_context_create([
                'http' => ['timeout' => 5, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => false],
            ]);
            $headers = @get_headers($acceso->url_acceso, true, $ctx);
            $latency = round((microtime(true) - $start) * 1000);

            if ($headers) {
                $statusLine = is_array($headers[0]) ? $headers[0][0] : $headers[0];
                $httpCode = (int)substr($statusLine, 9, 3);
                $result['status'] = ($httpCode >= 200 && $httpCode < 400) ? 'ok' : 'warning';
                $result['http_code'] = $httpCode;
                $result['latency_ms'] = $latency;

                // SSL check
                if (strpos($acceso->url_acceso, 'https://') === 0) {
                    $result['ssl'] = 'ok';
                    // Check SSL expiry
                    $sslExpiry = $this->getSSLExpiry($acceso->url_acceso);
                    if ($sslExpiry) {
                        $result['ssl_expires'] = $sslExpiry;
                        $daysLeft = (strtotime($sslExpiry) - time()) / 86400;
                        if ($daysLeft < 14) {
                            $result['ssl'] = 'expiring';
                            $result['ssl_days_left'] = round($daysLeft);
                        }
                    }
                }

                // Version detection for WordPress
                if ($acceso->tipo_acceso === 'wordpress') {
                    $version = $this->detectWPVersion($acceso->url_acceso);
                    if ($version) $result['version'] = $version;
                }
            } else {
                $result['status'] = 'down';
                $result['latency_ms'] = $latency;
            }
        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            $result['latency_ms'] = round((microtime(true) - $start) * 1000);
        }

        return $result;
    }

    private function getSSLExpiry(string $url): ?string
    {
        try {
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? '';
            if (!$host) return null;

            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
            $socket = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
            if (!$socket) return null;

            $params = stream_context_get_params($socket);
            fclose($socket);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if (!$cert) return null;

            $certInfo = openssl_x509_parse($cert);
            if (!$certInfo || !isset($certInfo['validTo_time_t'])) return null;

            return date('Y-m-d', $certInfo['validTo_time_t']);
        } catch (Exception $e) {
            return null;
        }
    }

    private function detectWPVersion(string $url): ?string
    {
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 3], 'ssl' => ['verify_peer' => false]]);
            $html = @file_get_contents(rtrim($url, '/') . '/', false, $ctx);
            if (!$html) return null;

            // Look for <meta name="generator" content="WordPress X.X.X" />
            if (preg_match('/content="WordPress\s+([\d.]+)"/i', $html, $matches)) {
                return $matches[1];
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    // ── Summary ─────────────────────────────────────────────────────────────

    private function buildSummary(array $infra, array $clients): array
    {
        $infraOk = count(array_filter($infra, fn($s) => $s['status'] === 'ok'));
        $infraTotal = count($infra);

        $clientsOk = count(array_filter($clients, fn($s) => ($s['status'] ?? '') === 'ok'));
        $clientsDown = count(array_filter($clients, fn($s) => in_array($s['status'] ?? '', ['down', 'error'])));
        $clientsTotal = count($clients);
        $sslExpiring = count(array_filter($clients, fn($s) => ($s['ssl'] ?? '') === 'expiring'));

        return [
            'infra_ok' => $infraOk,
            'infra_total' => $infraTotal,
            'clients_ok' => $clientsOk,
            'clients_down' => $clientsDown,
            'clients_total' => $clientsTotal,
            'ssl_expiring' => $sslExpiring,
            'overall' => ($infraOk === $infraTotal && $clientsDown === 0) ? 'healthy' : 'degraded',
        ];
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
