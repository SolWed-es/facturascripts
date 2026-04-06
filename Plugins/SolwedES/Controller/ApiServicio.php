<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Plugins\SolwedES\Lib\RedisReader;

/**
 * API for service catalog with business logic (pricing, categories).
 *
 * GET /ApiServicio?action=catalogo
 * GET /ApiServicio?action=get&id=X
 */
class ApiServicio extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Servicio';
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

        $action = $this->request->get('action', 'catalogo');

        try {
            switch ($action) {
                case 'catalogo':
                    $this->handleCatalogo();
                    break;
                case 'get':
                    $this->handleGet();
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action: ' . $action], 400);
            }
        } catch (Exception $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleCatalogo(): void
    {
        $soloComprables = (bool) $this->request->get('comprables', false);

        // Try Redis first
        $cached = RedisReader::getList('fs:servicios:catalogo');
        if (!empty($cached)) {
            if ($soloComprables) {
                foreach ($cached as &$group) {
                    $group['servicios'] = array_values(array_filter(
                        $group['servicios'] ?? [],
                        fn($s) => ($s['comprable'] ?? false)
                    ));
                }
                $cached = array_values(array_filter($cached, fn($g) => !empty($g['servicios'])));
            }
            $this->jsonResponse(['success' => true, 'source' => 'redis', 'data' => $cached]);
            return;
        }

        // Fallback to DB
        $grouped = Servicio::getServiciosAgrupadosPorCategoria();

        $result = [];
        foreach ($grouped as $categoria => $servicios) {
            $items = [];
            foreach ($servicios as $servicio) {
                if ($soloComprables && !$servicio->comprable) {
                    continue;
                }
                $items[] = $this->serializeWithPrecios($servicio);
            }
            if (!empty($items)) {
                $result[] = [
                    'categoria' => $categoria,
                    'servicios' => $items,
                ];
            }
        }

        $this->jsonResponse(['success' => true, 'source' => 'db', 'data' => $result]);
    }

    private function handleGet(): void
    {
        $id = (int) $this->request->get('id', 0);
        if ($id <= 0) {
            $this->jsonResponse(['error' => 'id required'], 400);
            return;
        }

        // Try Redis first
        $cached = RedisReader::get("fs:servicio:{$id}");
        if ($cached !== null) {
            $this->jsonResponse(['success' => true, 'source' => 'redis', 'data' => $cached]);
            return;
        }

        // Fallback to DB
        $servicio = new Servicio();
        if (!$servicio->load($id)) {
            $this->jsonResponse(['error' => 'Service not found'], 404);
            return;
        }

        $this->jsonResponse(['success' => true, 'source' => 'db', 'data' => $this->serializeWithPrecios($servicio)]);
    }

    private function serializeWithPrecios(Servicio $s): array
    {
        $precios = [];
        foreach ($s->getPrecios(true) as $p) {
            $precios[] = [
                'id' => $p->id,
                'nombre' => $p->nombre ?? '',
                'precio' => (float) $p->precio,
                'periodo' => $p->periodo ?? 'month',
                'stripe_price_id' => $p->stripe_price_id ?? '',
                'activo' => (bool) ($p->activo ?? true),
            ];
        }

        return [
            'id' => $s->id,
            'nombre' => $s->nombre,
            'descripcion' => $s->descripcion,
            'categoria' => $s->categoria,
            'icono' => $s->icono,
            'color' => $s->color,
            'imagen' => $s->imagen,
            'activo' => (bool) $s->activo,
            'comprable' => (bool) $s->comprable,
            'orden' => (int) $s->orden,
            'genera_suscripcion' => (bool) $s->genera_suscripcion,
            'caracteristicas' => $s->getCaracteristicas(),
            'precios' => $precios,
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

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
