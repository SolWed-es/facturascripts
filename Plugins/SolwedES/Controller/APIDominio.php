<?php
/**
 * Plugin SolwedES - API Controller para Dominios
 *
 * Endpoints para gestión de dominios desde el portal Next.js
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;
use FacturaScripts\Plugins\SolwedES\Lib\StripeSubscriptionManager;
use FacturaScripts\Plugins\SolwedES\Lib\StripeHelper;
use FacturaScripts\Plugins\SolwedES\Lib\SolwedLogger;
use FacturaScripts\Plugins\SolwedES\Lib\DonDominioHelper;

/**
 * API Controller para operaciones de dominio desde el portal
 */
class APIDominio extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Dominio';
        $data['showonmenu'] = false;
        return $data;
    }

    /**
     * Punto de entrada público (no requiere autenticación FS)
     * La autenticación se hace mediante token del portal
     */
    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->setTemplate(false);

        // CORS headers for Next.js portal
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = ['https://app.solwed.es', 'https://erp.solwed.es', 'https://mind.solwed.es'];
        if (in_array($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        if (!$this->validateToken()) {
            return;
        }

        // Get action from request
        $action = $this->request->get('action', '');

        SolwedLogger::stripe('APIDominio request: ' . $action);

        try {
            switch ($action) {
                case 'enableAutoRenewal':
                    $this->handleEnableAutoRenewal();
                    break;

                case 'disableAutoRenewal':
                    $this->handleDisableAutoRenewal();
                    break;

                case 'getAutoRenewalStatus':
                    $this->handleGetAutoRenewalStatus();
                    break;

                case 'getRenewalPrice':
                    $this->handleGetRenewalPrice();
                    break;

                case 'createRenewalCheckout':
                    $this->handleCreateRenewalCheckout();
                    break;

                case 'check':
                    $this->handleCheck();
                    break;

                case 'whois':
                    $this->handleWhois();
                    break;

                case 'history':
                    $this->handleHistory();
                    break;

                case 'getAuthcode':
                    $this->handleGetAuthcode();
                    break;

                case 'getNameservers':
                    $this->handleGetNameservers();
                    break;

                case 'setNameservers':
                    $this->handleSetNameservers();
                    break;

                case 'setTransferLock':
                    $this->handleSetTransferLock();
                    break;

                case 'renew':
                    $this->handleRenew();
                    break;

                case 'suggest':
                    $this->handleSuggest();
                    break;

                case 'createRegistrationCheckout':
                    $this->handleCreateRegistrationCheckout();
                    break;

                case 'createTransferCheckout':
                    $this->handleCreateTransferCheckout();
                    break;

                default:
                    $this->sendJsonResponse(['error' => 'Invalid action: ' . $action], 400);
            }
        } catch (Exception $e) {
            SolwedLogger::error('APIDominio exception: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Enable auto-renewal for a domain
     * Creates a Stripe subscription checkout session
     *
     * Required params:
     * - iddominio: Domain ID
     * - idcontacto: Contact ID
     * - successUrl: URL to redirect after successful checkout
     * - cancelUrl: URL to redirect if checkout is cancelled
     */
    private function handleEnableAutoRenewal(): void
    {
        $iddominio = $this->getRequestInt('iddominio');
        $idcontacto = $this->getRequestInt('idcontacto');
        $successUrl = $this->request->get('successUrl', '');
        $cancelUrl = $this->request->get('cancelUrl', '');

        if (!$iddominio) {
            $this->sendJsonResponse(['error' => 'Missing iddominio'], 400);
            return;
        }

        if (!$idcontacto) {
            $this->sendJsonResponse(['error' => 'Missing idcontacto'], 400);
            return;
        }

        if (empty($successUrl) || empty($cancelUrl)) {
            $this->sendJsonResponse(['error' => 'Missing successUrl or cancelUrl'], 400);
            return;
        }

        // Load domain
        $dominio = new Dominio();
        if (!$dominio->load($iddominio)) {
            $this->sendJsonResponse(['error' => 'Domain not found'], 404);
            return;
        }

        // Verify ownership
        if ($dominio->idcontacto !== $idcontacto) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }

        // Check if already has auto-renewal
        if ($dominio->hasAutoRenewal()) {
            $this->sendJsonResponse([
                'success' => true,
                'message' => 'Auto-renewal already enabled',
                'subscription_id' => $dominio->stripe_subscription_id
            ]);
            return;
        }

        // Load contact
        $contacto = new Contacto();
        if (!$contacto->load($idcontacto)) {
            $this->sendJsonResponse(['error' => 'Contact not found'], 404);
            return;
        }

        // Create checkout session
        $result = StripeSubscriptionManager::createDomainAutoRenewalCheckout(
            $dominio,
            $contacto,
            $successUrl,
            $cancelUrl
        );

        if (!$result['success']) {
            $this->sendJsonResponse(['error' => $result['error'] ?? 'Failed to create checkout'], 500);
            return;
        }

        $this->sendJsonResponse([
            'success' => true,
            'checkoutUrl' => $result['url'],
            'sessionId' => $result['session_id']
        ]);
    }

    /**
     * Disable auto-renewal for a domain
     * Cancels the Stripe subscription
     *
     * Required params:
     * - iddominio: Domain ID
     * - idcontacto: Contact ID
     * - immediately: (optional) Cancel immediately or at end of period
     */
    private function handleDisableAutoRenewal(): void
    {
        $iddominio = $this->getRequestInt('iddominio');
        $idcontacto = $this->getRequestInt('idcontacto');
        $immediately = $this->request->get('immediately', 'false') === 'true';

        if (!$iddominio) {
            $this->sendJsonResponse(['error' => 'Missing iddominio'], 400);
            return;
        }

        if (!$idcontacto) {
            $this->sendJsonResponse(['error' => 'Missing idcontacto'], 400);
            return;
        }

        // Load domain
        $dominio = new Dominio();
        if (!$dominio->load($iddominio)) {
            $this->sendJsonResponse(['error' => 'Domain not found'], 404);
            return;
        }

        // Verify ownership
        if ($dominio->idcontacto !== $idcontacto) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }

        // Check if has auto-renewal
        if (!$dominio->hasAutoRenewal()) {
            $this->sendJsonResponse([
                'success' => true,
                'message' => 'Auto-renewal not enabled'
            ]);
            return;
        }

        // Cancel subscription
        $result = StripeSubscriptionManager::cancelDomainAutoRenewal($dominio, $immediately);

        if (!$result['success']) {
            $this->sendJsonResponse(['error' => $result['error'] ?? 'Failed to cancel'], 500);
            return;
        }

        $this->sendJsonResponse([
            'success' => true,
            'message' => $immediately ? 'Auto-renewal cancelled immediately' : 'Auto-renewal will be cancelled at end of period'
        ]);
    }

    /**
     * Get auto-renewal status for a domain
     *
     * Required params:
     * - iddominio: Domain ID
     * - idcontacto: Contact ID
     */
    private function handleGetAutoRenewalStatus(): void
    {
        $iddominio = $this->getRequestInt('iddominio');
        $idcontacto = $this->getRequestInt('idcontacto');

        if (!$iddominio) {
            $this->sendJsonResponse(['error' => 'Missing iddominio'], 400);
            return;
        }

        if (!$idcontacto) {
            $this->sendJsonResponse(['error' => 'Missing idcontacto'], 400);
            return;
        }

        // Load domain
        $dominio = new Dominio();
        if (!$dominio->load($iddominio)) {
            $this->sendJsonResponse(['error' => 'Domain not found'], 404);
            return;
        }

        // Verify ownership
        if ($dominio->idcontacto !== $idcontacto) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }

        $response = [
            'success' => true,
            'domain' => $dominio->getNombreCompleto(),
            'auto_renewal' => $dominio->hasAutoRenewal(),
            'subscription_id' => $dominio->stripe_subscription_id,
            'expiration' => $dominio->fecha_expiracion,
            'days_until_expiration' => $dominio->getDiasHastaExpiracion()
        ];

        // Get subscription details if auto-renewal is enabled
        if ($dominio->hasAutoRenewal() && !empty($dominio->stripe_subscription_id)) {
            $subDetails = StripeSubscriptionManager::getSubscriptionDetails($dominio->stripe_subscription_id);
            if ($subDetails['success']) {
                $response['subscription'] = [
                    'status' => $subDetails['status'],
                    'next_billing_date' => $subDetails['current_period_end'],
                    'cancel_at_period_end' => $subDetails['cancel_at_period_end'] ?? false,
                    'payment_method' => $subDetails['payment_method']
                ];
            }
        }

        // Get renewal price
        $response['renewal_price'] = StripeSubscriptionManager::getDomainRenewalPrice($dominio->tld);

        $this->sendJsonResponse($response);
    }

    /**
     * Get renewal price for a domain/TLD
     *
     * Required params:
     * - tld: TLD (e.g., .com, .es) OR
     * - iddominio: Domain ID
     */
    private function handleGetRenewalPrice(): void
    {
        $tld = $this->request->get('tld', '');
        $iddominio = $this->getRequestInt('iddominio');

        if (empty($tld) && !$iddominio) {
            $this->sendJsonResponse(['error' => 'Missing tld or iddominio'], 400);
            return;
        }

        if ($iddominio) {
            $dominio = new Dominio();
            if (!$dominio->load($iddominio)) {
                $this->sendJsonResponse(['error' => 'Domain not found'], 404);
                return;
            }
            $tld = $dominio->tld;
        }

        $price = StripeSubscriptionManager::getDomainRenewalPrice($tld);

        $this->sendJsonResponse([
            'success' => true,
            'tld' => $tld,
            'price' => $price,
            'currency' => 'EUR',
            'period' => 'year'
        ]);
    }

    /**
     * Create a one-time renewal checkout (manual renewal)
     *
     * Required params:
     * - iddominio: Domain ID
     * - idcontacto: Contact ID
     * - successUrl: URL to redirect after successful checkout
     * - cancelUrl: URL to redirect if checkout is cancelled
     * - years: (optional) Number of years to renew (default 1)
     */
    private function handleCreateRenewalCheckout(): void
    {
        $iddominio = $this->getRequestInt('iddominio');
        $idcontacto = $this->getRequestInt('idcontacto');
        $successUrl = $this->request->get('successUrl', '');
        $cancelUrl = $this->request->get('cancelUrl', '');
        $years = $this->getRequestInt('years') ?: 1;

        if (!$iddominio) {
            $this->sendJsonResponse(['error' => 'Missing iddominio'], 400);
            return;
        }

        if (!$idcontacto) {
            $this->sendJsonResponse(['error' => 'Missing idcontacto'], 400);
            return;
        }

        if (empty($successUrl) || empty($cancelUrl)) {
            $this->sendJsonResponse(['error' => 'Missing successUrl or cancelUrl'], 400);
            return;
        }

        // Load domain
        $dominio = new Dominio();
        if (!$dominio->load($iddominio)) {
            $this->sendJsonResponse(['error' => 'Domain not found'], 404);
            return;
        }

        // Verify ownership
        if ($dominio->idcontacto !== $idcontacto) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }

        // Load contact
        $contacto = new Contacto();
        if (!$contacto->load($idcontacto)) {
            $this->sendJsonResponse(['error' => 'Contact not found'], 404);
            return;
        }

        // Check Stripe configuration
        if (!StripeHelper::isConfigured()) {
            $this->sendJsonResponse(['error' => 'Stripe not configured'], 500);
            return;
        }

        // Get renewal price
        $price = StripeSubscriptionManager::getDomainRenewalPrice($dominio->tld);
        $priceInCents = (int) ($price * $years * 100);

        try {
            StripeHelper::initStripe();

            // Find or create customer
            $customer = StripeHelper::findOrCreateCustomer(
                $contacto->email,
                $contacto->fullName(),
                [
                    'idcontacto' => $contacto->idcontacto,
                    'codcliente' => $contacto->codcliente ?? ''
                ]
            );

            if (!$customer) {
                $this->sendJsonResponse(['error' => 'Could not create Stripe customer'], 500);
                return;
            }

            // Create one-time checkout session
            $session = \Stripe\Checkout\Session::create([
                'customer' => $customer->id,
                'mode' => 'payment',
                'automatic_tax' => ['enabled' => true],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $priceInCents,
                        'product_data' => [
                            'name' => 'Renovación de dominio: ' . $dominio->getNombreCompleto(),
                            'description' => sprintf('Renovación por %d año(s)', $years)
                        ],
                        'tax_behavior' => 'exclusive'
                    ],
                    'quantity' => 1
                ]],
                'success_url' => $successUrl . (strpos($successUrl, '?') !== false ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'type' => 'domain_renewal',
                    'idcontacto' => $idcontacto,
                    'iddominio' => $iddominio,
                    'domain' => $dominio->getNombreCompleto(),
                    'years' => $years
                ]
            ]);

            SolwedLogger::stripe(sprintf(
                'Domain renewal checkout created: %s for %s (%d years)',
                $session->id,
                $dominio->getNombreCompleto(),
                $years
            ));

            $this->sendJsonResponse([
                'success' => true,
                'checkoutUrl' => $session->url,
                'sessionId' => $session->id
            ]);

        } catch (Exception $e) {
            SolwedLogger::error('Error creating renewal checkout: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ── New domain operations (SOL-64) ─────────────────────────

    /**
     * Domain availability check (single or comma-separated list).
     * Params: dominio (string, comma-separated allowed)
     */
    private function handleCheck(): void
    {
        $domains = $this->request->get('dominio', '');
        if (empty($domains)) {
            $this->sendJsonResponse(['error' => 'Missing dominio'], 400);
            return;
        }
        $list = array_filter(array_map('trim', explode(',', $domains)));
        $results = [];
        foreach ($list as $d) {
            $results[$d] = DonDominioHelper::checkAvailability($d);
        }
        $this->sendJsonResponse(['success' => true, 'results' => $results]);
    }

    /**
     * Domain WHOIS data via DonDominio.
     * Params: dominio
     */
    private function handleWhois(): void
    {
        $dominio = $this->request->get('dominio', '');
        if (empty($dominio)) {
            $this->sendJsonResponse(['error' => 'Missing dominio'], 400);
            return;
        }
        if (!$this->verifyDomainOwnership($dominio)) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }
        $api = DonDominioHelper::initAPI();
        if (!$api) {
            $this->sendJsonResponse(['error' => 'DonDominio not configured'], 500);
            return;
        }
        try {
            $response = $api->domain_whois($dominio);
            if (!$response->getSuccess()) {
                $this->sendJsonResponse(['error' => $response->getErrorCodeMsg() ?? 'WHOIS failed'], 502);
                return;
            }
            $this->sendJsonResponse(['success' => true, 'whois' => $response->getResponseData()]);
        } catch (Exception $e) {
            SolwedLogger::error('WHOIS error: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Domain operation history.
     * Params: dominio
     */
    private function handleHistory(): void
    {
        $dominio = $this->request->get('dominio', '');
        if (empty($dominio)) {
            $this->sendJsonResponse(['error' => 'Missing dominio'], 400);
            return;
        }
        if (!$this->verifyDomainOwnership($dominio)) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }
        $api = DonDominioHelper::initAPI();
        if (!$api) {
            $this->sendJsonResponse(['error' => 'DonDominio not configured'], 500);
            return;
        }
        try {
            $response = $api->domain_getHistory($dominio);
            if (!$response->getSuccess()) {
                $this->sendJsonResponse(['error' => $response->getErrorCodeMsg() ?? 'History failed'], 502);
                return;
            }
            $this->sendJsonResponse(['success' => true, 'history' => $response->getResponseData()]);
        } catch (Exception $e) {
            SolwedLogger::error('History error: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get domain auth code (EPP) for transfer-out.
     * Params: dominio
     */
    private function handleGetAuthcode(): void
    {
        $dominio = $this->request->get('dominio', '');
        if (empty($dominio)) {
            $this->sendJsonResponse(['error' => 'Missing dominio'], 400);
            return;
        }
        if (!$this->verifyDomainOwnership($dominio)) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }
        $api = DonDominioHelper::initAPI();
        if (!$api) {
            $this->sendJsonResponse(['error' => 'DonDominio not configured'], 500);
            return;
        }
        try {
            $response = $api->domain_getAuthCode($dominio);
            if (!$response->getSuccess()) {
                $this->sendJsonResponse(['error' => $response->getErrorCodeMsg() ?? 'AuthCode failed'], 502);
                return;
            }
            $data = $response->getResponseData();
            $this->sendJsonResponse(['success' => true, 'authcode' => $data['authcode'] ?? $data]);
        } catch (Exception $e) {
            SolwedLogger::error('AuthCode error: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get current nameservers.
     * Params: dominio
     */
    private function handleGetNameservers(): void
    {
        $dominio = $this->request->get('dominio', '');
        if (empty($dominio)) {
            $this->sendJsonResponse(['error' => 'Missing dominio'], 400);
            return;
        }
        if (!$this->verifyDomainOwnership($dominio)) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }
        $api = DonDominioHelper::initAPI();
        if (!$api) {
            $this->sendJsonResponse(['error' => 'DonDominio not configured'], 500);
            return;
        }
        try {
            $response = $api->domain_getNameServers($dominio);
            if (!$response->getSuccess()) {
                $this->sendJsonResponse(['error' => $response->getErrorCodeMsg() ?? 'getNameServers failed'], 502);
                return;
            }
            $data = $response->getResponseData();
            $this->sendJsonResponse(['success' => true, 'nameservers' => $data['nameservers'] ?? $data]);
        } catch (Exception $e) {
            SolwedLogger::error('getNameservers error: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update domain nameservers.
     * Params: dominio, nameservers (comma-separated, min 2)
     */
    private function handleSetNameservers(): void
    {
        $dominio = $this->request->get('dominio', '');
        $nsRaw = $this->request->get('nameservers', '');
        if (empty($dominio) || empty($nsRaw)) {
            $this->sendJsonResponse(['error' => 'Missing dominio or nameservers'], 400);
            return;
        }
        if (!$this->verifyDomainOwnership($dominio)) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }
        $ns = array_filter(array_map('trim', explode(',', $nsRaw)));
        if (count($ns) < 2) {
            $this->sendJsonResponse(['error' => 'At least 2 nameservers required'], 400);
            return;
        }
        $api = DonDominioHelper::initAPI();
        if (!$api) {
            $this->sendJsonResponse(['error' => 'DonDominio not configured'], 500);
            return;
        }
        try {
            $response = $api->domain_updateNameServers($dominio, $ns);
            if (!$response->getSuccess()) {
                $this->sendJsonResponse(['error' => $response->getErrorCodeMsg() ?? 'updateNameServers failed'], 502);
                return;
            }
            SolwedLogger::stripe('Nameservers updated for ' . $dominio . ': ' . implode(',', $ns));
            $this->sendJsonResponse(['success' => true, 'nameservers' => $ns]);
        } catch (Exception $e) {
            SolwedLogger::error('setNameservers error: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle transfer block on a domain.
     * Params: dominio, lock (1|0)
     */
    private function handleSetTransferLock(): void
    {
        $dominio = $this->request->get('dominio', '');
        $lock = $this->request->get('lock', null);
        if (empty($dominio) || $lock === null) {
            $this->sendJsonResponse(['error' => 'Missing dominio or lock'], 400);
            return;
        }
        if (!$this->verifyDomainOwnership($dominio)) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }
        $lockBool = filter_var($lock, FILTER_VALIDATE_BOOLEAN);
        $api = DonDominioHelper::initAPI();
        if (!$api) {
            $this->sendJsonResponse(['error' => 'DonDominio not configured'], 500);
            return;
        }
        try {
            $response = $api->domain_update($dominio, [
                'updateType' => 'transferBlock',
                'transferBlock' => $lockBool,
            ]);
            if (!$response->getSuccess()) {
                $this->sendJsonResponse(['error' => $response->getErrorCodeMsg() ?? 'transferBlock failed'], 502);
                return;
            }
            SolwedLogger::stripe('Transfer lock ' . ($lockBool ? 'enabled' : 'disabled') . ' for ' . $dominio);
            $this->sendJsonResponse(['success' => true, 'lock' => $lockBool]);
        } catch (Exception $e) {
            SolwedLogger::error('setTransferLock error: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Manual domain renewal via DonDominio (no Stripe — uses DD account balance).
     * Params: dominio, years (default 1)
     */
    private function handleRenew(): void
    {
        $dominio = $this->request->get('dominio', '');
        $years = max(1, (int)$this->request->get('years', '1'));
        if (empty($dominio)) {
            $this->sendJsonResponse(['error' => 'Missing dominio'], 400);
            return;
        }
        if (!$this->verifyDomainOwnership($dominio)) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }
        // Find current expiration from local Dominio model (DD requires it)
        $dominioModel = $this->loadDominioByName($dominio);
        $currentExp = $dominioModel ? $dominioModel->fecha_expiracion : null;
        $result = DonDominioHelper::renewDomain($dominio, $years, $currentExp);
        if (!$result['success']) {
            $this->sendJsonResponse(['error' => $result['error'] ?? 'Renewal failed'], 502);
            return;
        }
        // Update local model
        if ($dominioModel && !empty($result['expiration'])) {
            $dominioModel->fecha_expiracion = $result['expiration'];
            $dominioModel->save();
        }
        $this->sendJsonResponse(['success' => true, 'expiration' => $result['expiration'] ?? null]);
    }

    /**
     * Suggest similar domains (basic local impl: try common TLDs).
     * Params: keyword, tlds (comma-separated, default ".com,.es,.net,.org,.io")
     */
    private function handleSuggest(): void
    {
        $keyword = $this->request->get('keyword', '');
        $tldsRaw = $this->request->get('tlds', '.com,.es,.net,.org,.io');
        if (empty($keyword)) {
            $this->sendJsonResponse(['error' => 'Missing keyword'], 400);
            return;
        }
        // Strip any TLD if present
        $base = preg_replace('/\..+$/', '', strtolower(trim($keyword)));
        $tlds = array_filter(array_map('trim', explode(',', $tldsRaw)));
        $suggestions = [];
        foreach ($tlds as $tld) {
            $tld = ltrim($tld, '.');
            $candidate = $base . '.' . $tld;
            $check = DonDominioHelper::checkAvailability($candidate);
            $suggestions[] = [
                'domain' => $candidate,
                'available' => $check['available'] ?? false,
                'price' => $check['price'] ?? null,
            ];
        }
        $this->sendJsonResponse(['success' => true, 'suggestions' => $suggestions]);
    }

    /**
     * Create Stripe Checkout for new domain registration.
     * Owner data + payment captured before DonDominio call (handled by webhook).
     *
     * Required params:
     * - dominio: Full domain name (example.com)
     * - idcontacto: Contact ID
     * - successUrl, cancelUrl: Stripe redirect URLs
     *
     * Optional:
     * - years (default 1)
     * - whoisPrivacy (0/1)
     * - autorenew (0/1) — currently informational; auto-renewal subscription is separate
     * - nameservers (csv)
     * - contacto[*] — owner contact override (firstName, lastName, email, phone, address, etc.)
     */
    private function handleCreateRegistrationCheckout(): void
    {
        $dominio = trim((string)$this->request->get('dominio', ''));
        $idcontacto = $this->getRequestInt('idcontacto');
        $successUrl = $this->request->get('successUrl', '');
        $cancelUrl = $this->request->get('cancelUrl', '');
        $years = $this->getRequestInt('years') ?: 1;

        if (empty($dominio)) {
            $this->sendJsonResponse(['error' => 'Missing dominio'], 400);
            return;
        }
        if (!$idcontacto) {
            $this->sendJsonResponse(['error' => 'Missing idcontacto'], 400);
            return;
        }
        if (empty($successUrl) || empty($cancelUrl)) {
            $this->sendJsonResponse(['error' => 'Missing successUrl or cancelUrl'], 400);
            return;
        }

        $contacto = new Contacto();
        if (!$contacto->load($idcontacto)) {
            $this->sendJsonResponse(['error' => 'Contact not found'], 404);
            return;
        }

        if (!StripeHelper::isConfigured()) {
            $this->sendJsonResponse(['error' => 'Stripe not configured'], 500);
            return;
        }

        // Confirm domain is available before charging
        $check = DonDominioHelper::checkAvailability($dominio);
        if (empty($check['available'])) {
            $this->sendJsonResponse([
                'error' => 'Domain not available',
                'detail' => $check['error'] ?? null
            ], 409);
            return;
        }

        $priceFloat = isset($check['price']) ? (float)$check['price'] : 0.0;
        if ($priceFloat <= 0) {
            // Fallback to renewal pricing matrix by TLD
            $tld = strrchr($dominio, '.') ?: '.com';
            $priceFloat = (float)StripeSubscriptionManager::getDomainRenewalPrice($tld);
        }
        $priceInCents = (int)round($priceFloat * max(1, $years) * 100);
        if ($priceInCents <= 0) {
            $this->sendJsonResponse(['error' => 'Invalid domain price'], 500);
            return;
        }

        try {
            StripeHelper::initStripe();

            $customer = StripeHelper::findOrCreateCustomer(
                $contacto->email,
                $contacto->fullName(),
                [
                    'idcontacto' => $contacto->idcontacto,
                    'codcliente' => $contacto->codcliente ?? ''
                ]
            );
            if (!$customer) {
                $this->sendJsonResponse(['error' => 'Could not create Stripe customer'], 500);
                return;
            }

            $contactoOverride = $this->collectContactoOverride();

            $metadata = [
                'type' => 'domain_registration',
                'idcontacto' => (string)$idcontacto,
                'domain' => $dominio,
                'years' => (string)$years,
                'whois_privacy' => (string)((int)$this->request->get('whoisPrivacy', 0)),
                'autorenew' => (string)((int)$this->request->get('autorenew', 1)),
            ];
            $nameservers = trim((string)$this->request->get('nameservers', ''));
            if ($nameservers !== '') {
                $metadata['nameservers'] = $nameservers;
            }
            // Stripe metadata values must be strings ≤500 chars; serialize override compactly.
            if (!empty($contactoOverride)) {
                $metadata['contacto_override'] = substr(json_encode($contactoOverride), 0, 480);
            }

            $session = \Stripe\Checkout\Session::create([
                'customer' => $customer->id,
                'mode' => 'payment',
                'automatic_tax' => ['enabled' => true],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $priceInCents,
                        'product_data' => [
                            'name' => 'Registro de dominio: ' . $dominio,
                            'description' => sprintf('Registro por %d año(s)', $years)
                        ],
                        'tax_behavior' => 'exclusive'
                    ],
                    'quantity' => 1
                ]],
                'success_url' => $successUrl . (strpos($successUrl, '?') !== false ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata
            ]);

            SolwedLogger::stripe(sprintf(
                'Domain registration checkout created: %s for %s (%d years, %.2f EUR)',
                $session->id,
                $dominio,
                $years,
                $priceFloat * $years
            ));

            $this->sendJsonResponse([
                'success' => true,
                'checkoutUrl' => $session->url,
                'sessionId' => $session->id
            ]);
        } catch (Exception $e) {
            SolwedLogger::error('Error creating registration checkout: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create Stripe Checkout for domain transfer (auth code captured up front).
     * Webhook handler triggers the actual DonDominio transfer after payment.
     *
     * Required: dominio, authcode, idcontacto, successUrl, cancelUrl.
     * Optional: years, whoisPrivacy, autorenew, nameservers, contacto[*].
     */
    private function handleCreateTransferCheckout(): void
    {
        $dominio = trim((string)$this->request->get('dominio', ''));
        $authcode = trim((string)$this->request->get('authcode', ''));
        $idcontacto = $this->getRequestInt('idcontacto');
        $successUrl = $this->request->get('successUrl', '');
        $cancelUrl = $this->request->get('cancelUrl', '');
        $years = $this->getRequestInt('years') ?: 1;

        if (empty($dominio)) {
            $this->sendJsonResponse(['error' => 'Missing dominio'], 400);
            return;
        }
        if (empty($authcode)) {
            $this->sendJsonResponse(['error' => 'Missing authcode'], 400);
            return;
        }
        if (!$idcontacto) {
            $this->sendJsonResponse(['error' => 'Missing idcontacto'], 400);
            return;
        }
        if (empty($successUrl) || empty($cancelUrl)) {
            $this->sendJsonResponse(['error' => 'Missing successUrl or cancelUrl'], 400);
            return;
        }

        $contacto = new Contacto();
        if (!$contacto->load($idcontacto)) {
            $this->sendJsonResponse(['error' => 'Contact not found'], 404);
            return;
        }

        if (!StripeHelper::isConfigured()) {
            $this->sendJsonResponse(['error' => 'Stripe not configured'], 500);
            return;
        }

        // Transfer pricing: same as 1-year registration price for the TLD.
        $tld = strrchr($dominio, '.') ?: '.com';
        $priceFloat = (float)StripeSubscriptionManager::getDomainRenewalPrice($tld);
        $priceInCents = (int)round($priceFloat * max(1, $years) * 100);
        if ($priceInCents <= 0) {
            $this->sendJsonResponse(['error' => 'Invalid domain price'], 500);
            return;
        }

        try {
            StripeHelper::initStripe();

            $customer = StripeHelper::findOrCreateCustomer(
                $contacto->email,
                $contacto->fullName(),
                [
                    'idcontacto' => $contacto->idcontacto,
                    'codcliente' => $contacto->codcliente ?? ''
                ]
            );
            if (!$customer) {
                $this->sendJsonResponse(['error' => 'Could not create Stripe customer'], 500);
                return;
            }

            $contactoOverride = $this->collectContactoOverride();

            $metadata = [
                'type' => 'domain_transfer',
                'idcontacto' => (string)$idcontacto,
                'domain' => $dominio,
                'years' => (string)$years,
                'authcode' => $authcode,
                'whois_privacy' => (string)((int)$this->request->get('whoisPrivacy', 0)),
                'autorenew' => (string)((int)$this->request->get('autorenew', 1)),
            ];
            $nameservers = trim((string)$this->request->get('nameservers', ''));
            if ($nameservers !== '') {
                $metadata['nameservers'] = $nameservers;
            }
            if (!empty($contactoOverride)) {
                $metadata['contacto_override'] = substr(json_encode($contactoOverride), 0, 480);
            }

            $session = \Stripe\Checkout\Session::create([
                'customer' => $customer->id,
                'mode' => 'payment',
                'automatic_tax' => ['enabled' => true],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $priceInCents,
                        'product_data' => [
                            'name' => 'Traspaso de dominio: ' . $dominio,
                            'description' => sprintf('Traspaso + %d año(s)', $years)
                        ],
                        'tax_behavior' => 'exclusive'
                    ],
                    'quantity' => 1
                ]],
                'success_url' => $successUrl . (strpos($successUrl, '?') !== false ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata
            ]);

            SolwedLogger::stripe(sprintf(
                'Domain transfer checkout created: %s for %s (%d years, %.2f EUR)',
                $session->id,
                $dominio,
                $years,
                $priceFloat * $years
            ));

            $this->sendJsonResponse([
                'success' => true,
                'checkoutUrl' => $session->url,
                'sessionId' => $session->id
            ]);
        } catch (Exception $e) {
            SolwedLogger::error('Error creating transfer checkout: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Optional `contacto[*]` request fields override the FS Contacto when
     * the buyer wants to register the domain under a different titular.
     */
    private function collectContactoOverride(): array
    {
        $fields = ['firstName', 'lastName', 'orgName', 'identNumber', 'email', 'phone',
                   'address', 'city', 'state', 'postalCode', 'country', 'type'];
        $out = [];
        foreach ($fields as $f) {
            $v = trim((string)$this->request->get('contacto_' . $f, ''));
            if ($v !== '') $out[$f] = $v;
        }
        return $out;
    }

    // ── Helpers ────────────────────────────────────────────────

    private function loadDominioByName(string $dominio): ?Dominio
    {
        $model = new Dominio();
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('nombre', $dominio)];
        $found = $model->all($where, [], 0, 1);
        return !empty($found) ? $found[0] : null;
    }

    /**
     * Verify the requesting idcontacto owns the domain (skip if no idcontacto provided).
     * idcontacto in query is required; protects against cross-account writes.
     */
    private function verifyDomainOwnership(string $dominio): bool
    {
        $idcontacto = $this->getRequestInt('idcontacto');
        if (!$idcontacto) {
            return false;
        }
        $model = $this->loadDominioByName($dominio);
        if (!$model) {
            // Domain not in local DB — allow check/whois etc on external domains
            // but block writes. Caller decides per-action whether to allow.
            return true;
        }
        return (int)$model->idcontacto === $idcontacto;
    }

    private function validateToken(): bool
    {
        $token = $_SERVER['HTTP_TOKEN'] ?? '';
        if (empty($token)) {
            $this->sendJsonResponse(['error' => 'Token required'], 401);
            return false;
        }
        $db = new \FacturaScripts\Core\Base\DataBase();
        $db->connect();
        $result = $db->select("SELECT 1 FROM api_keys WHERE apikey = " . $db->var2str($token) . " AND enabled = true LIMIT 1");
        if (!empty($result)) {
            return true;
        }
        $this->sendJsonResponse(['error' => 'Token inválido'], 401);
        return false;
    }

    /**
     * Helper to get integer from request
     */
    private function getRequestInt(string $key): int
    {
        $value = $this->request->get($key, 0);
        return (int) $value;
    }

    /**
     * Send JSON response
     */
    private function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
