<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\SolwedES\Model\DireccionEnvio;

/**
 * API endpoints for shipping address actions:
 * - PUT /ApiDireccionEnvio?action=default&id=X
 */
class ApiDireccionEnvio extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Direccion Envio';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $action = $this->request->get('action', '');

        try {
            switch ($action) {
                case 'default':
                    $this->handleSetDefault();
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action: ' . $action], 400);
            }
        } catch (Exception $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleSetDefault(): void
    {
        $id = (int)$this->request->get('id', 0);
        if ($id <= 0) {
            $this->jsonResponse(['error' => 'id required'], 400);
            return;
        }

        $direccion = new DireccionEnvio();
        if (!$direccion->load($id)) {
            $this->jsonResponse(['error' => 'Address not found'], 404);
            return;
        }

        if ($direccion->setAsDefault()) {
            $this->jsonResponse([
                'success' => true,
                'data' => [
                    'id' => $direccion->id,
                    'label' => $direccion->label,
                    'is_default' => true,
                ],
            ]);
        } else {
            $this->jsonResponse(['error' => 'Failed to set as default'], 500);
        }
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
