<?php
/**
 * Plugin SolwedES - API Portal Login
 *
 * Provides authentication endpoint for the external Next.js portal.
 * Verifies credentials against the Contacto model's pc_nick and pc_password.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\Contacto;

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

    /**
     * Public endpoint - no authentication required
     */
    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        // Only allow POST requests
        if ($this->request->getMethod() !== 'POST') {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Method not allowed'
            ], 405);
            return;
        }

        // Get JSON body
        $body = json_decode($this->request->getContent(), true);

        if (!$body) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Invalid JSON body'
            ], 400);
            return;
        }

        $nick = $body['nick'] ?? '';
        $password = $body['password'] ?? '';

        if (empty($nick) || empty($password)) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Nick and password are required'
            ], 400);
            return;
        }

        // Find contact by nick or email
        $contact = $this->findContactByNick($nick);

        if (!$contact) {
            // Don't reveal if user exists
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Invalid credentials'
            ], 401);
            return;
        }

        // Check if account is active
        if (!$contact->pc_active) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Account is disabled'
            ], 403);
            return;
        }

        // Verify password
        if (!$this->verifyPassword($password, $contact->pc_password)) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Invalid credentials'
            ], 401);
            return;
        }

        // Success - return contact data (without sensitive fields)
        $this->sendJsonResponse([
            'success' => true,
            'contact' => [
                'idcontacto' => $contact->idcontacto,
                'codcliente' => $contact->codcliente,
                'email' => $contact->email,
                'nombre' => $contact->nombre,
                'apellidos' => $contact->apellidos,
                'empresa' => $contact->empresa,
                'telefono1' => $contact->telefono1,
                'telefono2' => $contact->telefono2,
                'direccion' => $contact->direccion,
                'apartado' => $contact->apartado,
                'codpostal' => $contact->codpostal,
                'ciudad' => $contact->ciudad,
                'provincia' => $contact->provincia,
                'codpais' => $contact->codpais,
                'cifnif' => $contact->cifnif,
                'pc_nick' => $contact->pc_nick,
                'pc_active' => $contact->pc_active,
                'pc_allow_buy' => $contact->pc_allow_buy,
                'pc_allow_show_invoice' => $contact->pc_allow_show_invoice,
                'pc_allow_show_order' => $contact->pc_allow_show_order,
                'pc_allow_show_estimation' => $contact->pc_allow_show_estimation,
                'pc_allow_show_delivery_note' => $contact->pc_allow_show_delivery_note,
                'langcode' => $contact->langcode,
            ]
        ]);
    }

    /**
     * Find contact by nick or email
     */
    private function findContactByNick(string $nick): ?Contacto
    {
        $contacto = new Contacto();
        $nickLower = strtolower(trim($nick));

        // First try to find by pc_nick
        $where = [new DataBaseWhere('LOWER(pc_nick)', $nickLower)];
        $contacts = $contacto->all($where, [], 0, 1);

        if (!empty($contacts)) {
            return $contacts[0];
        }

        // Try to find by email
        $where = [new DataBaseWhere('LOWER(email)', $nickLower)];
        $contacts = $contacto->all($where, [], 0, 1);

        if (!empty($contacts)) {
            return $contacts[0];
        }

        return null;
    }

    /**
     * Verify password using PHP's password_verify
     */
    private function verifyPassword(string $password, ?string $hash): bool
    {
        if (empty($hash)) {
            return false;
        }

        // Use password_verify for bcrypt hashes
        if (password_verify($password, $hash)) {
            return true;
        }

        // Fallback for legacy plain text passwords (if any)
        // This should be removed once all passwords are properly hashed
        if ($password === $hash) {
            return true;
        }

        return false;
    }

    /**
     * Send JSON response with proper headers
     */
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
