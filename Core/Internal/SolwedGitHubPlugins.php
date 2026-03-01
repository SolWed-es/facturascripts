<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2025 SolWed <dev@solwed.es>
 */

namespace FacturaScripts\Core\Internal;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;

/**
 * Fetches the SolWed plugin list from the SolwedPlugins-container repository.
 * Used by AdminPlugins to show and install SolWed plugins (Portal SolWed section).
 */
class SolwedGitHubPlugins
{
    const CACHE_KEY = 'solwed_github_plugin_list';
    const JSON_URL  = 'https://raw.githubusercontent.com/SolWed-es/SolwedPlugins-container/main/plugin-list.json';

    /** Returns a name-indexed map of all SolWed own plugins available on GitHub. */
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

    /** Returns the fallback download URL for a plugin (when download_url is not in the list). */
    public static function getDownloadUrl(string $name, string $version): string
    {
        return "https://github.com/SolWed-es/SolwedPlugins-container/raw/main/zip/{$name}.zip";
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
            return is_array($data) && isset($data['plugins']) ? $data['plugins'] : [];
        });
    }
}
