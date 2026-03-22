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
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
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

        // Load domain
        $dominio = new Dominio();
        if (!$dominio->load($iddominio)) {
            $this->sendJsonResponse(['error' => 'Domain not found'], 404);
            return;
        }

        // Verify ownership if idcontacto provided
        if ($idcontacto && $dominio->idcontacto !== $idcontacto) {
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
