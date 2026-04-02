<?php

/**
 * Plugin SolwedES - API Provisioning & Installations
 *
 * Endpoints:
 *   POST /ApiProvision?action=sync  { idcontacto }
 *     - Ensures wallet exists
 *     - Auto-creates missing accesos for active contracts
 *     - Updates lastactivity
 *
 *   GET  /ApiProvision?action=installations              → list all installations (admin)
 *   POST /ApiProvision?action=link  { id, idcontacto }   → link installation to contact
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Model\Wallet;
use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;
use FacturaScripts\Plugins\SolwedES\Lib\SolwedLogger;

class ApiProvision extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Provision';
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

        $token = $_SERVER['HTTP_TOKEN'] ?? '';
        $apiKey = Tools::settings('default', 'apikey', '');
        if (empty($token) || $token !== $apiKey) {
            $this->jsonResponse(['error' => 'Token inválido'], 401);
            return;
        }

        $action = $this->request->get('action', '');

        try {
            switch ($action) {
                case 'sync':
                    $this->handleSync();
                    break;
                case 'installations':
                    $this->handleListInstallations();
                    break;
                case 'link':
                    $this->handleLinkInstallation();
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action'], 400);
            }
        } catch (Exception $e) {
            SolwedLogger::error('ApiProvision error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Error interno del servidor'], 500);
        }
    }

    // ── Sync on login ───────────────────────────────────────────────────────

    private function handleSync(): void
    {
        $body = $this->getJsonBody();
        $idcontacto = (int)($body['idcontacto'] ?? 0);

        if ($idcontacto <= 0) {
            $this->jsonResponse(['error' => 'idcontacto required'], 400);
            return;
        }

        $contacto = new Contacto();
        if (!$contacto->loadFromCode($idcontacto)) {
            $this->jsonResponse(['error' => 'Contact not found'], 404);
            return;
        }

        $results = [
            'idcontacto' => $idcontacto,
            'wallet' => 'ok',
            'services' => 'ok',
            'created_accesos' => [],
            'warnings' => [],
        ];

        // 1. Ensure wallet
        $codcliente = $contacto->codcliente;
        if ($codcliente) {
            $results['wallet_balance'] = Wallet::getBalance($codcliente);
        } else {
            $results['wallet'] = 'skip_no_codcliente';
        }

        // 2. Sync contracts → accesos (auto-create missing)
        $contratos = (new Suscripcion())->all(
            [
                Where::isEqual('idcontacto', $idcontacto),
                Where::isNotEqual('estado', 'cancelado'),
                Where::isNotEqual('estado', 'vencido'),
            ],
            [], 0, 100
        );

        $accesos = (new AccesoServicio())->all(
            [Where::isEqual('idcontacto', $idcontacto)],
            [], 0, 100
        );

        $serviciosConAcceso = [];
        foreach ($accesos as $a) {
            $serviciosConAcceso[$a->idservicio] = $a;
        }

        foreach ($contratos as $contrato) {
            if (isset($serviciosConAcceso[$contrato->idservicio])) {
                // Acceso exists — reactivate if inactive
                $acceso = $serviciosConAcceso[$contrato->idservicio];
                if (!$acceso->activo && in_array($contrato->estado, ['activo', 'pendiente'])) {
                    $acceso->activo = true;
                    $acceso->save();
                    $results['created_accesos'][] = "Reactivated acceso for service {$contrato->idservicio}";
                }
                continue;
            }

            // No acceso exists — auto-create a placeholder
            $servicio = new Servicio();
            $servicioName = 'Servicio #' . $contrato->idservicio;
            if ($servicio->loadFromCode($contrato->idservicio)) {
                $servicioName = $servicio->nombre;
            }

            $nuevoAcceso = new AccesoServicio();
            $nuevoAcceso->idcontacto = $idcontacto;
            $nuevoAcceso->idservicio = $contrato->idservicio;
            $nuevoAcceso->url_acceso = $this->guessServiceUrl($servicio, $contacto);
            $nuevoAcceso->tipo_acceso = $this->guessServiceType($servicio);
            $nuevoAcceso->usuario = $contacto->email ?? '';
            $nuevoAcceso->activo = true;
            $nuevoAcceso->notas = 'Auto-creado por provisioning al login';
            $nuevoAcceso->created_by = 'system';

            if ($nuevoAcceso->save()) {
                $results['created_accesos'][] = "{$servicioName} ({$nuevoAcceso->tipo_acceso})";
                SolwedLogger::info("Auto-created acceso for contact {$idcontacto}, service {$contrato->idservicio} ({$servicioName})");
            }
        }

        // Deactivate accesos for cancelled/expired contracts
        $activeServiceIds = array_map(function ($c) { return $c->idservicio; }, $contratos);
        foreach ($accesos as $acceso) {
            if ($acceso->activo && !in_array($acceso->idservicio, $activeServiceIds)) {
                $acceso->activo = false;
                $acceso->save();
                $results['warnings'][] = "Deactivated acceso for expired service {$acceso->idservicio}";
            }
        }

        $results['contracts_count'] = count($contratos);
        $results['access_count'] = count($accesos) + count($results['created_accesos']);

        // 3. Update lastactivity
        $contacto->lastactivity = date('Y-m-d H:i:s');
        $contacto->save();

        $this->jsonResponse(['success' => true, 'data' => $results]);
    }

    /**
     * Guess the service URL based on the service category
     */
    private function guessServiceUrl($servicio, Contacto $contacto): string
    {
        if (!$servicio || !$servicio->categoria) {
            return 'https://solwed.es';
        }

        $cat = strtolower($servicio->categoria);

        // If the client has a domain, try to build URL from it
        // For now return placeholder — admin should configure the real URL
        switch ($cat) {
            case 'hosting':
            case 'wordpress':
                return 'https://pendiente-configurar.solwed.es';
            case 'email':
                return 'https://webmail.solwed.es';
            case 'erp':
                return 'https://pendiente-configurar.solwed.es';
            case 'web':
                return 'https://solwed.es/webs/pendiente';
            default:
                return 'https://solwed.es';
        }
    }

    /**
     * Guess the tipo_acceso based on the service category
     */
    private function guessServiceType($servicio): string
    {
        if (!$servicio || !$servicio->categoria) {
            return 'otro';
        }

        $cat = strtolower($servicio->categoria);
        $map = [
            'hosting' => 'wordpress',
            'wordpress' => 'wordpress',
            'erp' => 'facturascripts',
            'email' => 'otro',
            'web' => 'otro',
            'dominio' => 'otro',
            'seo' => 'otro',
            'marketing' => 'otro',
        ];

        return $map[$cat] ?? 'otro';
    }

    // ── Installations management (admin) ────────────────────────────────────

    /**
     * GET /ApiProvision?action=installations
     * List all accesos_servicios as "installations" for admin view
     */
    private function handleListInstallations(): void
    {
        $search = $this->request->get('search', '');

        $model = new AccesoServicio();
        $where = [];

        // No filtering by activo — show all for admin
        $accesos = $model->all($where, ['id' => 'DESC'], 0, 200);

        // Enrich with contact and service names
        $instalaciones = [];
        foreach ($accesos as $acceso) {
            $contacto = new Contacto();
            $contactoNombre = '';
            $codcliente = '';
            if ($contacto->loadFromCode($acceso->idcontacto)) {
                $contactoNombre = trim($contacto->nombre . ' ' . ($contacto->apellidos ?? ''));
                $codcliente = $contacto->codcliente ?? '';
            }

            $servicioNombre = '';
            $servicio = new Servicio();
            if ($servicio->loadFromCode($acceso->idservicio)) {
                $servicioNombre = $servicio->nombre;
            }

            $item = [
                'id' => $acceso->id,
                'nombre' => $servicioNombre ?: ('Servicio #' . $acceso->idservicio),
                'url' => $acceso->url_acceso,
                'tipo' => $acceso->tipo_acceso,
                'idcontacto' => $acceso->idcontacto,
                'codcliente' => $codcliente,
                'cliente_nombre' => $contactoNombre,
                'estado' => $acceso->activo ? 'activo' : 'inactivo',
                'ultima_sync' => $acceso->last_update,
            ];

            // Filter by search
            if ($search) {
                $s = strtolower($search);
                $haystack = strtolower($item['nombre'] . ' ' . $item['url'] . ' ' . $item['cliente_nombre']);
                if (strpos($haystack, $s) === false) {
                    continue;
                }
            }

            $instalaciones[] = $item;
        }

        $this->jsonResponse([
            'success' => true,
            'data' => [
                'instalaciones' => $instalaciones,
                'total' => count($instalaciones),
            ],
        ]);
    }

    /**
     * POST /ApiProvision?action=link  { id, idcontacto }
     * Link an installation (acceso) to a different contact
     */
    private function handleLinkInstallation(): void
    {
        $body = $this->getJsonBody();
        $id = (int)($body['id'] ?? 0);
        $idcontacto = (int)($body['idcontacto'] ?? 0);

        if ($id <= 0 || $idcontacto <= 0) {
            $this->jsonResponse(['error' => 'id and idcontacto required'], 400);
            return;
        }

        $acceso = new AccesoServicio();
        if (!$acceso->loadFromCode($id)) {
            $this->jsonResponse(['error' => 'Installation not found'], 404);
            return;
        }

        $contacto = new Contacto();
        if (!$contacto->loadFromCode($idcontacto)) {
            $this->jsonResponse(['error' => 'Contact not found'], 404);
            return;
        }

        $acceso->idcontacto = $idcontacto;
        if ($acceso->save()) {
            SolwedLogger::info("Linked installation {$id} to contact {$idcontacto}");
            $this->jsonResponse([
                'success' => true,
                'data' => [
                    'id' => $acceso->id,
                    'idcontacto' => $acceso->idcontacto,
                    'nombre' => 'Updated',
                ],
            ]);
        } else {
            $this->jsonResponse(['error' => 'Error saving'], 500);
        }
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
