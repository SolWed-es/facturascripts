<?php
/**
 * Plugin SolwedES - Controlador de edición de accesos a servicios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Lib\ServiceAccessManager;

/**
 * Controlador para gestionar accesos a servicios con SSO
 */
class EditAccesoServicio extends EditController
{
    /**
     * Nombre del modelo a editar
     *
     * @return string
     */
    public function getModelClassName(): string
    {
        return 'AccesoServicio';
    }

    /**
     * Título de la página
     *
     * @return string
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'access-service';
        $data['icon'] = 'fa-solid fa-right-to-bracket';

        return $data;
    }

    /**
     * Ejecuta acciones antes del procesamiento estándar
     *
     * @param string $action
     * @return bool
     */
    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'generate-password':
                return $this->generatePasswordAction();

            case 'test-access':
                return $this->testAccessAction();

            case 'generate-sso-token':
                return $this->generateSSOTokenAction();

            default:
                return parent::execPreviousAction($action);
        }
    }

    /**
     * Genera una contraseña aleatoria segura
     *
     * @return bool
     */
    protected function generatePasswordAction(): bool
    {
        $this->setTemplate(false);

        $password = ServiceAccessManager::generateSecurePassword(16);

        $this->response->setContent(json_encode([
            'success' => true,
            'password' => $password
        ]));

        return false;
    }

    /**
     * Prueba la conectividad a la URL del servicio
     *
     * @return bool
     */
    protected function testAccessAction(): bool
    {
        $this->setTemplate(false);

        $url = $this->request->request->get('url');

        if (empty($url)) {
            $this->response->setContent(json_encode([
                'success' => false,
                'error' => Tools::lang()->trans('url-required')
            ]));
            return false;
        }

        $result = ServiceAccessManager::testServiceUrl($url);

        $this->response->setContent(json_encode([
            'success' => $result['reachable'],
            'status_code' => $result['status_code'],
            'error' => $result['error']
        ]));

        return false;
    }

    /**
     * Genera un token SSO de prueba
     *
     * @return bool
     */
    protected function generateSSOTokenAction(): bool
    {
        $this->setTemplate(false);

        $code = $this->request->request->get('code');
        $model = $this->getModel();

        if (!$model->load($code)) {
            $this->response->setContent(json_encode([
                'success' => false,
                'error' => Tools::lang()->trans('record-not-found')
            ]));
            return false;
        }

        $ssoUrl = ServiceAccessManager::generatePortalSSOUrl($model);

        $this->response->setContent(json_encode([
            'success' => true,
            'url' => $ssoUrl,
            'expires_at' => $model->token_expires_at
        ]));

        return false;
    }

    /**
     * Carga los datos antes de mostrar la vista
     *
     * @param string $viewName
     * @param mixed $view
     */
    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);

        // Si es un registro nuevo, establecer valores por defecto
        if (!$this->getModel()->exists()) {
            $this->getModel()->activo = true;
            $this->getModel()->max_intentos_sso = 5;
            $this->getModel()->tipo_acceso = 'wordpress';
        }
    }
}
