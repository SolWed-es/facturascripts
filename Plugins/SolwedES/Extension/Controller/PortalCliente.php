<?php

/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Extensión del Portal Cliente para mostrar y gestionar servicios
 *
 * IMPORTANTE: En FacturaScripts, las clases de Extension/Controller/
 * solo pueden tener métodos públicos que retornen Closure.
 * Toda la lógica auxiliar debe estar en clases de Lib/ o inline.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\SolwedES\Lib\PortalServiciosRenderer;
use FacturaScripts\Plugins\SolwedES\Lib\ServiceAccessManager;
use FacturaScripts\Plugins\SolwedES\Lib\ClienteServiciosManager;
use FacturaScripts\Plugins\SolwedES\Lib\ServiceUpgradeManager;
use FacturaScripts\Plugins\SolwedES\Lib\StripeSubscriptionManager;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;
use FacturaScripts\Plugins\SolwedES\Model\ContratServicio;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * Extensión del controlador PortalCliente
 *
 * @mixin \FacturaScripts\Plugins\PortalCliente\Controller\PortalCliente
 * @property \FacturaScripts\Dinamic\Model\Contacto $contact
 * @property \Symfony\Component\HttpFoundation\Request $request
 * @property \Symfony\Component\HttpFoundation\Response $response
 */
class PortalCliente
{
    /**
     * Crea las vistas del portal
     */
    public function createViews(): Closure
    {
        return function () {
            // Cargar assets CSS del tema SOLWED (desde Dinamic/Assets para acceso HTTP)
            AssetManager::addCss(FS_ROUTE . '/Dinamic/Assets/CSS/portal-solwed-custom.css');
            AssetManager::addCss(FS_ROUTE . '/Dinamic/Assets/CSS/custom.css'); // Personalizaciones (prioridad)
            AssetManager::addJs(FS_ROUTE . '/Dinamic/Assets/JS/servicios-portal.js');
            AssetManager::addJs(FS_ROUTE . '/Dinamic/Assets/JS/mis-servicios.js');
            AssetManager::addJs(FS_ROUTE . '/Dinamic/Assets/JS/stripe-subscriptions.js');

            // Vista unificada de "Programas SOLWED" (servicios contratados y disponibles)
            $this->addHtmlView(
                'PortalServiciosSolwed',
                'Tab/PortalServiciosSolwed',
                'Servicio',
                'solwed-services',
                'fa-solid fa-briefcase'
            );
        };
    }

    /**
     * Carga los datos de la vista
     */
    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== 'PortalServiciosSolwed') {
                return;
            }

            // === Datos de servicios contratados (Mis Servicios) ===
            if ($this->contact && !empty($this->contact->codcliente)) {
                // FIXED: Pass idcontacto to ensure SSO access is filtered by the logged-in contact
                $servicios = ClienteServiciosManager::getServiciosCliente(
                    $this->contact->codcliente,
                    $this->contact->idcontacto
                );
                $view->servicios_cliente = $servicios;

                // Calcular contadores
                $activos = 0;
                $porVencer = 0;
                $vencidos = 0;

                foreach ($servicios as $servicio) {
                    switch ($servicio['estado']) {
                        case ClienteServiciosManager::ESTADO_ACTIVO:
                            $activos++;
                            break;
                        case ClienteServiciosManager::ESTADO_POR_VENCER:
                            $porVencer++;
                            break;
                        case ClienteServiciosManager::ESTADO_VENCIDO:
                            $vencidos++;
                            break;
                    }
                }

                $view->servicios_activos = $activos;
                $view->servicios_por_vencer = $porVencer;
                $view->servicios_vencidos = $vencidos;

                // Obtener servicios contratados desde ContratServicio
                $contratados = [];
                $contratos = ContratServicio::getActivosByContacto($this->contact->idcontacto);
                foreach ($contratos as $contrato) {
                    $servicio = $contrato->getServicio();
                    if ($servicio && !empty($servicio->id)) {
                        $contratados[$servicio->id] = [
                            'servicio' => $servicio,
                            'fecha_inicio' => $contrato->fecha_inicio,
                            'fecha_siguiente' => $contrato->fecha_vencimiento,
                            'id_contrato' => $contrato->id
                        ];
                    }
                }
                $view->servicios_contratados = $contratados;

                // Obtener URLs de acceso a servicios (SSO)
                $view->accesos_servicios = ServiceAccessManager::getClientAccessUrls($this->contact->idcontacto);

                // Obtener información de servicios Plesk
                $pleskServicesData = [];
                $accesos = AccesoServicio::getAllByCliente($this->contact->idcontacto);
                foreach ($accesos as $acceso) {
                    if ($acceso->tipo_acceso === 'plesk' && $acceso->activo) {
                        $servicesInfo = ServiceAccessManager::getPleskServicesInfo($acceso);
                        $pleskServicesData[$acceso->idservicio] = $servicesInfo;
                    }
                }
                $view->plesk_services = $pleskServicesData;

                // Obtener contratos activos del contacto (Stripe subscriptions)
                $view->suscripciones_stripe = ContratServicio::getActiveByStripeContacto($this->contact->idcontacto);
            } else {
                $view->servicios_cliente = [];
                $view->servicios_activos = 0;
                $view->servicios_por_vencer = 0;
                $view->servicios_vencidos = 0;
                $view->servicios_contratados = [];
                $view->accesos_servicios = [];
                $view->plesk_services = [];
                $view->suscripciones_stripe = [];
            }

            // === Datos de servicios disponibles ===
            $view->servicios_agrupados = PortalServiciosRenderer::renderPrepared();

            // Pasar el permiso de compra del contacto
            $view->pc_allow_buy = $this->contact ? $this->contact->pc_allow_buy : false;

            // REMOVED: $this->active = 'PortalCatalogue';
            // This line was incorrectly forcing the active tab, which could interfere
            // with normal tab navigation and data loading
        };
    }

    /**
     * Ejecuta las acciones del controlador
     */
    public function execPreviousAction(): Closure
    {
        return function ($action) {
            switch ($action) {
                case 'addServicioToCart':
                    $this->setTemplate(false);
                    $idServicio = $this->request->request->getInt('idservicio');
                    $cantidad = $this->request->request->getFloat('cantidad', 1);

                    if (!$this->contact || !$this->contact->pc_allow_buy) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('no-purchase-permission')
                        ]));
                        return false;
                    }

                    $servicio = new Servicio();
                    if (!$servicio->loadFromCode($idServicio)) {
                        $this->response->setStatusCode(404);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('service-not-found')
                        ]));
                        return false;
                    }

                    if (!$servicio->comprable || !$servicio->activo || empty($servicio->idproducto)) {
                        $this->response->setStatusCode(400);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('service-not-purchasable')
                        ]));
                        return false;
                    }

                    $producto = new Producto();
                    if (!$producto->loadFromCode($servicio->idproducto)) {
                        $this->response->setStatusCode(404);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('product-not-found')
                        ]));
                        return false;
                    }

                    $variante = new Variante();
                    $where = [new DataBaseWhere('idproducto', $producto->idproducto)];
                    $variantes = $variante->all($where, [], 0, 1);

                    if (empty($variantes)) {
                        $this->response->setStatusCode(404);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('product-variant-not-found')
                        ]));
                        return false;
                    }

                    $result = $this->addProductToCartAction($variantes[0]->idvariante, $cantidad);
                    $this->response->setContent(json_encode([
                        'ok' => (bool)$result,
                        'message' => $result ? Tools::lang()->trans('service-added-to-cart') : Tools::lang()->trans('error-adding-to-cart')
                    ]));
                    return false;

                case 'ssoServiceAccess':
                    $idacceso = $this->request->query->getInt('idacceso');

                    if (!$this->contact) {
                        Tools::log()->error('unauthorized-access');
                        $this->redirect($this->url());
                        return false;
                    }

                    // Generar token SSO y redirigir
                    $result = ServiceAccessManager::generateSSOUrl($idacceso, $this->contact->idcontacto);

                    if ($result['success']) {
                        $this->redirect($result['url']);
                    } else {
                        Tools::log()->error($result['error'] ?? 'Error SSO');
                        $this->redirect($this->url());
                    }
                    return false;

                case 'cancelarServicio':
                    $this->setTemplate(false);
                    $idContrato = $this->request->request->getInt('idcontrato');

                    if (!$this->contact || empty($this->contact->codcliente)) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('unauthorized-access')
                        ]));
                        return false;
                    }

                    if (!ClienteServiciosManager::perteneceACliente($idContrato, $this->contact->codcliente)) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('service-not-belongs-to-client')
                        ]));
                        return false;
                    }

                    $result = ClienteServiciosManager::cancelarServicio($idContrato, $this->contact->codcliente);
                    $this->response->setContent(json_encode([
                        'ok' => $result,
                        'message' => $result ? Tools::lang()->trans('service-cancelled-successfully') : Tools::lang()->trans('error-cancelling-service')
                    ]));
                    return false;

                case 'renovarServicio':
                    $this->setTemplate(false);
                    $idContrato = $this->request->request->getInt('idcontrato');

                    if (!$this->contact || empty($this->contact->codcliente) || !$this->contact->pc_allow_buy) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('unauthorized-access')
                        ]));
                        return false;
                    }

                    if (!ClienteServiciosManager::perteneceACliente($idContrato, $this->contact->codcliente)) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('service-not-belongs-to-client')
                        ]));
                        return false;
                    }

                    $result = ServiceUpgradeManager::renovarAnticipado($idContrato);
                    $this->response->setContent(json_encode($result));
                    return false;

                case 'upgradeServicio':
                    $this->setTemplate(false);
                    $idContrato = $this->request->request->getInt('idcontrato');
                    $idServicioNuevo = $this->request->request->getInt('idservicio_nuevo');

                    if (!$this->contact || empty($this->contact->codcliente) || !$this->contact->pc_allow_buy) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('unauthorized-access')
                        ]));
                        return false;
                    }

                    if (!ClienteServiciosManager::perteneceACliente($idContrato, $this->contact->codcliente)) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('service-not-belongs-to-client')
                        ]));
                        return false;
                    }

                    $result = ServiceUpgradeManager::procesarUpgrade($idContrato, $idServicioNuevo, $this->contact->codcliente);
                    $this->response->setContent(json_encode($result));
                    return false;

                case 'getUpgradeOptions':
                    $this->setTemplate(false);
                    $idServicio = $this->request->query->getInt('idservicio');
                    $idContrato = $this->request->query->getInt('idcontrato');

                    if (!$this->contact || empty($this->contact->codcliente)) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('unauthorized-access')
                        ]));
                        return false;
                    }

                    $servicioActual = new Servicio();
                    if (!$servicioActual->loadFromCode($idServicio)) {
                        $this->response->setStatusCode(404);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('service-not-found')
                        ]));
                        return false;
                    }

                    $upgrades = ClienteServiciosManager::getUpgradesDisponibles($servicioActual);
                    $contrato = new ContratServicio();
                    $upgradesConPrecio = [];

                    if ($contrato->loadFromCode($idContrato)) {
                        foreach ($upgrades as $upgrade) {
                            $precioProrrateo = ClienteServiciosManager::calcularProrrateoUpgrade($contrato, $upgrade);
                            $upgradesConPrecio[] = [
                                'id' => $upgrade->id,
                                'nombre' => $upgrade->nombre,
                                'descripcion' => $upgrade->descripcion,
                                'precio' => $upgrade->precio,
                                'precio_prorrateo' => $precioProrrateo,
                                'icono' => $upgrade->icono,
                                'color' => $upgrade->color,
                                'caracteristicas' => $upgrade->getCaracteristicas()
                            ];
                        }
                    }

                    $this->response->setContent(json_encode([
                        'ok' => true,
                        'upgrades' => $upgradesConPrecio
                    ]));
                    return false;

                case 'subscribeServicio':
                    // Acción para suscribirse a un servicio via Stripe
                    $this->setTemplate(false);
                    $idServicio = $this->request->request->getInt('idservicio');

                    if (!$this->contact || !$this->contact->pc_allow_buy) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('no-purchase-permission')
                        ]));
                        return false;
                    }

                    $servicio = new Servicio();
                    if (!$servicio->loadFromCode($idServicio)) {
                        $this->response->setStatusCode(404);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('service-not-found')
                        ]));
                        return false;
                    }

                    if (!$servicio->genera_suscripcion) {
                        $this->response->setStatusCode(400);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => 'Este servicio no usa suscripciones Stripe'
                        ]));
                        return false;
                    }

                    // Verificar que no tenga ya un contrato activo a este servicio
                    $contratosActivos = ContratServicio::getActiveByStripeContacto($this->contact->idcontacto);
                    foreach ($contratosActivos as $contrato) {
                        if ($contrato->idservicio === $servicio->id) {
                            $this->response->setStatusCode(400);
                            $this->response->setContent(json_encode([
                                'ok' => false,
                                'error' => 'Ya tienes una suscripción activa a este servicio'
                            ]));
                            return false;
                        }
                    }

                    // Crear sesión de checkout en Stripe
                    $successUrl = $this->url() . '&action=stripeSubscriptionSuccess';
                    $cancelUrl = $this->url();

                    $result = StripeSubscriptionManager::createCheckoutSession(
                        $this->contact->idcontacto,
                        $servicio,
                        $successUrl,
                        $cancelUrl
                    );

                    if ($result['success']) {
                        $this->response->setContent(json_encode([
                            'ok' => true,
                            'redirect' => $result['url']
                        ]));
                    } else {
                        $this->response->setStatusCode(500);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => $result['error'] ?? 'Error al crear suscripción'
                        ]));
                    }
                    return false;

                case 'stripeSubscriptionSuccess':
                    // Callback después de pago exitoso en Stripe
                    $sessionId = $this->request->query->get('session_id');

                    if (empty($sessionId)) {
                        Tools::log()->error('Sesión de Stripe no válida');
                        $this->redirect($this->url());
                        return false;
                    }

                    // Procesar la sesión completada
                    $result = StripeSubscriptionManager::processCompletedCheckout($sessionId);

                    if ($result['success']) {
                        Tools::log()->notice('Suscripción activada correctamente');
                    } else {
                        Tools::log()->error($result['error'] ?? 'Error procesando suscripción');
                    }

                    $this->redirect($this->url());
                    return false;

                case 'cancelSubscription':
                    // Cancelar contrato/suscripción de Stripe
                    $this->setTemplate(false);
                    $idContrato = $this->request->request->getInt('idsuscripcion'); // Keep param name for compatibility

                    if (!$this->contact) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('unauthorized-access')
                        ]));
                        return false;
                    }

                    $contrato = new ContratServicio();
                    if (!$contrato->loadFromCode($idContrato)) {
                        $this->response->setStatusCode(404);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => 'Contrato no encontrado'
                        ]));
                        return false;
                    }

                    // Verificar que pertenece al contacto
                    if ($contrato->idcontacto !== $this->contact->idcontacto) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('unauthorized-access')
                        ]));
                        return false;
                    }

                    // Cancelar en Stripe (al final del período) using referencia_externa
                    $result = StripeSubscriptionManager::cancelSubscription(
                        $contrato->referencia_externa,
                        false // No cancelar inmediatamente
                    );

                    $this->response->setContent(json_encode([
                        'ok' => $result,
                        'message' => $result
                            ? 'Suscripción cancelada. Seguirá activa hasta el final del período'
                            : 'Error al cancelar suscripción'
                    ]));
                    return false;

                    // =========================================================
                    // ACCIONES DE UPGRADE/DOWNGRADE STRIPE
                    // =========================================================

                case 'upgradeStripeSubscription':
                    $this->setTemplate(false);
                    $idContrato = $this->request->request->getInt('idsuscripcion'); // Keep param name for compatibility
                    $idServicioNuevo = $this->request->request->getInt('idservicio_nuevo');

                    if (!$this->contact || !$this->contact->pc_allow_buy) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('no-purchase-permission')
                        ]));
                        return false;
                    }

                    // Validar contrato pertenece al contacto
                    $contrato = new ContratServicio();
                    if (
                        !$contrato->loadFromCode($idContrato) ||
                        $contrato->idcontacto !== $this->contact->idcontacto
                    ) {
                        $this->response->setStatusCode(403);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('unauthorized-access')
                        ]));
                        return false;
                    }

                    // Cargar nuevo servicio
                    $servicioNuevo = new Servicio();
                    if (
                        !$servicioNuevo->loadFromCode($idServicioNuevo) ||
                        !$servicioNuevo->genera_suscripcion
                    ) {
                        $this->response->setStatusCode(400);
                        $this->response->setContent(json_encode([
                            'ok' => false,
                            'error' => Tools::lang()->trans('service-not-valid-for-upgrade')
                        ]));
                        return false;
                    }

                    // Procesar upgrade using referencia_externa
                    $result = StripeSubscriptionManager::updateSubscriptionPlan(
                        $contrato->referencia_externa,
                        $servicioNuevo
                    );

                    $this->response->setContent(json_encode([
                        'ok' => $result['success'],
                        'message' => $result['success']
                            ? Tools::lang()->trans('stripe-subscription-upgraded')
                            : ($result['error'] ?? 'Error al actualizar suscripción'),
                        'proration' => $result['proration_amount'] ?? 0
                    ]));
                    return false;

                case 'getStripeUpgradeOptions':
                    $this->setTemplate(false);
                    $idContrato = $this->request->query->getInt('idsuscripcion'); // Keep param name for compatibility

                    if (!$this->contact) {
                        $this->response->setStatusCode(403);
                        return false;
                    }

                    $contrato = new ContratServicio();
                    if (
                        !$contrato->loadFromCode($idContrato) ||
                        $contrato->idcontacto !== $this->contact->idcontacto
                    ) {
                        $this->response->setStatusCode(403);
                        return false;
                    }

                    $options = StripeSubscriptionManager::getUpgradeOptions($contrato->idservicio);

                    $this->response->setContent(json_encode([
                        'ok' => true,
                        'options' => $options
                    ]));
                    return false;

                    // =========================================================
                    // ACCIONES DE BILLING PORTAL
                    // =========================================================

                case 'openStripeBillingPortal':
                    $idContrato = $this->request->query->getInt('idsuscripcion'); // Keep param name for compatibility

                    if (!$this->contact) {
                        Tools::log()->error('unauthorized-access');
                        $this->redirect($this->url());
                        return false;
                    }

                    $contrato = new ContratServicio();
                    if (
                        !$contrato->loadFromCode($idContrato) ||
                        $contrato->idcontacto !== $this->contact->idcontacto
                    ) {
                        Tools::log()->error('unauthorized-access');
                        $this->redirect($this->url());
                        return false;
                    }

                    $result = StripeSubscriptionManager::createBillingPortalSession(
                        $contrato->stripe_customer_id,
                        $this->url()
                    );

                    if ($result['success']) {
                        $this->redirect($result['url']);
                    } else {
                        Tools::log()->error($result['error'] ?? 'Error opening billing portal');
                        $this->redirect($this->url());
                    }
                    return false;

                case 'getStripeSubscriptionDetails':
                    $this->setTemplate(false);
                    $idContrato = $this->request->query->getInt('idsuscripcion'); // Keep param name for compatibility

                    if (!$this->contact) {
                        $this->response->setStatusCode(403);
                        return false;
                    }

                    $contrato = new ContratServicio();
                    if (
                        !$contrato->loadFromCode($idContrato) ||
                        $contrato->idcontacto !== $this->contact->idcontacto
                    ) {
                        $this->response->setStatusCode(403);
                        return false;
                    }

                    $details = StripeSubscriptionManager::getSubscriptionDetails(
                        $contrato->referencia_externa
                    );

                    $this->response->setContent(json_encode($details));
                    return false;
            }

            // FIXED: Do NOT return false for actions not handled by this plugin.
            // Returning false would prevent the base PortalCliente from processing
            // its own actions (Albaranes, Direcciones, Facturas, etc.)
            // Return true to allow the base controller to continue processing.
            return true;
        };
    }
}
