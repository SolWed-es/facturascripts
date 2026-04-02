<?php

/**
 * Plugin SolwedES - API Push Subscriptions
 *
 * Stores Web Push subscriptions. Actual sending is done by the Next.js app.
 *
 * Endpoints:
 *   POST /ApiPush?action=subscribe       { idcontacto, endpoint, keys, user_agent }
 *   POST /ApiPush?action=unsubscribe     { endpoint }
 *   GET  /ApiPush?action=subscriptions&idcontacto=X → list active subscriptions
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\SolwedES\Lib\SolwedLogger;

class ApiPush extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Push';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
                case 'subscribe':
                    $this->handleSubscribe();
                    break;
                case 'unsubscribe':
                    $this->handleUnsubscribe();
                    break;
                case 'subscriptions':
                    $this->handleListSubscriptions();
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action'], 400);
            }
        } catch (Exception $e) {
            SolwedLogger::error('ApiPush error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Error interno del servidor'], 500);
        }
    }

    private function handleSubscribe(): void
    {
        $body = $this->getJsonBody();
        $idcontacto = (int)($body['idcontacto'] ?? 0);
        $endpoint = $body['endpoint'] ?? '';
        $keys = $body['keys'] ?? '';
        $userAgent = $body['user_agent'] ?? '';

        if ($idcontacto <= 0 || empty($endpoint)) {
            $this->jsonResponse(['error' => 'idcontacto and endpoint required'], 400);
            return;
        }

        $db = $this->dataBase;
        $now = date('Y-m-d H:i:s');

        // Upsert
        $existing = $db->select(
            "SELECT id FROM solwedes_push_subscriptions WHERE endpoint = " . $db->var2str($endpoint) . " LIMIT 1"
        );

        if (!empty($existing)) {
            $db->exec(
                "UPDATE solwedes_push_subscriptions SET idcontacto = " . (int)$idcontacto
                . ", keys = " . $db->var2str($keys)
                . ", user_agent = " . $db->var2str($userAgent)
                . ", activo = true"
                . ", last_used = " . $db->var2str($now)
                . " WHERE endpoint = " . $db->var2str($endpoint)
            );
        } else {
            $db->exec(
                "INSERT INTO solwedes_push_subscriptions (idcontacto, endpoint, keys, user_agent, activo, creation_date, last_used)"
                . " VALUES (" . (int)$idcontacto
                . ", " . $db->var2str($endpoint)
                . ", " . $db->var2str($keys)
                . ", " . $db->var2str($userAgent)
                . ", true"
                . ", " . $db->var2str($now)
                . ", " . $db->var2str($now) . ")"
            );
        }

        $this->jsonResponse(['success' => true]);
    }

    private function handleUnsubscribe(): void
    {
        $body = $this->getJsonBody();
        $endpoint = $body['endpoint'] ?? '';

        if (empty($endpoint)) {
            $this->jsonResponse(['error' => 'endpoint required'], 400);
            return;
        }

        $this->dataBase->exec(
            "UPDATE solwedes_push_subscriptions SET activo = false WHERE endpoint = " . $this->dataBase->var2str($endpoint)
        );

        $this->jsonResponse(['success' => true]);
    }

    private function handleListSubscriptions(): void
    {
        $idcontacto = (int)$this->request->get('idcontacto', '0');
        if ($idcontacto <= 0) {
            $this->jsonResponse(['error' => 'idcontacto required'], 400);
            return;
        }

        $subs = $this->dataBase->select(
            "SELECT id, idcontacto, endpoint, keys, user_agent FROM solwedes_push_subscriptions"
            . " WHERE idcontacto = " . $idcontacto . " AND activo = true"
        );

        $this->jsonResponse(['success' => true, 'data' => $subs ?: []]);
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

    private function validateToken(): bool
    {
        $token = $_SERVER['HTTP_TOKEN'] ?? '';
        if (empty($token)) {
            $this->jsonResponse(['error' => 'Token required'], 401);
            return false;
        }
        $db = new \FacturaScripts\Core\Base\DataBase();
        $db->connect();
        $result = $db->select("SELECT 1 FROM api_keys WHERE apikey = " . $db->var2str($token) . " AND enabled = true LIMIT 1");
        if (!empty($result)) {
            return true;
        }
        $this->jsonResponse(['error' => 'Token inválido'], 401);
        return false;
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
