<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;

/**
 * API for service access management (credentials, SSO tokens).
 *
 * GET    /ApiAccesoServicio?action=list&idcontacto=X
 * GET    /ApiAccesoServicio?action=get&id=X
 * POST   /ApiAccesoServicio?action=create
 * PUT    /ApiAccesoServicio?action=update&id=X
 * DELETE /ApiAccesoServicio?action=delete&id=X
 * POST   /ApiAccesoServicio?action=sso&id=X
 */
class ApiAccesoServicio extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Acceso Servicio';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        if (!$this->validateToken()) {
            return;
        }

        $action = $this->request->get('action', '');

        try {
            switch ($action) {
                case 'list':
                    $this->handleList();
                    break;
                case 'get':
                    $this->handleGet();
                    break;
                case 'create':
                    $this->handleCreate();
                    break;
                case 'update':
                    $this->handleUpdate();
                    break;
                case 'delete':
                    $this->handleDelete();
                    break;
                case 'sso':
                    $this->handleSSO();
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action: ' . $action], 400);
            }
        } catch (Exception $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleList(): void
    {
        $idcontacto = (int) $this->request->get('idcontacto', 0);
        if ($idcontacto <= 0) {
            $this->jsonResponse(['error' => 'idcontacto required'], 400);
            return;
        }

        $items = AccesoServicio::getAllByCliente($idcontacto);
        $result = array_map(fn($a) => $this->serialize($a), $items);
        $this->jsonResponse(['success' => true, 'data' => $result]);
    }

    private function handleGet(): void
    {
        $id = (int) $this->request->get('id', 0);
        $acceso = new AccesoServicio();
        if ($id <= 0 || !$acceso->load($id)) {
            $this->jsonResponse(['error' => 'Access not found'], 404);
            return;
        }
        $this->jsonResponse(['success' => true, 'data' => $this->serialize($acceso)]);
    }

    private function handleCreate(): void
    {
        $body = $this->getJsonBody();

        $required = ['idcontacto', 'idservicio', 'tipo_acceso'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                $this->jsonResponse(['error' => "$field required"], 400);
                return;
            }
        }

        $acceso = new AccesoServicio();
        $acceso->idcontacto = (int) $body['idcontacto'];
        $acceso->idservicio = (int) $body['idservicio'];
        $acceso->tipo_acceso = $body['tipo_acceso'];
        $acceso->url_acceso = $body['url_acceso'] ?? '';
        $acceso->usuario = $body['usuario'] ?? '';
        $acceso->api_key = $body['api_key'] ?? '';
        $acceso->activo = (bool) ($body['activo'] ?? true);

        if (!empty($body['password'])) {
            $acceso->password_hash = password_hash($body['password'], PASSWORD_BCRYPT);
        }

        if ($acceso->save()) {
            $this->jsonResponse(['success' => true, 'data' => $this->serialize($acceso)], 201);
        } else {
            $this->jsonResponse(['error' => 'Failed to create access'], 500);
        }
    }

    private function handleUpdate(): void
    {
        $id = (int) $this->request->get('id', 0);
        $acceso = new AccesoServicio();
        if ($id <= 0 || !$acceso->load($id)) {
            $this->jsonResponse(['error' => 'Access not found'], 404);
            return;
        }

        $body = $this->getJsonBody();
        $updatable = ['url_acceso', 'tipo_acceso', 'usuario', 'api_key', 'activo'];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $body)) {
                $acceso->$field = $body[$field];
            }
        }

        if (!empty($body['password'])) {
            $acceso->password_hash = password_hash($body['password'], PASSWORD_BCRYPT);
        }

        if ($acceso->save()) {
            $this->jsonResponse(['success' => true, 'data' => $this->serialize($acceso)]);
        } else {
            $this->jsonResponse(['error' => 'Failed to update access'], 500);
        }
    }

    private function handleDelete(): void
    {
        $id = (int) $this->request->get('id', 0);
        $acceso = new AccesoServicio();
        if ($id <= 0 || !$acceso->load($id)) {
            $this->jsonResponse(['error' => 'Access not found'], 404);
            return;
        }

        if ($acceso->delete()) {
            $this->jsonResponse(['success' => true]);
        } else {
            $this->jsonResponse(['error' => 'Failed to delete access'], 500);
        }
    }

    private function handleSSO(): void
    {
        $id = (int) $this->request->get('id', 0);
        $acceso = new AccesoServicio();
        if ($id <= 0 || !$acceso->load($id)) {
            $this->jsonResponse(['error' => 'Access not found'], 404);
            return;
        }

        if (!$acceso->activo) {
            $this->jsonResponse(['error' => 'Access is disabled'], 403);
            return;
        }

        if ($acceso->estaBloqueado()) {
            $this->jsonResponse(['error' => 'Access is locked due to failed attempts'], 423);
            return;
        }

        $token = $acceso->generateSSOToken();
        $acceso->save();

        $this->jsonResponse([
            'success' => true,
            'sso_token' => $token,
            'url' => $acceso->url_acceso,
            'expires_in' => 900,
        ]);
    }

    private function serialize(AccesoServicio $a): array
    {
        return [
            'id' => $a->id,
            'idcontacto' => $a->idcontacto,
            'idservicio' => $a->idservicio,
            'url_acceso' => $a->url_acceso,
            'tipo_acceso' => $a->tipo_acceso,
            'usuario' => $a->usuario,
            'api_key' => $a->api_key,
            'activo' => (bool) $a->activo,
            'last_access' => $a->last_access,
            'creation_date' => $a->creation_date,
        ];
    }

    private function validateToken(): bool
    {
        $token = $_SERVER['HTTP_TOKEN'] ?? '';
        if (empty($token)) {
            $this->jsonResponse(['error' => 'Token required'], 401);
            return false;
        }
        $db = new \FacturaScripts\Core\Base\DataBase();
        $db->connect();
        $sql = "SELECT 1 FROM api_keys WHERE apikey = " . $db->var2str($token) . " AND enabled = true LIMIT 1";
        $result = $db->select($sql);
        if (!empty($result)) {
            return true;
        }
        $this->jsonResponse(['error' => 'Token inválido'], 401);
        return false;
    }

    private function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return $this->request->request->all();
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
