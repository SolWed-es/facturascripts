<?php

/**
 * Plugin SolwedES - Debug Controller for DonDominio
 *
 * ACCESS: https://erp.solwed.es/DebugDonDominio?action=XXX
 *
 * IMPORTANT: Remove or disable this controller in production!
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Lib\DonDominioHelper;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;
use FacturaScripts\Dinamic\Model\Contacto;

class DebugDonDominio extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Debug DonDominio';
        $data['icon'] = 'fa-solid fa-bug';
        $data['showonmenu'] = false; // Hidden from menu
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        // Disabled in production. Re-enable by exporting SOLWED_DEBUG_ENABLED=1
        // in the ERP container env (and restart) — admin-only gate still applies.
        $debugEnabled = getenv('SOLWED_DEBUG_ENABLED') === '1';
        if (!$debugEnabled) {
            Tools::log()->warning('DebugDonDominio access blocked (controller disabled)');
            $this->setTemplate(false);
            $this->response->setStatusCode(410);
            $this->response->headers->set('Content-Type', 'application/json; charset=utf-8');
            $this->response->setContent(json_encode([
                'error' => 'Debug controller disabled',
                'hint' => 'Set SOLWED_DEBUG_ENABLED=1 in ERP env to enable',
            ]));
            return;
        }

        if (!$user->admin) {
            Tools::log()->error('Unauthorized access to DebugDonDominio');
            $this->response->setContent(json_encode(['error' => 'Unauthorized']));
            return;
        }

        $this->setTemplate(false);
        header('Content-Type: application/json; charset=utf-8');

        $action = $this->request->get('action', 'status');

        try {
            $result = match($action) {
                'status' => $this->testStatus(),
                'check' => $this->testCheckAvailability(),
                'list' => $this->testListDomains(),
                'balance' => $this->testBalance(),
                'info' => $this->testDomainInfo(),
                'pricing' => $this->testTldPricing(),
                'settings' => $this->showSettings(),
                'contact' => $this->testContactConversion(),
                'sync' => $this->forceSync(),
                default => ['error' => 'Unknown action. Available: status, check, list, balance, info, pricing, settings, contact, sync']
            };
        } catch (\Exception $e) {
            $result = [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }

        $this->response->setContent(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Test configuration status
     * URL: ?action=status
     */
    private function testStatus(): array
    {
        return [
            'action' => 'status',
            'configured' => DonDominioHelper::isConfigured(),
            'api_user' => DonDominioHelper::getSetting('dondominio_api_user') ? 'SET (hidden)' : 'NOT SET',
            'api_pass' => DonDominioHelper::getSetting('dondominio_api_pass') ? 'SET (hidden)' : 'NOT SET',
            'auto_sync' => DonDominioHelper::getSetting('dondominio_auto_sync', false),
            'default_years' => DonDominioHelper::getSetting('dondominio_default_years', 1),
            'default_nameservers' => DonDominioHelper::getSetting('dondominio_default_nameservers', ''),
            'last_sync' => DonDominioHelper::getSetting('dondominio_last_sync', 'Never'),
            'sdk_available' => class_exists(\Dondominio\API\API::class),
        ];
    }

    /**
     * Test domain availability check
     * URL: ?action=check&domain=example.com
     */
    private function testCheckAvailability(): array
    {
        $domain = $this->request->get('domain', 'test-solwed-' . time() . '.com');

        return [
            'action' => 'check',
            'domain' => $domain,
            'result' => DonDominioHelper::checkAvailability($domain)
        ];
    }

    /**
     * Test listing domains
     * URL: ?action=list&limit=10
     */
    private function testListDomains(): array
    {
        $limit = (int)$this->request->get('limit', 10);

        $result = DonDominioHelper::listDomains();

        if ($result['success'] && !empty($result['domains'])) {
            // Limit output for readability
            $result['domains'] = array_slice($result['domains'], 0, $limit);
            $result['showing'] = count($result['domains']);
        }

        return [
            'action' => 'list',
            'limit' => $limit,
            'result' => $result
        ];
    }

    /**
     * Test account balance
     * URL: ?action=balance
     */
    private function testBalance(): array
    {
        // First try through helper
        $helperResult = DonDominioHelper::getAccountBalance();

        // Also try direct API call for debugging
        $directResult = $this->testDirectApiCall();

        return [
            'action' => 'balance',
            'via_helper' => $helperResult,
            'via_direct_api' => $directResult
        ];
    }

    /**
     * Direct API call for debugging
     */
    private function testDirectApiCall(): array
    {
        try {
            $apiUser = DonDominioHelper::getSetting('dondominio_api_user');
            $apiPass = DonDominioHelper::getSetting('dondominio_api_pass');

            if (empty($apiUser) || empty($apiPass)) {
                return ['error' => 'Credentials not set'];
            }

            $api = new \Dondominio\API\API([
                'apiuser' => $apiUser,
                'apipasswd' => $apiPass,
            ]);

            // Try account_info
            $response = $api->account_info();

            // Use correct SDK methods for response handling
            $data = $response->getResponseData();

            return [
                'success' => $response->getSuccess(),
                'errorCode' => $response->getErrorCode(),
                'errorCodeMsg' => $response->getErrorCodeMsg(),
                'responseData' => $data,
                'balance' => $data['balance'] ?? 'not found',
                'currency' => $data['currency'] ?? 'not found',
                'clientName' => $data['clientName'] ?? 'not found',
                'threshold' => $data['threshold'] ?? 'not found',
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * Test getting domain info
     * URL: ?action=info&domain=example.com
     */
    private function testDomainInfo(): array
    {
        $domain = $this->request->get('domain', '');

        if (empty($domain)) {
            return ['error' => 'Missing domain parameter. Usage: ?action=info&domain=yourdomain.com'];
        }

        return [
            'action' => 'info',
            'domain' => $domain,
            'result' => DonDominioHelper::getDomainInfo($domain)
        ];
    }

    /**
     * Test TLD pricing
     * URL: ?action=pricing&tld=com
     */
    private function testTldPricing(): array
    {
        $tld = $this->request->get('tld', 'com');

        return [
            'action' => 'pricing',
            'tld' => $tld,
            'result' => DonDominioHelper::getTldPricing($tld)
        ];
    }

    /**
     * Show all settings
     * URL: ?action=settings
     */
    private function showSettings(): array
    {
        return [
            'action' => 'settings',
            'dondominio_api_user' => DonDominioHelper::getSetting('dondominio_api_user') ? '***SET***' : null,
            'dondominio_api_pass' => DonDominioHelper::getSetting('dondominio_api_pass') ? '***SET***' : null,
            'dondominio_auto_sync' => DonDominioHelper::getSetting('dondominio_auto_sync'),
            'dondominio_create_contacts' => DonDominioHelper::getSetting('dondominio_create_contacts'),
            'dondominio_send_notifications' => DonDominioHelper::getSetting('dondominio_send_notifications'),
            'dondominio_default_years' => DonDominioHelper::getSetting('dondominio_default_years'),
            'dondominio_default_nameservers' => DonDominioHelper::getSetting('dondominio_default_nameservers'),
            'dondominio_last_sync' => DonDominioHelper::getSetting('dondominio_last_sync'),
            'dondominio_account_balance' => DonDominioHelper::getSetting('dondominio_account_balance'),
        ];
    }

    /**
     * Test contact conversion
     * URL: ?action=contact&idcontacto=123
     */
    private function testContactConversion(): array
    {
        $idcontacto = (int)$this->request->get('idcontacto', 0);

        if ($idcontacto === 0) {
            // List some contacts
            $contacto = new Contacto();
            $contacts = $contacto->all([], [], 0, 5);

            return [
                'action' => 'contact',
                'hint' => 'Add ?idcontacto=X to test conversion',
                'sample_contacts' => array_map(function($c) {
                    return [
                        'idcontacto' => $c->idcontacto,
                        'nombre' => $c->nombre,
                        'email' => $c->email
                    ];
                }, $contacts)
            ];
        }

        $contacto = new Contacto();
        if (!$contacto->load($idcontacto)) {
            return ['error' => 'Contact not found: ' . $idcontacto];
        }

        return [
            'action' => 'contact',
            'idcontacto' => $idcontacto,
            'original' => [
                'nombre' => $contacto->nombre,
                'empresa' => $contacto->empresa,
                'cifnif' => $contacto->cifnif,
                'email' => $contacto->email,
                'telefono1' => $contacto->telefono1,
                'direccion' => $contacto->direccion,
                'ciudad' => $contacto->ciudad,
                'codpostal' => $contacto->codpostal,
                'provincia' => $contacto->provincia,
                'codpais' => $contacto->codpais,
            ],
            'converted_to_dondominio' => DonDominioHelper::contactoToDD($contacto)
        ];
    }
}
