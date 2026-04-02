<?php

/**
 * Plugin SolwedES - API Wallet (Wcoins)
 *
 * Endpoints:
 * - GET  /ApiWallet?action=balance&codcliente=XXX       → saldo actual
 * - GET  /ApiWallet?action=history&codcliente=XXX&page=1 → historial paginado
 * - POST /ApiWallet?action=recharge                      → recarga (admin/sistema)
 * - POST /ApiWallet?action=spend                         → gasto
 * - POST /ApiWallet?action=assign                        → asignar saldo (admin)
 * - GET  /ApiWallet?action=preferences&codcliente=XXX    → preferencia de pago
 * - PUT  /ApiWallet?action=preferences                   → guardar preferencia
 *
 * Auth: Token header (FS API key)
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Model\Wallet;
use FacturaScripts\Plugins\SolwedES\Lib\SolwedLogger;

class ApiWallet extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Wallet';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        // Authenticate via Token header (same as other FS API endpoints)
        $token = $_SERVER['HTTP_TOKEN'] ?? '';
        $apiKey = Tools::settings('default', 'apikey', '');
        if (empty($token) || $token !== $apiKey) {
            $this->jsonResponse(['error' => 'Token inválido'], 401);
            return;
        }

        $action = $this->request->get('action', '');

        try {
            switch ($action) {
                case 'balance':
                    $this->handleBalance();
                    break;
                case 'history':
                    $this->handleHistory();
                    break;
                case 'recharge':
                    $this->handleRecharge();
                    break;
                case 'spend':
                    $this->handleSpend();
                    break;
                case 'assign':
                    $this->handleAssign();
                    break;
                case 'preferences':
                    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                        $this->handleSetPreference();
                    } else {
                        $this->handleGetPreference();
                    }
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action: ' . $action], 400);
            }
        } catch (Exception $e) {
            SolwedLogger::error('ApiWallet error: ' . $e->getMessage());
            $this->jsonResponse(['error' => 'Error interno del servidor'], 500);
        }
    }

    /**
     * GET balance — returns current Wcoin balance for a customer
     */
    private function handleBalance(): void
    {
        $codcliente = $this->request->get('codcliente', '');
        if (empty($codcliente)) {
            $this->jsonResponse(['error' => 'codcliente required'], 400);
            return;
        }

        $saldo = Wallet::getBalance($codcliente);
        $this->jsonResponse(['saldo' => $saldo]);
    }

    /**
     * GET history — returns paginated transaction history
     */
    private function handleHistory(): void
    {
        $codcliente = $this->request->get('codcliente', '');
        if (empty($codcliente)) {
            $this->jsonResponse(['error' => 'codcliente required'], 400);
            return;
        }

        $page = max(1, (int)$this->request->get('page', '1'));
        $limit = min(50, max(1, (int)$this->request->get('limit', '20')));
        $offset = ($page - 1) * $limit;

        $items = Wallet::getHistory($codcliente, $offset, $limit);
        $total = Wallet::countByClient($codcliente);

        $this->jsonResponse([
            'data' => array_map(function (Wallet $tx) {
                return [
                    'id' => $tx->id,
                    'tipo' => $tx->tipo,
                    'cantidad' => $tx->cantidad,
                    'saldo_resultante' => $tx->saldo_resultante,
                    'concepto' => $tx->concepto,
                    'created_at' => $tx->creation_date,
                ];
            }, $items),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => ceil($total / $limit),
            ],
        ]);
    }

    /**
     * POST recharge — add Wcoins to a customer balance
     */
    private function handleRecharge(): void
    {
        $body = $this->getJsonBody();
        $codcliente = $body['codcliente'] ?? '';
        $wcoins = (float)($body['wcoins'] ?? 0);
        $concepto = $body['concepto'] ?? 'Recarga Wcoins';
        $referencia = $body['referencia_externa'] ?? null;

        if (empty($codcliente) || $wcoins <= 0) {
            $this->jsonResponse(['error' => 'codcliente and wcoins > 0 required'], 400);
            return;
        }

        $tx = Wallet::addTransaction($codcliente, Wallet::TIPO_RECARGA, $wcoins, $concepto, $referencia);
        if (!$tx) {
            $this->jsonResponse(['error' => 'Error al crear transacción'], 500);
            return;
        }

        SolwedLogger::info("Wallet recharge: {$codcliente} +{$wcoins} Wcoins");
        $this->jsonResponse([
            'success' => true,
            'saldo' => $tx->saldo_resultante,
            'transaccion' => $tx->id,
        ]);
    }

    /**
     * POST spend — deduct Wcoins from a customer balance
     */
    private function handleSpend(): void
    {
        $body = $this->getJsonBody();
        $codcliente = $body['codcliente'] ?? '';
        $amount = (float)($body['amount'] ?? 0);
        $concepto = $body['concepto'] ?? 'Gasto Wcoins';
        $referencia = $body['referencia_externa'] ?? null;
        $metadata = $body['metadata'] ?? null;

        if (empty($codcliente) || $amount <= 0) {
            $this->jsonResponse(['error' => 'codcliente and amount > 0 required'], 400);
            return;
        }

        $tx = Wallet::addTransaction($codcliente, Wallet::TIPO_GASTO, $amount, $concepto, $referencia, $metadata);
        if (!$tx) {
            $this->jsonResponse(['error' => 'Saldo insuficiente'], 400);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'saldo' => $tx->saldo_resultante,
        ]);
    }

    /**
     * POST assign — admin assign balance (regalo/reembolso)
     */
    private function handleAssign(): void
    {
        $body = $this->getJsonBody();
        $codcliente = $body['codcliente'] ?? '';
        $wcoins = (float)($body['wcoins'] ?? 0);
        $concepto = $body['concepto'] ?? 'Asignación admin';
        $tipo = $body['tipo'] ?? Wallet::TIPO_REGALO;

        if (empty($codcliente) || $wcoins <= 0) {
            $this->jsonResponse(['error' => 'codcliente and wcoins > 0 required'], 400);
            return;
        }

        if (!in_array($tipo, [Wallet::TIPO_REGALO, Wallet::TIPO_REEMBOLSO])) {
            $tipo = Wallet::TIPO_REGALO;
        }

        $tx = Wallet::addTransaction($codcliente, $tipo, $wcoins, $concepto);
        if (!$tx) {
            $this->jsonResponse(['error' => 'Error al asignar saldo'], 500);
            return;
        }

        SolwedLogger::info("Wallet assign: {$codcliente} +{$wcoins} ({$tipo})");
        $this->jsonResponse([
            'success' => true,
            'saldo' => $tx->saldo_resultante,
        ]);
    }

    /**
     * GET preferences — get wallet payment preference
     * Stored in contacto.observaciones as JSON flag (lightweight approach)
     */
    private function handleGetPreference(): void
    {
        $codcliente = $this->request->get('codcliente', '');
        if (empty($codcliente)) {
            $this->jsonResponse(['error' => 'codcliente required'], 400);
            return;
        }

        // Default: not preferring Wcoins
        $preferWcoins = (bool)Tools::settings('solwed', 'wallet_prefer_' . $codcliente, false);
        $this->jsonResponse(['preferWcoins' => $preferWcoins]);
    }

    /**
     * PUT preferences — set wallet payment preference
     */
    private function handleSetPreference(): void
    {
        $body = $this->getJsonBody();
        $codcliente = $body['codcliente'] ?? '';
        $preferWcoins = (bool)($body['preferWcoins'] ?? false);

        if (empty($codcliente)) {
            $this->jsonResponse(['error' => 'codcliente required'], 400);
            return;
        }

        Tools::settingsSave('solwed', 'wallet_prefer_' . $codcliente, $preferWcoins ? '1' : '0');
        $this->jsonResponse(['success' => true, 'preferWcoins' => $preferWcoins]);
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
