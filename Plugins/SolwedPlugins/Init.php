<?php

namespace FacturaScripts\Plugins\SolwedPlugins;

use FacturaScripts\Core\Controller\ApiRoot;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Plugins\SolwedPlugins\Lib\PluginCompatibilityOverride;

/**
 * Clase de inicialización para el plugin SolwedPlugins.
 * Gestiona la instalación, actualización y compatibilidad de plugins.
 * Aplica override para permitir plugins con min_version >= 2024.
 *
 * @package FacturaScripts\Plugins\SolwedPlugins
 */
class Init extends InitClass
{
    /**
     * Se ejecuta cada vez que carga FacturaScripts (si este plugin está activado)
     */
    public function init(): void
    {
        $this->applyCompatibilityOverride();
        $this->registerApiRoutes();
    }

    /**
     * Se ejecuta cuando se instala el plugin
     */
    public function update(): void
    {
        // Lógica de actualización
    }

    /**
     * Se ejecuta cuando se desinstala el plugin
     */
    public function uninstall(): void
    {
        // Lógica de desinstalación
    }

    /**
     * Declara la versión mínima de FacturaScripts requerida
     */
    public function version(): array
    {
        return [2024.0];
    }

    /**
     * Aplica el override de compatibilidad a todos los plugins cargados
     */
    /**
     * Registra endpoints API del plugin en el Kernel de FacturaScripts
     */
    private function registerApiRoutes(): void
    {
        Kernel::addRoute('/api/3/enviarDocumento', 'ApiEnviarDocumento', 10, 'solwed-enviar-doc');
        ApiRoot::addCustomResource('enviarDocumento');
    }

    private function applyCompatibilityOverride(): void
    {
        try {
            Plugins::load();
            $allPlugins = Plugins::list(true);

            foreach ($allPlugins as $plugin) {
                PluginCompatibilityOverride::applyOverride($plugin);
            }
        } catch (\Exception $e) {
            // Silenciar errores en override
        }
    }
}
