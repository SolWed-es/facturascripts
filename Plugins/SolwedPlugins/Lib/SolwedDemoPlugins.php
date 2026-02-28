<?php

namespace FacturaScripts\Plugins\SolwedPlugins\Lib;

use Exception;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

/**
 * Fetches plugins from Demo ERP instance
 * Similar to SolwedGitHubPlugins but connects to Demo ERP endpoint
 */
class SolwedDemoPlugins
{
    private string $demoErpUrl;
    private array $plugins = [];
    private bool $initialized = false;
    private string $cacheDir;
    private string $cacheFile;
    private int $cacheDuration = 3600; // 1 hour in seconds

    public function __construct()
    {
        // Demo ERP URL - configured for https://demo.erpsolwed.es
        $this->demoErpUrl = Tools::settings('solwedplugins', 'demo_erp_url', 'https://demo.erpsolwed.es');

        // If not configured in settings, use hardcoded fallback
        if ($this->demoErpUrl === 'https://demo.erpsolwed.es') {
            // Hardcoded Demo ERP URL
            $this->demoErpUrl = 'https://demo.erpsolwed.es';
        }

        // Configure cache directories
        $this->cacheDir = FS_FOLDER . '/MyFiles/Cache/SolwedPlugins';
        $this->cacheFile = $this->cacheDir . '/demo-plugin-list.json';

        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Allow configuration of cache duration
        $this->cacheDuration = (int) Tools::settings('solwedplugins', 'cache_duration', 3600);
    }

    public function getPlugins(): array
    {
        if (!$this->initialized) {
            $this->loadPlugins();
        }
        return $this->plugins;
    }

    public function getPluginInfo(string $pluginName): ?array
    {
        foreach ($this->getPlugins() as $plugin) {
            if ($plugin['name'] === $pluginName) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * Clears the cache of Demo ERP plugins
     */
    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
            Tools::log()->notice('Demo ERP plugin cache cleared');
        }
    }

    /**
     * Checks if the cache is valid (not expired)
     */
    private function isCacheValid(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $fileTime = filemtime($this->cacheFile);
        $cacheAge = time() - $fileTime;

        return $cacheAge < $this->cacheDuration;
    }

    /**
     * Loads plugins from cache if available
     */
    private function loadFromCache(): bool
    {
        if (!$this->isCacheValid()) {
            return false;
        }

        try {
            $content = file_get_contents($this->cacheFile);
            $data = json_decode($content, true);

            if ($data && isset($data['plugins']) && is_array($data['plugins'])) {
                // Add 'source' field to each plugin when loading from cache
                $this->plugins = array_map(function($plugin) {
                    $plugin['source'] = 'demo';
                    return $plugin;
                }, $data['plugins']);

                $this->initialized = true;

                Tools::log()->notice('Demo ERP plugins loaded from cache', [
                    '%count%' => count($this->plugins),
                    '%age%' => time() - filemtime($this->cacheFile) . 's'
                ]);

                return true;
            }
        } catch (Exception $e) {
            Tools::log()->warning('Error reading Demo ERP plugin cache', [
                '%error%' => $e->getMessage()
            ]);
        }

        return false;
    }

    /**
     * Saves plugins to cache
     */
    private function saveToCache(array $data): void
    {
        try {
            $cacheData = ['plugins' => $data, 'timestamp' => time()];
            file_put_contents($this->cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            Tools::log()->warning('Error saving Demo ERP plugin cache', [
                '%error%' => $e->getMessage()
            ]);
        }
    }

    public function getDownloadUrl(array $pluginInfo): string
    {
        // Use the download_url from plugin info if available
        if (isset($pluginInfo['download_url'])) {
            $url = $pluginInfo['download_url'];

            // Check if it's already a full URL (starts with http:// or https://)
            if (preg_match('/^https?:\/\//', $url)) {
                // Fix malformed URLs where the slash is missing after the domain
                // e.g., https://demo.erpsolwed.esSolwedPluginExport -> https://demo.erpsolwed.es/SolwedPluginExport
                $baseUrlPattern = preg_quote($this->demoErpUrl, '/');
                if (preg_match('/^' . $baseUrlPattern . '([^\/].*)$/', $url, $matches)) {
                    // URL is malformed - missing slash after domain
                    return $this->demoErpUrl . '/' . $matches[1];
                }
                return $url;
            }

            // If it's a relative URL, prepend the base URL
            // Make sure there's exactly one slash between base URL and path
            $baseUrl = rtrim($this->demoErpUrl, '/');
            $path = ltrim($url, '/');
            return $baseUrl . '/' . $path;
        }

        // Fallback: construct download URL
        $pluginName = $pluginInfo['name'];
        return $this->demoErpUrl . '/SolwedPluginExport?action=download&plugin=' . urlencode($pluginName);
    }

    private function loadPlugins(): void
    {
        // Try to load from cache first
        if ($this->loadFromCache()) {
            return;
        }

        try {
            $listUrl = $this->demoErpUrl . '/SolwedPluginExport?action=list';

            $response = Http::get($listUrl)
                ->setTimeout(15)
                ->setHeader('User-Agent', 'FacturaScripts-SolwedPlugins/1.0');

            if ($response->failed()) {
                // If fails, try to use expired cache as fallback
                if (file_exists($this->cacheFile)) {
                    Tools::log()->warning('demo-erp-connection-failed-using-cache');
                    $this->loadFromCache();
                    return;
                }

                Tools::log()->error('demo-erp-connection-failed', [
                    '%status%' => $response->status()
                ]);
                return;
            }

            $data = json_decode($response->body(), true);

            if (!$data || !isset($data['plugins']) || !is_array($data['plugins'])) {
                Tools::log()->error('demo-erp-invalid-response');
                return;
            }

            // Add 'source' field to each plugin to identify it as from Demo ERP
            $this->plugins = array_map(function($plugin) {
                $plugin['source'] = 'demo';
                return $plugin;
            }, $data['plugins']);

            $this->initialized = true;

            // Save to cache
            $this->saveToCache($this->plugins);
        } catch (Exception $e) {
            Tools::log()->error('demo-erp-exception', [
                '%error%' => $e->getMessage()
            ]);
        }
    }

    public function downloadPlugin(string $url, string $destination): bool
    {
        try {
            $response = Http::get($url)
                ->setTimeout(45)
                ->setHeader('User-Agent', 'FacturaScripts-SolwedPlugins/1.0');

            if ($response->failed()) {
                Tools::log()->error('download-failed-http-error', [
                    '%status%' => $response->status()
                ]);
                return false;
            }

            $content = $response->body();

            if (empty($content)) {
                Tools::log()->error('download-failed-empty-content');
                return false;
            }

            $result = file_put_contents($destination, $content);

            if ($result === false) {
                Tools::log()->error('download-failed-write-error');
                return false;
            }

            return true;
        } catch (Exception $e) {
            Tools::log()->error('download-failed-exception', [
                '%error%' => $e->getMessage()
            ]);
            return false;
        }
    }
}
