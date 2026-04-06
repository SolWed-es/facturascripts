<?php

/**
 * Plugin SolwedES - Redis writer for FacturaScripts data
 *
 * Pushes ERP business data to Redis so other services (MIND, portal, bridge)
 * can read it without hitting the FS API.
 *
 * Keys written: fs:suscripciones, fs:suscripcion:{id}, fs:pagos:recientes,
 *               fs:facturas:recientes, fs:clientes, fs:dominios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Core\Tools;

class RedisWriter
{
    private const DEFAULT_TTL = 2100; // 35 min (margin over 30 min cron)

    /**
     * Store a value in Redis with TTL
     */
    public static function set(string $key, $data, int $ttl = self::DEFAULT_TTL): bool
    {
        $redis = self::connect();
        if (!$redis) {
            return false;
        }

        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $redis->set($key, $json, $ttl);
        } catch (\Throwable $e) {
            Tools::log('solwed')->warning('Redis set failed for key ' . $key . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a key
     */
    public static function del(string $key): bool
    {
        $redis = self::connect();
        if (!$redis) {
            return false;
        }

        try {
            return $redis->del($key) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Delete keys matching a pattern
     */
    public static function delPattern(string $pattern): int
    {
        $redis = self::connect();
        if (!$redis) {
            return 0;
        }

        try {
            $keys = $redis->keys($pattern);
            if (empty($keys)) {
                return 0;
            }
            return $redis->del(...$keys);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static ?\Redis $conn = null;

    private static function connect(): ?\Redis
    {
        if (self::$conn !== null) {
            return self::$conn;
        }

        if (!extension_loaded('redis')) {
            return null;
        }

        try {
            $host = Tools::settings('solwed', 'redis_host', 'redis');
            $port = (int)Tools::settings('solwed', 'redis_port', 6379);

            self::$conn = new \Redis();
            self::$conn->connect($host, $port, 2.0);
            return self::$conn;
        } catch (\Throwable $e) {
            Tools::log('solwed')->error('Redis write connection failed: ' . $e->getMessage());
            self::$conn = null;
            return null;
        }
    }
}
