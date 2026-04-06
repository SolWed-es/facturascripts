<?php

/**
 * Plugin SolwedES - Redis reader for cached external service data
 *
 * Reads data cached by solwed-bridge sync workers.
 * Keys: dd:*, plesk:*, kolab:*, cf:*
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Core\Tools;

class RedisReader
{
    private static ?\Redis $conn = null;

    /**
     * Get a single key as decoded array/object
     */
    public static function get(string $key): ?array
    {
        $redis = self::connect();
        if (!$redis) {
            return null;
        }

        try {
            $raw = $redis->get($key);
            if ($raw === false) {
                return null;
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Tools::log('solwed')->warning('Redis get failed for key ' . $key . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a key expected to be a JSON array
     */
    public static function getList(string $key): array
    {
        return self::get($key) ?? [];
    }

    /**
     * Check if a key exists in Redis
     */
    public static function exists(string $key): bool
    {
        $redis = self::connect();
        if (!$redis) {
            return false;
        }

        try {
            return (bool)$redis->exists($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get remaining TTL for a key in seconds (-1 = no expiry, -2 = key missing)
     */
    public static function ttl(string $key): int
    {
        $redis = self::connect();
        if (!$redis) {
            return -2;
        }

        try {
            return $redis->ttl($key);
        } catch (\Throwable $e) {
            return -2;
        }
    }

    private static function connect(): ?\Redis
    {
        if (self::$conn !== null) {
            return self::$conn;
        }

        if (!extension_loaded('redis')) {
            Tools::log('solwed')->error('PHP redis extension not loaded');
            return null;
        }

        try {
            $host = Tools::settings('solwed', 'redis_host', 'redis');
            $port = (int)Tools::settings('solwed', 'redis_port', 6379);

            self::$conn = new \Redis();
            self::$conn->connect($host, $port, 2.0); // 2s timeout
            return self::$conn;
        } catch (\Throwable $e) {
            Tools::log('solwed')->error('Redis connection failed: ' . $e->getMessage());
            self::$conn = null;
            return null;
        }
    }
}
