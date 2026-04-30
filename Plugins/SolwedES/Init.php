<?php

/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES;

// Load Composer dependencies (DonDominio SDK, etc.)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Controller\ApiRoot;

/**
 * Clase de inicialización del plugin SolwedES
 */
class Init extends InitClass
{
    public function init(): void
    {
        // Cargar extensión de EditContacto (pestana Stripe)
        $this->loadExtension(new Extension\Controller\EditContacto());

        // Registrar endpoint API para productos con imágenes
        Kernel::addRoute('/api/3/productos-con-imagenes', 'ApiProductosConImagenes', -1);
        ApiRoot::addCustomResource('productos-con-imagenes');

        // Disabled 2026-04-28: ticket-files sin consumidores externos detectados.
        // Re-enable si app necesita upload/download de adjuntos via FS.
        // Kernel::addRoute('/api/3/ticket-files', 'ApiTicketFiles', -1);
        // ApiRoot::addCustomResource('ticket-files');

        // Registrar modelos SolwedES como recursos API REST.
        // Solo los que el bridge consume vía fsFetch.
        ApiRoot::addCustomResource('dominios');
        ApiRoot::addCustomResource('direccionenvios');

        // Disabled 2026-04-28: REST endpoints sin consumidores en bridge/app/mind.
        // Modelos siguen accesibles via FS admin UI (Edit*/List* controllers).
        // ApiRoot::addCustomResource('servicios');
        // ApiRoot::addCustomResource('servicioprecios');
        // ApiRoot::addCustomResource('accesoservicios');
        // ApiRoot::addCustomResource('pagostripes');
        // ApiRoot::addCustomResource('suscripciones');

        // Custom API endpoints — only routes with confirmed external consumers.
        Kernel::addRoute('/ApiHealth', 'ApiHealth', -1); // bridge adminHealth()

        // Google OAuth migrated to bridge `/auth/google/callback` (Auth Phase 2).
        // ApiOAuth.php + Model/OAuthToken.php archived to _archive/SolwedOAuth/.

        // Archived 2026-04-28 to _archive/SolwedES_disabled_controllers/.
        // Bridge owns equivalent flows:
        //   - Auth (ApiPortalLogin)         → bridge routers/auth.ts + auth-2fa.ts
        //   - Push (ApiPush + DB table)     → bridge routers/push.ts (Redis storage)
        //   - Provision (ApiProvision)      → bridge routers/provision.ts
        //   - Stripe API (ApiStripe)        → bridge routers/stripe.ts
        //   - REST resources               → bridge consumes FS native /api/3/<resource>
        //   - ApiContactSearch / ApiDevices / ApiTicketFiles / ApiBusinessDocument
        //     no consumer detected; restore from archive if a flow needs them.
        // ApiOAuth.php + Model/OAuthToken.php archived to _archive/SolwedOAuth/.
    }

    public function update(): void
    {
        // Configurar valores por defecto del portal
        $this->setupPortalSettings();

        // Configurar valores por defecto de Stripe
        $this->setupStripeSettings();

        // Google OAuth migrated to bridge — settings managed there.
        // $this->setupGoogleSettings();

        // Configurar valores por defecto de DonDominio
        $this->setupDonDominioSettings();
    }

    public function uninstall(): void
    {
        // Lógica de desinstalación si es necesaria
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

    // setupGoogleSettings() removed: Google OAuth handled by bridge
    // (`/auth/google/callback`). Redirect URI configured in bridge env, not FS.

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
