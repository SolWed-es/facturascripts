<?php
/**
 * Plugin SolwedES - API Portal Login
 *
 * Unified auth: searches FS contactos (clients) AND users (admin/staff).
 * Returns role: admin | cliente | usuario.
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\User;

class ApiPortalLogin extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Portal Login';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        if ($this->request->getMethod() !== 'POST') {
            $this->sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
            return;
        }

        $body = json_decode($this->request->getContent(), true);
        if (!$body) {
            $this->sendJsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);
            return;
        }

        $nick = $body['nick'] ?? '';
        $password = $body['password'] ?? '';

        if (empty($nick) || empty($password)) {
            $this->sendJsonResponse(['success' => false, 'error' => 'Nick and password are required'], 400);
            return;
        }

        // Device info from App (forwarded headers)
        $clientIp = $body['client_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $body['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';

        // 1. Try contactos (portal clients)
        $contact = $this->findContactByNick($nick);
        if ($contact && $contact->pc_active && $this->verifyPassword($password, $contact->pc_password)) {
            $this->trackDevice($contact->idcontacto, $contact->pc_nick, $clientIp, $userAgent);
            $this->sendJsonResponse([
                'success' => true,
                'type' => 'contact',
                'role' => 'cliente',
                'contact' => $this->serializeContact($contact),
            ]);
            return;
        }

        // 2. Try FS users (admin/staff)
        $user = $this->findUserByNick($nick);
        if ($user && $user->enabled && $this->verifyPassword($password, $user->password)) {
            $role = $user->admin ? 'admin' : 'usuario';

            // Try to find linked contact by email
            $linkedContact = null;
            if (!empty($user->email)) {
                $linkedContact = $this->findContactByNick($user->email);
            }

            $responseData = [
                'success' => true,
                'type' => 'user',
                'role' => $role,
                'user' => [
                    'nick' => $user->nick,
                    'email' => $user->email,
                    'admin' => (bool) $user->admin,
                    'enabled' => (bool) $user->enabled,
                ],
            ];

            if ($linkedContact) {
                $responseData['contact'] = $this->serializeContact($linkedContact);
                $this->trackDevice($linkedContact->idcontacto, $user->nick, $clientIp, $userAgent);
            }

            $this->sendJsonResponse($responseData);
            return;
        }

        $this->sendJsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
    }

    private function findContactByNick(string $nick): ?Contacto
    {
        $contacto = new Contacto();
        $nickLower = strtolower(trim($nick));

        $where = [new DataBaseWhere('LOWER(pc_nick)', $nickLower)];
        $contacts = $contacto->all($where, [], 0, 1);
        if (!empty($contacts)) {
            return $contacts[0];
        }

        $where = [new DataBaseWhere('LOWER(email)', $nickLower)];
        $contacts = $contacto->all($where, [], 0, 1);
        return !empty($contacts) ? $contacts[0] : null;
    }

    private function findUserByNick(string $nick): ?User
    {
        $user = new User();
        $nickLower = strtolower(trim($nick));

        $where = [new DataBaseWhere('LOWER(nick)', $nickLower)];
        $users = $user->all($where, [], 0, 1);
        if (!empty($users)) {
            return $users[0];
        }

        $where = [new DataBaseWhere('LOWER(email)', $nickLower)];
        $users = $user->all($where, [], 0, 1);
        return !empty($users) ? $users[0] : null;
    }

    private function verifyPassword(string $password, ?string $hash): bool
    {
        if (empty($hash)) {
            return false;
        }
        if (password_verify($password, $hash)) {
            return true;
        }
        return $password === $hash;
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
            'codpostal' => $c->codpostal,
            'ciudad' => $c->ciudad,
            'provincia' => $c->provincia,
            'codpais' => $c->codpais,
            'cifnif' => $c->cifnif,
            'pc_nick' => $c->pc_nick,
            'pc_active' => (bool) $c->pc_active,
            'langcode' => $c->langcode ?? '',
        ];
    }

    /**
     * Track device on successful login: update contacto + insert log
     */
    private function trackDevice(int $idcontacto, string $nick, string $ip, string $userAgent): void
    {
        try {
            $db = new \FacturaScripts\Core\Base\DataBase();
            $db->connect();
            $now = date('Y-m-d H:i:s');
            $deviceName = $this->parseDeviceName($userAgent);

            // Update contacto last device
            $db->exec("UPDATE contactos SET pc_last_browser = " . $db->var2str($userAgent)
                . ", pc_last_ip = " . $db->var2str($ip)
                . ", pc_last_login = " . $db->var2str($now)
                . " WHERE idcontacto = " . (int) $idcontacto);

            // Insert login log
            $context = json_encode(['user_agent' => $userAgent, 'device_name' => $deviceName]);
            $db->exec("INSERT INTO logs (channel, idcontacto, ip, nick, level, message, context, time) VALUES ("
                . $db->var2str('portal-login') . ", "
                . (int) $idcontacto . ", "
                . $db->var2str($ip) . ", "
                . $db->var2str($nick) . ", "
                . $db->var2str('info') . ", "
                . $db->var2str('Portal login: ' . $deviceName) . ", "
                . $db->var2str($context) . ", "
                . $db->var2str($now) . ")");
        } catch (\Throwable $e) {
            // Non-fatal — don't block login
        }
    }

    /**
     * Parse User-Agent into human-readable device name
     */
    private function parseDeviceName(string $ua): string
    {
        $browser = 'Navegador';
        $os = '';

        if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false) $browser = 'Chrome';
        elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
        elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) $browser = 'Safari';
        elseif (stripos($ua, 'Edg') !== false) $browser = 'Edge';

        if (stripos($ua, 'Windows') !== false) $os = 'Windows';
        elseif (stripos($ua, 'Mac') !== false) $os = 'Mac';
        elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
        elseif (stripos($ua, 'iPhone') !== false) $os = 'iPhone';
        elseif (stripos($ua, 'Android') !== false) $os = 'Android';
        elseif (stripos($ua, 'iPad') !== false) $os = 'iPad';

        return $os ? "$browser en $os" : $browser;
    }

    private function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        $this->response->setStatusCode($statusCode);
        $this->response->headers->set('Content-Type', 'application/json');
        $this->response->headers->set('Access-Control-Allow-Origin', '*');
        $this->response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $this->response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Token');
        $this->response->setContent(json_encode($data));
    }
}
