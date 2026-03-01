<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 SolWed <dev@solwed.es>
 */

namespace FacturaScripts\Core\Internal;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;

/**
 * Fetches the SolWed plugin list from two sources:
 *   1. SolWed-es/SolwedPlugins-container (GitHub) — plugin-list.json with download_url per plugin
 *   2. demo.erpsolwed.es — SolwedPluginExport?action=list API
 *
 * Both lists are merged and deduplicated (GitHub takes priority).
 * Used by AdminPlugins to populate the "Portal SolWed" tab.
 */
class SolwedGitHubPlugins
{
    const CACHE_KEY  = 'solwed_plugin_list_v2';
    const GITHUB_URL = 'https://raw.githubusercontent.com/SolWed-es/SolwedPlugins-container/main/plugin-list.json';
    const DEMO_URL   = 'https://demo.erpsolwed.es/SolwedPluginExport?action=list';
    const DEMO_BASE  = 'https://demo.erpsolwed.es';

    /** Returns a name-indexed map of all SolWed plugins available for install. */
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

    /** Fallback download URL when download_url is not present in the plugin data. */
    public static function getDownloadUrl(string $name, string $version): string
    {
        return 'https://github.com/SolWed-es/SolwedPlugins-container/raw/main/zip/' . $name . '.zip';
    }

    private static function fetchPlugins(): array
    {
        return Cache::remember(self::CACHE_KEY, function () {
            $github = self::fetchFromGitHub();
            $demo   = self::fetchFromDemo();

            // merge: GitHub takes priority, demo fills in missing plugins
            $merged = $github;
            foreach ($demo as $plugin) {
                if (!empty($plugin['name']) && !isset($merged[$plugin['name']])) {
                    $merged[$plugin['name']] = $plugin;
                }
            }

            return array_values($merged);
        });
    }

    /** Fetches plugins from SolWed-es/SolwedPlugins-container plugin-list.json */
    private static function fetchFromGitHub(): array
    {
        $response = Http::get(self::GITHUB_URL)
            ->setTimeout(10)
            ->setHeader('User-Agent', 'FacturaScripts-SolWed/1.0');

        if ($response->failed()) {
            return [];
        }

        $data = json_decode($response->body(), true);
        if (!is_array($data) || !isset($data['plugins'])) {
            return [];
        }

        $result = [];
        foreach ($data['plugins'] as $plugin) {
            if (!empty($plugin['name'])) {
                $result[$plugin['name']] = $plugin;
            }
        }
        return $result;
    }

    /** Fetches plugins from demo.erpsolwed.es SolwedPluginExport API */
    private static function fetchFromDemo(): array
    {
        $response = Http::get(self::DEMO_URL)
            ->setTimeout(10)
            ->setHeader('User-Agent', 'FacturaScripts-SolWed/1.0');

        if ($response->failed()) {
            return [];
        }

        $data = json_decode($response->body(), true);
        if (!is_array($data) || !isset($data['plugins'])) {
            return [];
        }

        $result = [];
        foreach ($data['plugins'] as $plugin) {
            if (empty($plugin['name'])) {
                continue;
            }
            // aseguramos que siempre hay download_url
            if (empty($plugin['download_url'])) {
                $plugin['download_url'] = self::DEMO_BASE . '/SolwedPluginExport?action=download&plugin='
                    . urlencode($plugin['name']);
            }
            $result[$plugin['name']] = $plugin;
        }
        return $result;
    }
}
