<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\SolwedES\Lib\RedisReader;
use FacturaScripts\Plugins\SolwedES\Lib\FacturaScriptsSync;

/**
 * Generic Redis data API — serves cached FS + external service data.
 *
 * GET /ApiRedis?key=fs:suscripciones         — get a specific key
 * GET /ApiRedis?key=fs:cliente:C001          — get a specific key
 * GET /ApiRedis?prefix=fs:suscripcion:       — list all keys with prefix (returns array)
 * GET /ApiRedis?action=stats                 — get fs:stats
 * GET /ApiRedis?action=keys                  — list available key prefixes
 * POST /ApiRedis?action=sync                 — force FS → Redis sync now
 *
 * Allowed prefixes: fs:, dd:, plesk:, kolab:, cf:
 */
class ApiRedis extends Controller
{
    private const ALLOWED_PREFIXES = ['fs:', 'dd:', 'plesk:', 'kolab:', 'cf:'];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Redis';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = ['https://app.solwed.es', 'https://erp.solwed.es', 'https://mind.solwed.es'];
        if (in_array($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        if (!$this->validateToken()) {
            return;
        }

        $action = $this->request->get('action', '');
        $key = $this->request->get('key', '');
        $prefix = $this->request->get('prefix', '');

        if ($action === 'stats') {
            $this->handleStats();
        } elseif ($action === 'keys') {
            $this->handleKeys();
        } elseif ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleSync();
        } elseif (!empty($key)) {
            $this->handleGet($key);
        } elseif (!empty($prefix)) {
            $this->handlePrefix($prefix);
        } else {
            $this->jsonResponse(['error' => 'key, prefix, or action required'], 400);
        }
    }

    private function handleGet(string $key): void
    {
        if (!$this->isAllowedKey($key)) {
            $this->jsonResponse(['error' => 'Key prefix not allowed'], 403);
            return;
        }

        $data = RedisReader::get($key);
        if ($data === null) {
            // Try as list
            $data = RedisReader::getList($key);
            if (empty($data)) {
                $this->jsonResponse(['error' => 'Key not found or empty', 'key' => $key], 404);
                return;
            }
        }

        $this->jsonResponse([
            'success' => true,
            'key' => $key,
            'ttl' => RedisReader::ttl($key),
            'data' => $data,
        ]);
    }

    private function handlePrefix(string $prefix): void
    {
        if (!$this->isAllowedKey($prefix)) {
            $this->jsonResponse(['error' => 'Prefix not allowed'], 403);
            return;
        }

        // Use Redis KEYS to find matching keys (safe for our small dataset)
        $redis = $this->getRedis();
        if (!$redis) {
            $this->jsonResponse(['error' => 'Redis not available'], 503);
            return;
        }

        try {
            $keys = $redis->keys($prefix . '*');
            $result = [];
            foreach ($keys as $k) {
                $raw = $redis->get($k);
                $result[$k] = $raw ? json_decode($raw, true) : null;
            }

            $this->jsonResponse([
                'success' => true,
                'prefix' => $prefix,
                'count' => count($result),
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['error' => 'Redis error: ' . $e->getMessage()], 500);
        }
    }

    private function handleStats(): void
    {
        $stats = RedisReader::get('fs:stats');
        if (!$stats) {
            $this->jsonResponse(['error' => 'No stats cached. Run sync first.'], 404);
            return;
        }

        $this->jsonResponse(['success' => true, 'data' => $stats]);
    }

    private function handleKeys(): void
    {
        $redis = $this->getRedis();
        if (!$redis) {
            $this->jsonResponse(['error' => 'Redis not available'], 503);
            return;
        }

        $summary = [];
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            try {
                $keys = $redis->keys($prefix . '*');
                $summary[$prefix] = count($keys);
            } catch (\Throwable $e) {
                $summary[$prefix] = -1;
            }
        }

        $this->jsonResponse(['success' => true, 'data' => $summary]);
    }

    private function handleSync(): void
    {
        try {
            $stats = FacturaScriptsSync::syncAll();
            $this->jsonResponse(['success' => true, 'synced' => $stats]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['error' => 'Sync failed: ' . $e->getMessage()], 500);
        }
    }

    private function isAllowedKey(string $key): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private function getRedis(): ?\Redis
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        try {
            $host = \FacturaScripts\Core\Tools::settings('solwed', 'redis_host', 'redis');
            $port = (int)\FacturaScripts\Core\Tools::settings('solwed', 'redis_port', 6379);
            $redis = new \Redis();
            $redis->connect($host, $port, 2.0);
            return $redis;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function validateToken(): bool
    {
        $token = $_SERVER['HTTP_TOKEN'] ?? '';
        if (empty($token)) {
            $this->jsonResponse(['error' => 'Token required'], 401);
            return false;
        }
        $db = new \FacturaScripts\Core\Base\DataBase();
        $db->connect();
        $result = $db->select("SELECT 1 FROM api_keys WHERE apikey = " . $db->var2str($token) . " AND enabled = true LIMIT 1");
        if (!empty($result)) {
            return true;
        }
        $this->jsonResponse(['error' => 'Token inválido'], 401);
        return false;
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
