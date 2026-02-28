<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 SolWed <dev@solwed.es>
 */

namespace FacturaScripts\Core\Internal;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;

/**
 * Fetches the plugin list from the SolWed GitHub repository.
 * Used by AdminPlugins to offer direct installation from GitHub Releases
 * instead of redirecting to an external website.
 */
class SolwedGitHubPlugins
{
    const CACHE_KEY = 'solwed_github_plugin_list';
    const JSON_URL = 'https://raw.githubusercontent.com/SolWed-es/facturascripts/solwed/production/plugin-list.json';

    /** Returns a name-indexed map of all plugins available on GitHub. */
    public static function getPluginMap(): array
    {
        $plugins = self::fetchPlugins();
        $map = [];
        foreach ($plugins as $plugin) {
            if (!empty($plugin['name'])) {
                $map[$plugin['name']] = $plugin;
            }
        }
        return $map;
    }

    /** Returns the direct download URL for a plugin. */
    public static function getDownloadUrl(string $name, string $version): string
    {
        return "https://github.com/SolWed-es/facturascripts/releases/download/{$name}-v{$version}/{$name}.zip";
    }

    private static function fetchPlugins(): array
    {
        return Cache::remember(self::CACHE_KEY, function () {
            $response = Http::get(self::JSON_URL)
                ->setTimeout(10)
                ->setHeader('User-Agent', 'FacturaScripts-SolWed/1.0');
            if ($response->failed()) {
                return [];
            }
            $data = json_decode($response->body(), true);
            return $data['plugins'] ?? [];
        });
    }
}
