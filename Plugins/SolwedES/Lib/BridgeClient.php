<?php

/**
 * Plugin SolwedES - HTTP client for solwed-bridge
 *
 * All write operations to external services go through bridge.
 * Base URL and token from Tools::settings('solwed', ...).
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Core\Tools;

class BridgeClient
{
    private const TIMEOUT = 30;

    public static function get(string $path, array $query = [], int $timeout = self::TIMEOUT): array
    {
        $url = self::buildUrl($path, $query);
        return self::request('GET', $url, null, $timeout);
    }

    public static function post(string $path, array $data = [], int $timeout = self::TIMEOUT): array
    {
        $url = self::buildUrl($path);
        return self::request('POST', $url, $data, $timeout);
    }

    public static function put(string $path, array $data = [], int $timeout = self::TIMEOUT): array
    {
        $url = self::buildUrl($path);
        return self::request('PUT', $url, $data, $timeout);
    }

    public static function delete(string $path, array $query = [], int $timeout = self::TIMEOUT): array
    {
        $url = self::buildUrl($path, $query);
        return self::request('DELETE', $url, null, $timeout);
    }

    private static function buildUrl(string $path, array $query = []): string
    {
        $baseUrl = rtrim(Tools::settings('solwed', 'bridge_url', 'http://solwed-bridge:3009'), '/');
        $url = $baseUrl . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    private static function request(string $method, string $url, ?array $body = null, int $timeout = self::TIMEOUT): array
    {
        $token = Tools::settings('solwed', 'bridge_token', '');

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Tools::log('solwed')->error("Bridge request failed: {$method} {$url} — {$error}");
            return ['ok' => false, 'error' => 'Bridge connection failed: ' . $error];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            Tools::log('solwed')->error("Bridge invalid response: {$method} {$url} — HTTP {$httpCode}");
            return ['ok' => false, 'error' => "Bridge returned HTTP {$httpCode}", 'raw' => $response];
        }

        if ($httpCode >= 400) {
            $msg = $decoded['error'] ?? $decoded['message'] ?? "HTTP {$httpCode}";
            Tools::log('solwed')->warning("Bridge error: {$method} {$url} — {$msg}");
            return array_merge(['ok' => false], $decoded);
        }

        return array_merge(['ok' => true], $decoded);
    }
}
