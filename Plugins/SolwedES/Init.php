<?php

/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Controller\ApiRoot;
use FacturaScripts\Core\WorkQueue;

/**
 * Clase de inicialización del plugin SolwedES
 */
class Init extends InitClass
{
    public function init(): void
    {
        // Cargar extensión de EditContacto (pestana Stripe)
        $this->loadExtension(new Extension\Controller\EditContacto());

        // Register workers
        WorkQueue::addWorker('RedisSyncWorker', 'Model.Suscripcion.*');
        WorkQueue::addWorker('RedisSyncWorker', 'Model.PagoStripe.*');
        WorkQueue::addWorker('RedisSyncWorker', 'Model.Dominio.*');
        WorkQueue::addWorker('RedisSyncWorker', 'Model.Servicio.*');
        WorkQueue::addWorker('RedisSyncWorker', 'Model.Cliente.*');
        WorkQueue::addWorker('RedisSyncWorker', 'Model.FacturaCliente.*');
        WorkQueue::addWorker('WordPressProvisionWorker', 'solwed.provision.wordpress');
        WorkQueue::addWorker('DomainSyncWorker', 'solwed.sync.domains');

        // Registrar endpoint API para productos con imágenes
        Kernel::addRoute('/api/3/productos-con-imagenes', 'ApiProductosConImagenes', -1);
        ApiRoot::addCustomResource('productos-con-imagenes');

        // Registrar endpoint API para archivos de tickets
        Kernel::addRoute('/api/3/ticket-files', 'ApiTicketFiles', -1);
        ApiRoot::addCustomResource('ticket-files');

        // Registrar modelos SolwedES como recursos API REST
        ApiRoot::addCustomResource('servicios');
        ApiRoot::addCustomResource('servicioprecios');
        ApiRoot::addCustomResource('accesoservicios');
        ApiRoot::addCustomResource('dominios');
        ApiRoot::addCustomResource('pagostripes');
        ApiRoot::addCustomResource('suscripciones');
        ApiRoot::addCustomResource('direccionenvios');

        // Custom API endpoints
        Kernel::addRoute('/ApiSuscripcion', 'ApiSuscripcion', -1);
        Kernel::addRoute('/ApiStripe', 'ApiStripe', -1);
        Kernel::addRoute('/ApiDireccionEnvio', 'ApiDireccionEnvio', -1);
        Kernel::addRoute('/ApiOAuth', 'ApiOAuth', -1);
        Kernel::addRoute('/ApiContactSearch', 'ApiContactSearch', -1);
        Kernel::addRoute('/ApiAccesoServicio', 'ApiAccesoServicio', -1);
        Kernel::addRoute('/ApiServicio', 'ApiServicio', -1);
        Kernel::addRoute('/ApiHealth', 'ApiHealth', -1);
        Kernel::addRoute('/ApiProvision', 'ApiProvision', -1);
        Kernel::addRoute('/ApiDevices', 'ApiDevices', -1);
        Kernel::addRoute('/ApiRedis', 'ApiRedis', -1);
    }

    public function update(): void
    {
        $this->setupPortalSettings();
        $this->setupBridgeSettings();
        $this->setupStripeSettings();
        $this->setupGoogleSettings();
        $this->setupDonDominioSettings();
    }

    public function uninstall(): void
    {
        // Lógica de desinstalación si es necesaria
    }

    /**
     * Configura valores por defecto para Bridge + Redis
     */
    private function setupBridgeSettings(): void
    {
        if (empty(Tools::settings('solwed', 'bridge_url'))) {
            Tools::settingsSet('solwed', 'bridge_url', 'http://solwed-bridge:3009');
        }
        if (empty(Tools::settings('solwed', 'redis_host'))) {
            Tools::settingsSet('solwed', 'redis_host', 'redis');
        }
        if (empty(Tools::settings('solwed', 'redis_port'))) {
            Tools::settingsSet('solwed', 'redis_port', '6379');
        }
        Tools::settingsSave();
    }

    /**
     * Configura valores por defecto del portal de clientes
     */
    private function setupPortalSettings(): void
    {
        if (empty(Tools::settings('solwed', 'portal_url'))) {
            Tools::settingsSet('solwed', 'portal_url', 'https://app.solwed.es');
            Tools::settingsSave();
        }
    }

    /**
     * Configura valores por defecto para la integración Stripe
     */
    private function setupStripeSettings(): void
    {
        // Solo establecer si no existen (no sobrescribir configuración del usuario)
        if (empty(Tools::settings('stripe', 'crear_factura'))) {
            Tools::settingsSet('stripe', 'crear_factura', true);
        }
        if (empty(Tools::settings('stripe', 'crear_albaran'))) {
            Tools::settingsSet('stripe', 'crear_albaran', true);
        }
        if (empty(Tools::settings('stripe', 'enviar_email'))) {
            Tools::settingsSet('stripe', 'enviar_email', true);
        }
        if (empty(Tools::settings('stripe', 'serie_factura'))) {
            Tools::settingsSet('stripe', 'serie_factura', 'A');
        }
        if (empty(Tools::settings('stripe', 'serie_albaran'))) {
            Tools::settingsSet('stripe', 'serie_albaran', 'A');
        }

        // URL del webhook (informativo, solo lectura)
        $baseUrl = Tools::settings('default', 'site_url', '');
        if (!empty($baseUrl)) {
            Tools::settingsSet('stripe', 'webhook_url', rtrim($baseUrl, '/') . '/StripeWebhook');
        } else {
            Tools::settingsSet('stripe', 'webhook_url', 'https://erp.solwed.es/StripeWebhook');
        }

        Tools::settingsSave();
    }

    /**
     * Configura valores por defecto para Google OAuth
     */
    private function setupGoogleSettings(): void
    {
        if (empty(Tools::settings('google', 'redirect_uri'))) {
            $baseUrl = Tools::settings('default', 'site_url', 'https://erp.solwed.es');
            Tools::settingsSet('google', 'redirect_uri', rtrim($baseUrl, '/') . '/ApiOAuth?action=callback&provider=google');
            Tools::settingsSave();
        }
    }

    /**
     * Configura valores por defecto para la integracion DonDominio
     */
    private function setupDonDominioSettings(): void
    {
        // Solo establecer si no existen (no sobrescribir configuracion del usuario)
        if (empty(Tools::settings('dondominio', 'dondominio_auto_sync'))) {
            Tools::settingsSet('dondominio', 'dondominio_auto_sync', true);
        }
        if (empty(Tools::settings('dondominio', 'dondominio_create_contacts'))) {
            Tools::settingsSet('dondominio', 'dondominio_create_contacts', false);
        }
        if (empty(Tools::settings('dondominio', 'dondominio_send_notifications'))) {
            Tools::settingsSet('dondominio', 'dondominio_send_notifications', true);
        }
        if (empty(Tools::settings('dondominio', 'dondominio_default_years'))) {
            Tools::settingsSet('dondominio', 'dondominio_default_years', 1);
        }
        if (empty(Tools::settings('dondominio', 'dondominio_default_nameservers'))) {
            Tools::settingsSet('dondominio', 'dondominio_default_nameservers', 'ns1.solwed.es,ns2.solwed.es');
        }

        Tools::settingsSave();
    }
}
