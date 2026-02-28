<?php

namespace FacturaScripts\Plugins\SolwedPlugins\Controller;

use FacturaScripts\Core\Controller\AdminPlugins as CoreAdminPlugins;
use FacturaScripts\Plugins\SolwedPlugins\Lib\SolwedGitHubPlugins;
use FacturaScripts\Plugins\SolwedPlugins\Lib\SolwedDemoPlugins;
use FacturaScripts\Plugins\SolwedPlugins\Lib\PluginValidator;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Kernel;

class AdminPlugins extends CoreAdminPlugins
{
    /** @var array */
    public $solwedPluginList = [];

    /** @var SolwedGitHubPlugins */
    private $github;

    /** @var SolwedDemoPlugins */
    private $demoErp;

    public function __construct(string $className, string $url = '')
    {
        parent::__construct($className, $url);
        $this->github = new SolwedGitHubPlugins();
        $this->demoErp = new SolwedDemoPlugins();
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        // Handle Solwed-specific actions
        $action = $this->request->get('action', '');

        switch ($action) {
            case 'refreshSolwed':
                $this->refreshSolwedPlugins();
                break;

            case 'install':
                $this->installPluginAction();
                break;

            case 'update':
                $this->updatePluginAction();
                break;
        }

        // Load Solwed plugins from both GitHub and Demo ERP
        $githubPlugins = $this->github->getPlugins();
        $demoPlugins = $this->demoErp->getPlugins();

        // Merge: JSON (github) tiene prioridad. Omitir del demo los que ya están en el JSON.
        $githubNames = array_column($githubPlugins, 'name');
        $demoOnly = array_values(array_filter($demoPlugins, fn($p) => !in_array($p['name'], $githubNames)));
        $this->solwedPluginList = array_merge($githubPlugins, $demoOnly);

        // Mark which plugins are already installed
        $this->markInstalledPlugins();
    }

    private function refreshSolwedPlugins(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        // Clear both caches to force refresh
        $this->github->clearCache();
        $this->demoErp->clearCache();

        // Reload plugins from both sources
        $githubPlugins = $this->github->getPlugins();
        $demoPlugins = $this->demoErp->getPlugins();

        $totalPlugins = count($githubPlugins) + count($demoPlugins);

        if ($totalPlugins > 0) {
            Tools::log()->notice('Plugins Solwed refreshed successfully', [
                'github' => count($githubPlugins),
                'demo' => count($demoPlugins),
                'total' => $totalPlugins
            ]);
        } else {
            Tools::log()->warning('No plugins found after refresh');
        }
    }

    private function markInstalledPlugins(): void
    {
        $installedPlugins = Plugins::list(true);  // true para incluir plugins ocultos
        $currentFsVersion = $this->getFacturaScriptsVersion();

        // Crear mapa indexado de plugins instalados para búsquedas O(1)
        $installedPluginsMap = PluginValidator::createInstalledPluginsMap($installedPlugins);

        foreach ($this->solwedPluginList as &$plugin) {
            // Validar compatibilidad
            $validation = PluginValidator::validatePlugin($plugin, $currentFsVersion);
            $plugin['compatible'] = $validation['compatible'];
            $plugin['compatibility_message'] = $validation['message'];

            // Verificar si está instalado y si hay actualización disponible
            // Usar mapa indexado para búsqueda O(1) en lugar de O(n)
            $status = PluginValidator::checkInstallationStatus(
                $plugin['name'],
                $plugin['version'] ?? '0',
                $installedPluginsMap
            );
            $plugin['installed'] = $status['installed'];
            $plugin['update_available'] = $status['update_available'];
        }
    }

    /**
     * Obtiene la versión actual de FacturaScripts
     */
    public function getFacturaScriptsVersion(): float
    {
        return (float) Kernel::version();
    }

    /**
     * Maneja la acción de instalación de un plugin desde GitHub
     */
    private function installPluginAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        }

        if (false === $this->validateFormToken()) {
            Tools::log()->error('Invalid form token');
            return;
        }

        $pluginName = $this->request->get('plugin', '');

        if (empty($pluginName)) {
            Tools::log()->error('plugin-name-required');
            return;
        }

        $this->installPlugin($pluginName);
    }

    /**
     * Maneja la acción de actualización de un plugin desde GitHub
     */
    private function updatePluginAction(): void
    {
        Tools::log()->info('updatePluginAction called');

        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        }

        if (false === $this->validateFormToken()) {
            Tools::log()->error('Invalid form token');
            return;
        }

        $pluginName = $this->request->get('plugin', '');
        Tools::log()->info('Updating plugin', ['plugin' => $pluginName]);

        if (empty($pluginName)) {
            Tools::log()->error('No plugin name provided');
            return;
        }

        // Para actualizar, primero deshabilitamos y luego reinstalamos
        $this->installPlugin($pluginName, true);
    }

    /**
     * Installs a plugin from GitHub or Demo ERP
     */
    private function installPlugin(string $pluginName, bool $isUpdate = false): void
    {
        $currentFsVersion = $this->getFacturaScriptsVersion();

        // Get explicit source from request parameter, fallback to auto-detection
        $requestedSource = $this->request->get('source', '');

        $pluginInfo = null;
        $source = 'github';

        if ($requestedSource === 'demo') {
            // User explicitly requested Demo ERP source
            $pluginInfo = $this->demoErp->getPluginInfo($pluginName);
            $source = 'demo';

            if (!$pluginInfo) {
                Tools::log()->error('plugin-not-found-source', [
                    '%plugin%' => $pluginName,
                    '%source%' => 'Demo ERP'
                ]);
                return;
            }
        } elseif ($requestedSource === 'github') {
            // User explicitly requested GitHub source
            $pluginInfo = $this->github->getPluginInfo($pluginName);
            $source = 'github';

            if (!$pluginInfo) {
                Tools::log()->error('plugin-not-found-source', [
                    '%plugin%' => $pluginName,
                    '%source%' => 'GitHub'
                ]);
                return;
            }
        } else {
            // No explicit source specified, try GitHub first then Demo ERP (backward compatibility)
            $pluginInfo = $this->github->getPluginInfo($pluginName);
            $source = 'github';

            if (!$pluginInfo) {
                // Try Demo ERP if not found in GitHub
                $pluginInfo = $this->demoErp->getPluginInfo($pluginName);
                $source = 'demo';
            }

            if (!$pluginInfo) {
                Tools::log()->error('plugin-not-found-any-source', ['%plugin%' => $pluginName]);
                return;
            }
        }

        // Validate compatibility
        $validation = PluginValidator::validatePlugin($pluginInfo, $currentFsVersion);
        if (!$validation['compatible']) {
            Tools::log()->warning('plugin-incompatible', [
                '%plugin%' => $pluginName,
                '%reason%' => $validation['message']
            ]);
            // Continue anyway if user confirmed
        }

        // Download and install from appropriate source
        $this->downloadAndInstallPlugin($pluginInfo, $source, $isUpdate);
    }

    /**
     * Downloads and installs a plugin from GitHub or Demo ERP
     *
     * @param array $pluginInfo Plugin information
     * @param string $source Source of the plugin ('github' or 'demo')
     * @param bool $isUpdate Whether this is an update operation
     */
    private function downloadAndInstallPlugin(array $pluginInfo, string $source, bool $isUpdate = false): void
    {
        $pluginName = $pluginInfo['name'];

        // Get download URL and downloader based on source
        if ($source === 'demo') {
            $downloadUrl = $this->demoErp->getDownloadUrl($pluginInfo);
            $downloader = $this->demoErp;
        } else {
            $downloadUrl = $this->github->getDownloadUrl($pluginInfo);
            $downloader = $this->github;
        }

        // Create temporary directory
        $tempDir = FS_FOLDER . '/MyFiles/tmp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir . '/' . $pluginName . '.zip';

        try {
            // Download the plugin from appropriate source
            $downloadResult = $downloader->downloadPlugin($downloadUrl, $tempFile);

            if (!$downloadResult) {
                Tools::log()->error('plugin-download-failed', [
                    '%plugin%' => $pluginName,
                    '%source%' => $source === 'demo' ? 'Demo ERP' : 'GitHub'
                ]);
                return;
            }

            // Verify file exists and has content
            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                Tools::log()->error('Downloaded file is empty or missing', ['%file%' => $tempFile]);
                return;
            }

            // Install plugin using FacturaScripts native system
            // Note: Plugins::add() and Plugins::enable() already log success/failure messages
            if (Plugins::add($tempFile, $pluginName)) {
                // Auto-enable the plugin
                Plugins::enable($pluginName);
            }
        } finally {
            // Clean up temporary file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
