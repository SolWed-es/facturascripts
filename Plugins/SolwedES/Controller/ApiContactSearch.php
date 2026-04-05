<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\Contacto;

/**
 * Fast contact search by nick or email (single query, no pagination).
 * Used by Mind for authentication.
 *
 * GET /ApiContactSearch?nick=IvanMoreno
 * GET /ApiContactSearch?email=ivan@solwed.es
 * GET /ApiContactSearch?id=66
 */
class ApiContactSearch extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Contact Search';
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

        // Token authentication via api_keys table
        if (!$this->validateToken()) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->jsonResponse(['error' => 'Only GET allowed'], 405);
            return;
        }

        try {
            $nick = $this->request->get('nick', '');
            $email = $this->request->get('email', '');
            $id = (int) $this->request->get('id', 0);

            $contact = null;

            if (!empty($nick)) {
                $contact = $this->findByNick($nick);
            } elseif (!empty($email)) {
                $contact = $this->findByEmail($email);
            } elseif ($id > 0) {
                $contacto = new Contacto();
                if ($contacto->load($id)) {
                    $contact = $contacto;
                }
            } else {
                $this->jsonResponse(['error' => 'Provide nick, email or id parameter'], 400);
                return;
            }

            if ($contact === null) {
                $this->jsonResponse(['error' => 'Contact not found'], 404);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'contact' => $this->serializeContact($contact),
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function findByNick(string $nick): ?Contacto
    {
        $contacto = new Contacto();
        $where = [new DataBaseWhere('LOWER(pc_nick)', strtolower($nick))];
        $results = $contacto->all($where, [], 0, 1);
        if (!empty($results)) {
            return $results[0];
        }

        // Fallback: search by email
        return $this->findByEmail($nick);
    }

    private function findByEmail(string $email): ?Contacto
    {
        $contacto = new Contacto();
        $where = [new DataBaseWhere('LOWER(email)', strtolower($email))];
        $results = $contacto->all($where, [], 0, 1);
        return !empty($results) ? $results[0] : null;
    }

    private function serializeContact(Contacto $c): array
    {
        return [
            'idcontacto' => $c->idcontacto,
            'codcliente' => $c->codcliente,
            'email' => $c->email,
            'nombre' => $c->nombre,
            'apellidos' => $c->apellidos,
            'empresa' => $c->empresa,
            'telefono1' => $c->telefono1,
            'telefono2' => $c->telefono2,
            'direccion' => $c->direccion,
            'apartado' => $c->apartado,
            'codpostal' => $c->codpostal,
            'ciudad' => $c->ciudad,
            'provincia' => $c->provincia,
            'codpais' => $c->codpais,
            'cifnif' => $c->cifnif,
            'pc_nick' => $c->pc_nick,
            'pc_active' => (bool) $c->pc_active,
            'pc_allow_buy' => (bool) ($c->pc_allow_buy ?? false),
            'pc_allow_show_invoice' => (bool) ($c->pc_allow_show_invoice ?? true),
            'pc_allow_show_order' => (bool) ($c->pc_allow_show_order ?? true),
            'pc_allow_show_estimation' => (bool) ($c->pc_allow_show_estimation ?? true),
            'pc_allow_show_delivery_note' => (bool) ($c->pc_allow_show_delivery_note ?? true),
            'langcode' => $c->langcode ?? '',
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
