<?php

namespace FacturaScripts\Plugins\SolwedPlugins\Lib;

use Exception;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;

class SolwedGitHubPlugins
{
    private string $githubUsername;
    private string $repoName;
    private string $repoBranch;
    private string $jsonUrl;
    private array $plugins = [];
    private bool $initialized = false;
    private string $cacheDir;
    private string $cacheFile;
    private int $cacheDuration = 3600; // 1 hora en segundos

    public function __construct()
    {
        $this->githubUsername = Tools::settings('solwedplugins', 'github_username', 'SolWed-es');
        $this->repoName = Tools::settings('solwedplugins', 'repo_name', 'facturascripts');
        $this->repoBranch = Tools::settings('solwedplugins', 'repo_branch', 'solwed/production');
        $this->jsonUrl = "https://raw.githubusercontent.com/{$this->githubUsername}/{$this->repoName}/{$this->repoBranch}/plugin-list.json";

        // Configurar directorios de caché
        $this->cacheDir = FS_FOLDER . '/MyFiles/Cache/SolwedPlugins';
        $this->cacheFile = $this->cacheDir . '/plugin-list.json';

        // Crear directorio de caché si no existe
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Permitir configuración del tiempo de caché
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
     * Limpia el caché de plugins
     */
    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
            Tools::log()->notice('Caché de plugins borrado exitosamente');
        }
    }

    /**
     * Verifica si el caché es válido (no expirado)
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
     * Carga plugins desde caché si está disponible
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
                $this->plugins = $data['plugins'];

                $this->initialized = true;

                Tools::log()->notice('Plugins cargados desde caché', [
                    '%count%' => count($this->plugins),
                    '%age%' => time() - filemtime($this->cacheFile) . 's'
                ]);

                return true;
            }
        } catch (Exception $e) {
            Tools::log()->warning('Error al leer caché de plugins', [
                '%error%' => $e->getMessage()
            ]);
        }

        return false;
    }

    /**
     * Guarda plugins en caché
     */
    private function saveToCache(array $data): void
    {
        try {
            $cacheData = ['plugins' => $data, 'timestamp' => time()];
            file_put_contents($this->cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            Tools::log()->warning('Error al guardar caché de plugins', [
                '%error%' => $e->getMessage()
            ]);
        }
    }

    public function getDownloadUrl(array $pluginInfo): string
    {
        $url = $pluginInfo['download_url'] ??
            "https://github.com/{$this->githubUsername}/{$this->repoName}/releases/download/{$pluginInfo['name']}-v{$pluginInfo['version']}/{$pluginInfo['name']}.zip";
        return $url;
    }

    private function loadPlugins(): void
    {
        // Intentar cargar desde caché primero
        if ($this->loadFromCache()) {
            return;
        }

        try {
            $response = Http::get($this->jsonUrl)
                ->setTimeout(15)
                ->setHeader('User-Agent', 'FacturaScripts-SolwedPlugins/1.0');

            if ($response->failed()) {
                // Si falla la descarga, intentar usar caché expirado como fallback
                if (file_exists($this->cacheFile)) {
                    Tools::log()->warning('Solicitud HTTP fallida, usando caché expirado como fallback', [
                        '%url%' => $this->jsonUrl,
                        '%status%' => $response->status()
                    ]);
                    $this->loadFromCache();
                    return;
                }

                Tools::log()->error('Solicitud HTTP fallida', [
                    '%url%' => $this->jsonUrl,
                    '%status%' => $response->status(),
                    '%error%' => $response->errorMessage()
                ]);
                return;
            }

            $data = json_decode($response->body(), true);

            if (!$data || !isset($data['plugins']) || !is_array($data['plugins'])) {
                Tools::log()->error('Estructura JSON del plugin no válida');
                return;
            }

            // Preserve 'source' field from JSON (github or demo)
            $this->plugins = $data['plugins'];

            $this->initialized = true;

            // Guardar en caché
            $this->saveToCache($this->plugins);

            Tools::log()->notice('Plugins extraídos con éxito desde GitHub', [
                '%count%' => count($this->plugins),
                '%url%' => $this->jsonUrl
            ]);
        } catch (Exception $e) {
            Tools::log()->error('Error al cargar los plugins desde GitHub', [
                '%error%' => $e->getMessage(),
                '%url%' => $this->jsonUrl
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
                Tools::log()->error('download-failed', [
                    '%url%' => $url,
                    '%status%' => $response->status(),
                    '%error%' => $response->errorMessage()
                ]);
                return false;
            }

            $content = $response->body();

            if (empty($content)) {
                Tools::log()->error('Contenido descargado vacío', ['%url%' => $url]);
                return false;
            }

            $result = file_put_contents($destination, $content);

            if ($result === false) {
                Tools::log()->error('Error al escribir el archivo', ['%destination%' => $destination]);
                return false;
            }

            $sizeKb = round(strlen($content) / 1024, 2);
            Tools::log()->notice('Descarga exitosa', [
                '%url%' => $url,
                '%size%' => $sizeKb . 'KB'
            ]);

            return true;
        } catch (Exception $e) {
            Tools::log()->error('Excepción durante la descarga', [
                '%url%' => $url,
                '%error%' => $e->getMessage()
            ]);
            return false;
        }
    }
}
