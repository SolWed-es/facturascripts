<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Lib\StripeSubscriptionManager;
use FacturaScripts\Plugins\SolwedES\Lib\SolwedLogger;

/**
 * API endpoints for subscription management:
 * - GET  /ApiSuscripcion?action=list&idcontacto=X
 * - GET  /ApiSuscripcion?action=get&id=X
 * - POST /ApiSuscripcion?action=create
 * - PUT  /ApiSuscripcion?action=update&id=X
 * - PUT  /ApiSuscripcion?action=cancelar&id=X
 */
class ApiSuscripcion extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Suscripcion';
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
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

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
                case 'cancelar':
                    $this->handleCancelar();
                    break;
                case 'activate':
                    $this->handleActivate();
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action: ' . $action], 400);
            }
        } catch (Exception $e) {
            SolwedLogger::stripe('ApiSuscripcion error: ' . $e->getMessage());
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleList(): void
    {
        $idcontacto = (int) $this->request->get('idcontacto', 0);
        $estado = $this->request->get('estado', '');

        if ($idcontacto > 0) {
            if ($estado === 'activo') {
                $items = Suscripcion::getActivosByContacto($idcontacto);
            } else {
                $suscripcion = new Suscripcion();
                $where = [new DataBaseWhere('idcontacto', $idcontacto)];
                if (!empty($estado)) {
                    $where[] = new DataBaseWhere('estado', $estado);
                }
                $items = $suscripcion->all($where, ['id' => 'DESC'], 0, 100);
            }
        } else {
            $suscripcion = new Suscripcion();
            $where = [];
            if (!empty($estado)) {
                $where[] = new DataBaseWhere('estado', $estado);
            }
            $items = $suscripcion->all($where, ['id' => 'DESC'], 0, 100);
        }

        $result = array_map(fn($s) => $this->serializeSuscripcion($s), $items);
        $this->jsonResponse(['success' => true, 'data' => $result]);
    }

    private function handleGet(): void
    {
        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            $this->jsonResponse(['error' => 'id required'], 400);
            return;
        }

        $suscripcion = new Suscripcion();
        if (!$suscripcion->load($id)) {
            $this->jsonResponse(['error' => 'Suscripcion not found'], 404);
            return;
        }

        $this->jsonResponse(['success' => true, 'data' => $this->serializeSuscripcion($suscripcion)]);
    }

    private function handleCreate(): void
    {
        $body = $this->getJsonBody();

        $required = ['idcontacto', 'idservicio'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                $this->jsonResponse(['error' => "$field required"], 400);
                return;
            }
        }

        $suscripcion = new Suscripcion();
        $suscripcion->idcontacto = (int) $body['idcontacto'];
        $suscripcion->idservicio = (int) $body['idservicio'];
        $suscripcion->estado = $body['estado'] ?? Suscripcion::ESTADO_PENDIENTE;
        $suscripcion->metodo_pago = $body['metodo_pago'] ?? Suscripcion::METODO_MANUAL;
        $suscripcion->importe = (float) ($body['importe'] ?? 0);
        $suscripcion->fecha_inicio = $body['fecha_inicio'] ?? date('Y-m-d');
        $suscripcion->auto_renovar = (bool) ($body['auto_renovar'] ?? true);
        $suscripcion->dominio = $body['dominio'] ?? '';
        $suscripcion->servidor = $body['servidor'] ?? '';
        $suscripcion->intervalo = $body['intervalo'] ?? 'month';
        $suscripcion->referencia_externa = $body['referencia_externa'] ?? '';
        $suscripcion->stripe_customer_id = $body['stripe_customer_id'] ?? '';
        $suscripcion->stripe_price_id = $body['stripe_price_id'] ?? '';

        if ($suscripcion->save()) {
            $this->jsonResponse(['success' => true, 'data' => $this->serializeSuscripcion($suscripcion)], 201);
        } else {
            $this->jsonResponse(['error' => 'Failed to create subscription'], 500);
        }
    }

    private function handleUpdate(): void
    {
        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            $this->jsonResponse(['error' => 'id required'], 400);
            return;
        }

        $suscripcion = new Suscripcion();
        if (!$suscripcion->load($id)) {
            $this->jsonResponse(['error' => 'Suscripcion not found'], 404);
            return;
        }

        $body = $this->getJsonBody();
        $updatable = [
            'estado', 'metodo_pago', 'importe', 'fecha_vencimiento', 'fecha_proximo_pago',
            'auto_renovar', 'dominio', 'servidor', 'intervalo', 'referencia_externa',
            'stripe_customer_id', 'stripe_price_id', 'cancel_at_period_end',
            'provisioning_status',
        ];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $body)) {
                $suscripcion->$field = $body[$field];
            }
        }

        if ($suscripcion->save()) {
            $this->jsonResponse(['success' => true, 'data' => $this->serializeSuscripcion($suscripcion)]);
        } else {
            $this->jsonResponse(['error' => 'Failed to update subscription'], 500);
        }
    }

    private function serializeSuscripcion(Suscripcion $s): array
    {
        return [
            'id' => $s->id,
            'idcontacto' => $s->idcontacto,
            'idservicio' => $s->idservicio,
            'estado' => $s->estado,
            'fecha_inicio' => $s->fecha_inicio,
            'fecha_vencimiento' => $s->fecha_vencimiento,
            'fecha_ultimo_pago' => $s->fecha_ultimo_pago,
            'fecha_proximo_pago' => $s->fecha_proximo_pago,
            'metodo_pago' => $s->metodo_pago,
            'referencia_externa' => $s->referencia_externa,
            'auto_renovar' => (bool) $s->auto_renovar,
            'importe' => (float) $s->importe,
            'dominio' => $s->dominio,
            'servidor' => $s->servidor,
            'moneda' => $s->moneda,
            'intervalo' => $s->intervalo,
            'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
            'canceled_at' => $s->canceled_at,
            'provisioning_status' => $s->provisioning_status,
            'stripe_customer_id' => $s->stripe_customer_id,
            'stripe_price_id' => $s->stripe_price_id,
            'creation_date' => $s->creation_date,
        ];
    }

    private function handleActivate(): void
    {
        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            $this->jsonResponse(['error' => 'id required'], 400);
            return;
        }

        // Direct DB update — bypasses model validation that blocks estado changes
        $db = new \FacturaScripts\Core\Base\DataBase();
        $db->connect();
        $result = $db->exec("UPDATE solwedes_suscripciones SET estado = 'activa', last_update = NOW() WHERE id = " . $id);

        if ($result) {
            $this->jsonResponse(['success' => true, 'id' => $id, 'estado' => 'activa']);
        } else {
            $this->jsonResponse(['error' => 'Failed to activate'], 500);
        }
    }

    private function handleCancelar(): void
    {
        $id = (int)$this->request->get('id', 0);
        if ($id <= 0) {
            $this->jsonResponse(['error' => 'id required'], 400);
            return;
        }

        $suscripcion = new Suscripcion();
        if (!$suscripcion->load($id)) {
            $this->jsonResponse(['error' => 'Suscripcion not found'], 404);
            return;
        }

        $body = $this->getJsonBody();
        $immediately = (bool)($body['immediately'] ?? false);

        // If Stripe subscription, cancel in Stripe too
        if ($suscripcion->metodo_pago === Suscripcion::METODO_STRIPE && !empty($suscripcion->referencia_externa)) {
            $result = StripeSubscriptionManager::cancelSubscription($suscripcion->referencia_externa, $immediately);
            if (!$result) {
                $this->jsonResponse(['error' => 'Failed to cancel Stripe subscription'], 500);
                return;
            }
        }

        if ($immediately) {
            $suscripcion->estado = Suscripcion::ESTADO_CANCELADO;
            $suscripcion->canceled_at = date('Y-m-d H:i:s');
        } else {
            $suscripcion->cancel_at_period_end = true;
        }

        if ($suscripcion->save()) {
            $this->jsonResponse([
                'success' => true,
                'suscripcion' => [
                    'id' => $suscripcion->id,
                    'estado' => $suscripcion->estado,
                    'cancel_at_period_end' => $suscripcion->cancel_at_period_end,
                    'canceled_at' => $suscripcion->canceled_at,
                ],
            ]);
        } else {
            $this->jsonResponse(['error' => 'Failed to save subscription'], 500);
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
