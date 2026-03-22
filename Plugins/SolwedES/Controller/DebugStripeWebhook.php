<?php

/**
 * Plugin SolwedES - Debug Controller for Stripe Webhook
 *
 * ACCESS: https://erp.solwed.es/DebugStripeWebhook?action=XXX
 *
 * IMPORTANT: Remove or disable this controller in production!
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\SolwedES\Lib\StripeHelper;
use FacturaScripts\Plugins\SolwedES\Lib\DonDominioHelper;
use FacturaScripts\Plugins\SolwedES\Model\PagoStripe;
use FacturaScripts\Plugins\SolwedES\Model\ContratServicio;
use FacturaScripts\Plugins\SolwedES\Model\Dominio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Dinamic\Model\Contacto;

class DebugStripeWebhook extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Debug Stripe Webhook';
        $data['icon'] = 'fa-solid fa-bug';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        // Only allow admin users
        if (!$user->admin) {
            Tools::log()->error('Unauthorized access to DebugStripeWebhook');
            $this->response->setContent(json_encode(['error' => 'Unauthorized']));
            return;
        }

        $this->setTemplate(false);
        header('Content-Type: application/json; charset=utf-8');

        $action = $this->request->get('action', 'status');

        try {
            $result = match($action) {
                'status' => $this->testStatus(),
                'payments' => $this->listPayments(),
                'subscriptions' => $this->listSubscriptions(),
                'domains' => $this->listDomains(),
                'services' => $this->listServices(),
                'simulate_domain_reg' => $this->simulateDomainRegistration(),
                'simulate_domain_renew' => $this->simulateDomainRenewal(),
                'logs' => $this->getRecentLogs(),
                default => ['error' => 'Unknown action. Available: status, payments, subscriptions, domains, services, simulate_domain_reg, simulate_domain_renew, logs']
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
            'stripe' => [
                'configured' => StripeHelper::isConfigured(),
                'test_mode' => StripeHelper::isTestMode(),
                'secret_key' => StripeHelper::getSetting('stripe_secret_key') ? 'SET (hidden)' : 'NOT SET',
                'webhook_secret' => StripeHelper::getSetting('stripe_webhook_secret') ? 'SET (hidden)' : 'NOT SET',
                'crear_factura' => StripeHelper::getSetting('crear_factura', false),
                'crear_albaran' => StripeHelper::getSetting('crear_albaran', false),
                'enviar_email' => StripeHelper::getSetting('enviar_email', false),
                'webhook_url' => StripeHelper::getSetting('webhook_url', 'Not set'),
            ],
            'dondominio' => [
                'configured' => DonDominioHelper::isConfigured(),
            ],
            'counts' => [
                'pagos' => $this->countRecords(new PagoStripe()),
                'contratos' => $this->countRecords(new ContratServicio()),
                'dominios' => $this->countRecords(new Dominio()),
                'servicios' => $this->countRecords(new Servicio()),
            ]
        ];
    }

    /**
     * List recent payments
     * URL: ?action=payments&limit=10
     */
    private function listPayments(): array
    {
        $limit = (int)$this->request->get('limit', 10);

        $pago = new PagoStripe();
        $pagos = $pago->all([], ['id' => 'DESC'], 0, $limit);

        return [
            'action' => 'payments',
            'count' => count($pagos),
            'payments' => array_map(function($p) {
                return [
                    'id' => $p->id,
                    'fecha' => $p->fecha_pago,
                    'tipo' => $p->tipo,
                    'estado' => $p->estado,
                    'concepto' => $p->concepto,
                    'importe' => $p->importe . ' ' . $p->moneda,
                    'idcontacto' => $p->idcontacto,
                    'stripe_payment_intent' => $p->stripe_payment_intent,
                    'idfactura' => $p->idfactura,
                ];
            }, $pagos)
        ];
    }

    /**
     * List contracts (formerly subscriptions)
     * URL: ?action=subscriptions&limit=10
     */
    private function listSubscriptions(): array
    {
        $limit = (int)$this->request->get('limit', 10);

        $contrato = new ContratServicio();
        $contratos = $contrato->all([], ['id' => 'DESC'], 0, $limit);

        return [
            'action' => 'contracts',
            'count' => count($contratos),
            'contracts' => array_map(function($c) {
                return [
                    'id' => $c->id,
                    'referencia_externa' => $c->referencia_externa,
                    'stripe_customer_id' => $c->stripe_customer_id,
                    'estado' => $c->estado,
                    'idcontacto' => $c->idcontacto,
                    'idservicio' => $c->idservicio,
                    'metodo_pago' => $c->metodo_pago,
                    'fecha_inicio' => $c->fecha_inicio,
                    'fecha_proximo_pago' => $c->fecha_proximo_pago,
                ];
            }, $contratos)
        ];
    }

    /**
     * List domains
     * URL: ?action=domains&limit=10
     */
    private function listDomains(): array
    {
        $limit = (int)$this->request->get('limit', 10);

        $dom = new Dominio();
        $domains = $dom->all([], ['id' => 'DESC'], 0, $limit);

        return [
            'action' => 'domains',
            'count' => count($domains),
            'domains' => array_map(function($d) {
                return [
                    'id' => $d->id,
                    'nombre_completo' => $d->getNombreCompleto(),
                    'estado' => $d->estado,
                    'fecha_expiracion' => $d->fecha_expiracion,
                    'dondominio_id' => $d->dondominio_id,
                    'idcontacto' => $d->idcontacto,
                    'gestionado_solwed' => $d->gestionado_solwed,
                ];
            }, $domains)
        ];
    }

    /**
     * List services
     * URL: ?action=services&limit=10
     */
    private function listServices(): array
    {
        $limit = (int)$this->request->get('limit', 10);

        $serv = new Servicio();
        $services = $serv->all([], ['id' => 'DESC'], 0, $limit);

        return [
            'action' => 'services',
            'count' => count($services),
            'services' => array_map(function($s) {
                return [
                    'id' => $s->id,
                    'nombre' => $s->nombre,
                    'categoria' => $s->categoria ?? null,
                    'precio' => $s->precio ?? null,
                    'stripe_price_id' => $s->stripe_price_id ?? null,
                ];
            }, $services)
        ];
    }

    /**
     * Simulate domain registration (DRY RUN - no actual registration)
     * URL: ?action=simulate_domain_reg&domain=test.com&idcontacto=123
     */
    private function simulateDomainRegistration(): array
    {
        $domain = $this->request->get('domain', 'test-debug-' . time() . '.com');
        $idcontacto = (int)$this->request->get('idcontacto', 0);
        $years = (int)$this->request->get('years', 1);

        $result = [
            'action' => 'simulate_domain_reg',
            'dry_run' => true,
            'input' => [
                'domain' => $domain,
                'idcontacto' => $idcontacto,
                'years' => $years,
            ],
            'steps' => []
        ];

        // Step 1: Check DonDominio configuration
        $result['steps'][] = [
            'step' => 'Check DonDominio config',
            'result' => DonDominioHelper::isConfigured() ? 'OK' : 'FAIL - Not configured'
        ];

        // Step 2: Find contact
        $contacto = null;
        if ($idcontacto > 0) {
            $contacto = new Contacto();
            if ($contacto->load($idcontacto)) {
                $result['steps'][] = [
                    'step' => 'Find contact',
                    'result' => 'OK - Found: ' . $contacto->nombre
                ];
            } else {
                $result['steps'][] = [
                    'step' => 'Find contact',
                    'result' => 'FAIL - Contact not found'
                ];
            }
        } else {
            $result['steps'][] = [
                'step' => 'Find contact',
                'result' => 'SKIP - No idcontacto provided'
            ];
        }

        // Step 3: Convert contact to DonDominio format
        if ($contacto) {
            $ddContact = DonDominioHelper::contactoToDD($contacto);
            $result['steps'][] = [
                'step' => 'Convert contact to DD format',
                'result' => 'OK',
                'data' => $ddContact
            ];
        }

        // Step 4: Check domain availability (actual API call)
        if (DonDominioHelper::isConfigured()) {
            $availability = DonDominioHelper::checkAvailability($domain);
            $result['steps'][] = [
                'step' => 'Check domain availability',
                'result' => $availability['available'] ? 'AVAILABLE' : 'NOT AVAILABLE',
                'data' => $availability
            ];
        }

        // Step 5: Parse domain name
        $lastDot = strrpos($domain, '.');
        $nombre = $lastDot !== false ? substr($domain, 0, $lastDot) : $domain;
        $tld = $lastDot !== false ? substr($domain, $lastDot) : '.com';

        $result['steps'][] = [
            'step' => 'Parse domain',
            'result' => 'OK',
            'data' => ['nombre' => $nombre, 'tld' => $tld]
        ];

        // Step 6: What would be created
        $result['steps'][] = [
            'step' => 'Would create Dominio record',
            'data' => [
                'nombre' => strtolower($nombre),
                'tld' => strtolower($tld),
                'idcontacto' => $idcontacto,
                'estado' => 'active',
                'gestionado_solwed' => true,
            ]
        ];

        $result['conclusion'] = 'Dry run complete. No changes were made.';

        return $result;
    }

    /**
     * Simulate domain renewal (DRY RUN)
     * URL: ?action=simulate_domain_renew&domain=test.com
     */
    private function simulateDomainRenewal(): array
    {
        $domain = $this->request->get('domain', '');
        $years = (int)$this->request->get('years', 1);

        if (empty($domain)) {
            return ['error' => 'Missing domain parameter. Usage: ?action=simulate_domain_renew&domain=yourdomain.com'];
        }

        $result = [
            'action' => 'simulate_domain_renew',
            'dry_run' => true,
            'input' => [
                'domain' => $domain,
                'years' => $years,
            ],
            'steps' => []
        ];

        // Step 1: Find domain in FS
        $dominio = Dominio::getByNombreCompleto($domain);
        if ($dominio) {
            $result['steps'][] = [
                'step' => 'Find domain in FS',
                'result' => 'OK - Found ID: ' . $dominio->id,
                'data' => [
                    'id' => $dominio->id,
                    'estado' => $dominio->estado,
                    'fecha_expiracion' => $dominio->fecha_expiracion,
                    'dondominio_id' => $dominio->dondominio_id,
                ]
            ];
        } else {
            $result['steps'][] = [
                'step' => 'Find domain in FS',
                'result' => 'NOT FOUND - Would create new record on renewal'
            ];
        }

        // Step 2: Check DonDominio config
        $result['steps'][] = [
            'step' => 'Check DonDominio config',
            'result' => DonDominioHelper::isConfigured() ? 'OK' : 'FAIL - Not configured'
        ];

        // Step 3: Get current domain info from DonDominio
        if (DonDominioHelper::isConfigured()) {
            $info = DonDominioHelper::getDomainInfo($domain);
            $result['steps'][] = [
                'step' => 'Get domain info from DonDominio',
                'result' => $info['success'] ? 'OK' : 'FAIL',
                'data' => $info
            ];
        }

        $result['conclusion'] = 'Dry run complete. No changes were made.';

        return $result;
    }

    /**
     * Get recent logs
     * URL: ?action=logs&limit=50
     */
    private function getRecentLogs(): array
    {
        $limit = (int)$this->request->get('limit', 50);

        // Try to read from FS logs
        $logFile = FS_FOLDER . '/MyFiles/Logs/facturascripts.log';

        if (!file_exists($logFile)) {
            return [
                'action' => 'logs',
                'error' => 'Log file not found: ' . $logFile,
                'hint' => 'Check FacturaScripts Admin > Tools > Logs instead'
            ];
        }

        // Read last N lines
        $lines = file($logFile);
        $relevant = [];

        foreach (array_reverse($lines) as $line) {
            if (stripos($line, 'solwed') !== false || stripos($line, 'stripe') !== false || stripos($line, 'dondominio') !== false) {
                $relevant[] = trim($line);
                if (count($relevant) >= $limit) {
                    break;
                }
            }
        }

        return [
            'action' => 'logs',
            'count' => count($relevant),
            'filter' => 'solwed, stripe, dondominio',
            'logs' => $relevant
        ];
    }

    private function countRecords($model): int
    {
        return count($model->all([], [], 0, 0));
    }
}
