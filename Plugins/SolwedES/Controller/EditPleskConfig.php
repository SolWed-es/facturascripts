<?php

/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Controlador para editar configuración de servidores Plesk
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Plugins\SolwedES\Model\PleskConfig;
use FacturaScripts\Core\Tools;

/**
 * Controlador para editar configuración de Plesk
 */
class EditPleskConfig extends EditController
{
    /**
     * Retorna el nombre del modelo
     *
     * @return string
     */
    public function getModelClassName(): string
    {
        return 'PleskConfig';
    }

    /**
     * Retorna los datos básicos de la página
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'plesk-config';
        $data['icon'] = 'fa-solid fa-server';
        $data['showonmenu'] = false;

        return $data;
    }

    /**
     * Ejecuta la lógica adicional después de cargar los datos
     *
     * @param string $action
     * @return bool
     */
    protected function execAfterAction($action)
    {
        if ($action === 'test-connection') {
            return $this->testConnectionAction();
        }

        return parent::execAfterAction($action);
    }

    /**
     * Prueba la conexión al servidor Plesk
     *
     * @return bool
     */
    protected function testConnectionAction(): bool
    {
        $config = $this->getModel();

        if (!$config || empty($config->server_url) || empty($config->api_token)) {
            Tools::log()->warning('plesk-config-incomplete');
            return true;
        }

        // Crear cliente API temporal
        $apiClient = new \FacturaScripts\Plugins\SolwedES\Lib\PleskApiClient($config);

        // Probar conexión
        $success = $apiClient->testConnection();

        if ($success) {
            $config->connection_status = 'success';
            $config->last_connection_test = date('Y-m-d H:i:s');
            $config->save();

            Tools::log()->notice('connection-test-success');
        } else {
            $config->connection_status = 'failed';
            $config->last_connection_test = date('Y-m-d H:i:s');
            $config->save();

            Tools::log()->error('connection-test-failed');
        }

        return true;
    }

    /**
     * Carga los datos de la vista
     *
     * @param string $viewName
     * @param mixed $view
     */
    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'EditPleskConfig':
                parent::loadData($viewName, $view);

                // Añadir botón de prueba de conexión
                $this->addButton($viewName, [
                    'action' => 'test-connection',
                    'color' => 'info',
                    'icon' => 'fa-solid fa-plug',
                    'label' => 'test-connection',
                    'type' => 'action'
                ]);
                break;
        }
    }
}
