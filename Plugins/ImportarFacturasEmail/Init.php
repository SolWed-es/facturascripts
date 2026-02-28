<?php
/**
 * ImportarFacturasEmail - Plugin para procesar facturas de proveedores por email
 * Copyright (C) 2026 SOLWED <admin@solwed.es>
 */

namespace FacturaScripts\Plugins\ImportarFacturasEmail;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Dinamic\Model\Settings;

class Init extends InitClass
{
    public function init(): void
    {
        // Se ejecuta en cada carga si el plugin está activo
    }

    public function update(): void
    {
        // Se ejecuta al instalar o actualizar el plugin
        // La tabla se crea automáticamente desde Table/facturas_email_log.xml

        // Crear configuración por defecto si no existe
        $this->createDefaultSettings();
    }

    public function uninstall(): void
    {
        // Se ejecuta al desinstalar el plugin
    }

    /**
     * Crea la configuración por defecto del plugin
     */
    private function createDefaultSettings(): void
    {
        $settings = new Settings();

        if (false === $settings->loadFromCode('ImportarFacturasEmail')) {
            $settings->name = 'ImportarFacturasEmail';
            $settings->imap_host = 'mail.solwed.es';
            $settings->imap_port = '993';
            $settings->imap_user = 'facturas@solwed.es';
            $settings->imap_password = '';
            $settings->imap_folder = 'INBOX';
            $settings->imap_ssl = true;
            $settings->groq_api_key = '';
            $settings->groq_model = 'llama-3.3-70b-versatile';
            $settings->auto_create_supplier = true;
            $settings->default_codserie = 'A';
            $settings->save();
        }
    }
}
