<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\Controller;

/**
 * API for login device history.
 *
 * GET /ApiDevices?idcontacto=66 → list login devices from logs
 */
class ApiDevices extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Devices';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = ['https://app.solwed.es', 'https://erp.solwed.es', 'https://mind.solwed.es'];
        if (in_array($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        if (!$this->validateToken()) {
            return;
        }

        $idcontacto = (int) $this->request->get('idcontacto', 0);
        if ($idcontacto <= 0) {
            $this->jsonResponse(['error' => 'idcontacto required'], 400);
            return;
        }

        try {
            $db = new \FacturaScripts\Core\Base\DataBase();
            $db->connect();

            $sql = "SELECT id, ip, nick, message, context, time FROM logs "
                . "WHERE channel = 'portal-login' AND idcontacto = " . $idcontacto
                . " ORDER BY id DESC LIMIT 50";

            $rows = $db->select($sql);

            // Group by IP + device_name, keep most recent
            $devices = [];
            $seen = [];

            foreach ($rows as $row) {
                $ctx = json_decode($row['context'] ?? '{}', true) ?: [];
                $deviceName = $ctx['device_name'] ?? 'Dispositivo desconocido';
                $key = $row['ip'] . '|' . $deviceName;

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $devices[] = [
                        'id' => (int) $row['id'],
                        'device_name' => $deviceName,
                        'ip_address' => $row['ip'],
                        'last_used' => $row['time'],
                        'created_at' => $row['time'],
                    ];
                }
            }

            $this->jsonResponse(['success' => true, 'data' => $devices]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
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
