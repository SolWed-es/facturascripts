<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Controlador para listar configuraciones de servidores Plesk
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * Controlador para listar configuraciones de Plesk
 */
class ListPleskConfig extends ListController
{
    /**
     * Retorna los datos básicos de la página
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'plesk-servers';
        $data['icon'] = 'fa-solid fa-server';

        return $data;
    }

    /**
     * Carga los datos de las vistas
     *
     * @param string $viewName
     * @param mixed $view
     */
    protected function createViews()
    {
        $this->createViewPleskConfig();
    }

    /**
     * Crea la vista de configuraciones Plesk
     *
     * @param string $viewName
     */
    protected function createViewPleskConfig(string $viewName = 'ListPleskConfig')
    {
        $this->addView($viewName, 'PleskConfig', 'plesk-servers', 'fa-solid fa-server');
        $this->addOrderBy($viewName, ['id'], 'id', 2);
        $this->addOrderBy($viewName, ['server_name'], 'server-name');
        $this->addOrderBy($viewName, ['last_connection_test'], 'last-connection-test');

        // Filtros
        $this->addFilterCheckbox($viewName, 'activo', 'active', 'activo');

        $apiTypes = [
            ['code' => 'rest', 'description' => 'REST API'],
            ['code' => 'xmlrpc', 'description' => 'XML-RPC']
        ];
        $this->addFilterSelect($viewName, 'api_type', 'api-type', 'api_type', $apiTypes);

        $connectionStatus = [
            ['code' => 'success', 'description' => 'connection-success'],
            ['code' => 'failed', 'description' => 'connection-failed'],
            ['code' => 'untested', 'description' => 'connection-untested']
        ];
        $this->addFilterSelect($viewName, 'connection_status', 'connection-status', 'connection_status', $connectionStatus);

        // Búsqueda
        $this->addSearchFields($viewName, ['server_name', 'server_url']);
    }
}
