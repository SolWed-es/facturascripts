<?php

namespace FacturaScripts\Plugins\SolwedES\Controller;

use Exception;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Lib\StripeHelper;
use FacturaScripts\Plugins\SolwedES\Lib\StripeSubscriptionManager;
use FacturaScripts\Plugins\SolwedES\Lib\SolwedLogger;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Customer;

/**
 * API endpoints for Stripe operations:
 * - POST /ApiStripe?action=portal (billing portal URL)
 * - POST /ApiStripe?action=payment-intent (create payment intent)
 * - GET  /ApiStripe?action=metodos-pago (list payment methods)
 * - PUT  /ApiStripe?action=default-metodo-pago (set default payment method)
 */
class ApiStripe extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'API Stripe';
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
                case 'portal':
                    $this->handleBillingPortal();
                    break;
                case 'payment-intent':
                    $this->handlePaymentIntent();
                    break;
                case 'metodos-pago':
                    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                        $this->handleGetPaymentMethods();
                    }
                    break;
                case 'default-metodo-pago':
                    $this->handleSetDefaultPaymentMethod();
                    break;
                default:
                    $this->jsonResponse(['error' => 'Unknown action: ' . $action], 400);
            }
        } catch (Exception $e) {
            SolwedLogger::stripe('ApiStripe error: ' . $e->getMessage());
            $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleBillingPortal(): void
    {
        $body = $this->getJsonBody();
        $stripeCustomerId = $body['stripe_customer_id'] ?? '';
        $returnUrl = $body['return_url'] ?? '';

        if (empty($stripeCustomerId)) {
            $this->jsonResponse(['error' => 'stripe_customer_id required'], 400);
            return;
        }

        if (empty($returnUrl)) {
            $returnUrl = Tools::settings('solwed', 'portal_url', 'https://app.solwed.es');
        }

        $result = StripeSubscriptionManager::createBillingPortalSession($stripeCustomerId, $returnUrl);
        $this->jsonResponse($result, $result['success'] ? 200 : 400);
    }

    private function handlePaymentIntent(): void
    {
        if (!StripeHelper::initStripe()) {
            $this->jsonResponse(['error' => 'Stripe not configured'], 500);
            return;
        }

        $body = $this->getJsonBody();
        $amount = (int)(($body['amount'] ?? 0) * 100); // EUR to cents
        $currency = $body['currency'] ?? 'eur';
        $stripeCustomerId = $body['stripe_customer_id'] ?? '';
        $description = $body['description'] ?? '';
        $metadata = $body['metadata'] ?? [];

        if ($amount <= 0) {
            $this->jsonResponse(['error' => 'amount must be > 0'], 400);
            return;
        }

        try {
            $params = [
                'amount' => $amount,
                'currency' => $currency,
                'automatic_payment_methods' => ['enabled' => true],
            ];

            if (!empty($stripeCustomerId)) {
                $params['customer'] = $stripeCustomerId;
            }
            if (!empty($description)) {
                $params['description'] = $description;
            }
            if (!empty($metadata)) {
                $params['metadata'] = $metadata;
            }

            $intent = PaymentIntent::create($params);

            $this->jsonResponse([
                'success' => true,
                'client_secret' => $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function handleGetPaymentMethods(): void
    {
        if (!StripeHelper::initStripe()) {
            $this->jsonResponse(['error' => 'Stripe not configured'], 500);
            return;
        }

        $stripeCustomerId = $this->request->get('stripe_customer_id', '');
        if (empty($stripeCustomerId)) {
            $this->jsonResponse(['error' => 'stripe_customer_id required'], 400);
            return;
        }

        try {
            $methods = PaymentMethod::all([
                'customer' => $stripeCustomerId,
                'type' => 'card',
            ]);

            // Also get SEPA methods
            $sepaMethods = PaymentMethod::all([
                'customer' => $stripeCustomerId,
                'type' => 'sepa_debit',
            ]);

            $customer = Customer::retrieve($stripeCustomerId);
            $defaultMethodId = $customer->invoice_settings->default_payment_method ?? null;

            $result = [];
            foreach (array_merge($methods->data, $sepaMethods->data) as $pm) {
                $item = [
                    'id' => $pm->id,
                    'type' => $pm->type,
                    'is_default' => $pm->id === $defaultMethodId,
                ];

                if ($pm->type === 'card' && $pm->card) {
                    $item['brand'] = $pm->card->brand;
                    $item['last4'] = $pm->card->last4;
                    $item['exp_month'] = $pm->card->exp_month;
                    $item['exp_year'] = $pm->card->exp_year;
                } elseif ($pm->type === 'sepa_debit' && $pm->sepa_debit) {
                    $item['bank_code'] = $pm->sepa_debit->bank_code;
                    $item['last4'] = $pm->sepa_debit->last4;
                    $item['country'] = $pm->sepa_debit->country;
                }

                $result[] = $item;
            }

            $this->jsonResponse(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function handleSetDefaultPaymentMethod(): void
    {
        if (!StripeHelper::initStripe()) {
            $this->jsonResponse(['error' => 'Stripe not configured'], 500);
            return;
        }

        $body = $this->getJsonBody();
        $stripeCustomerId = $body['stripe_customer_id'] ?? '';
        $paymentMethodId = $body['payment_method_id'] ?? '';

        if (empty($stripeCustomerId) || empty($paymentMethodId)) {
            $this->jsonResponse(['error' => 'stripe_customer_id and payment_method_id required'], 400);
            return;
        }

        try {
            Customer::update($stripeCustomerId, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);

            $this->jsonResponse(['success' => true]);
        } catch (Exception $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
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
