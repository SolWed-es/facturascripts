<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 SolWed <dev@solwed.es>
 */

namespace FacturaScripts\Core\Internal;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;

/**
 * Fetches the official SolWed plugin list from demo.erpsolwed.es.
 * Used by AdminPlugins to populate the "Más plugins" tab.
 */
class SolwedDemoPlugins
{
    const CACHE_KEY = 'solwed_demo_plugin_list';
    const LIST_URL  = 'https://demo.erpsolwed.es/SolwedPluginExport?action=list';
    const BASE_URL  = 'https://demo.erpsolwed.es';

    /** Returns a name-indexed map of all plugins available on demo.erpsolwed.es. */
    public static function getPluginMap(): array
    {
        $map = [];
        foreach (self::fetchPlugins() as $plugin) {
            if (!empty($plugin['name'])) {
                $map[$plugin['name']] = $plugin;
            }
        }
        return $map;
    }

    private static function fetchPlugins(): array
    {
        return Cache::remember(self::CACHE_KEY, function () {
            $response = Http::get(self::LIST_URL)
                ->setTimeout(10)
                ->setHeader('User-Agent', 'FacturaScripts-SolWed/1.0');
            if ($response->failed()) {
                return [];
            }
            $data = json_decode($response->body(), true);
            if (!is_array($data) || !isset($data['plugins'])) {
                return [];
            }
            $plugins = [];
            foreach ($data['plugins'] as $plugin) {
                if (empty($plugin['name'])) {
                    continue;
                }
                // asegurar siempre download_url
                if (empty($plugin['download_url'])) {
                    $plugin['download_url'] = self::BASE_URL . '/SolwedPluginExport?action=download&plugin='
                        . urlencode($plugin['name']);
                }
                $plugin['in_github'] = true;
                $plugin['health'] = $plugin['health'] ?? 5;
                $plugins[] = $plugin;
            }
            return $plugins;
        });
    }
}
