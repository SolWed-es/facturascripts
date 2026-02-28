<?php
/**
 * ImportarFacturasEmail - Controlador configuración del plugin
 * Copyright (C) 2026 SOLWED <admin@solwed.es>
 */

namespace FacturaScripts\Plugins\ImportarFacturasEmail\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\ImportarFacturasEmail\Lib\ImapProcessor;

class EditConfigFacturasEmail extends EditController
{
    public const SETTINGS_NAME = 'ImportarFacturasEmail';

    public function getModelClassName(): string
    {
        return 'Settings';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Config. Facturas Email';
        $data['icon'] = 'fa-solid fa-cog';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews(): void
    {
        parent::createViews();
        $this->setSettings('EditSettings', 'btnNew', false);
        $this->setSettings('EditSettings', 'btnDelete', false);
    }

    protected function loadData($viewName, $view): void
    {
        if ($viewName === 'EditSettings') {
            $code = self::SETTINGS_NAME;
            $view->loadData($code);

            // Si no existe, crear con valores por defecto
            if (false === $view->model->exists()) {
                $view->model->name = self::SETTINGS_NAME;
                $this->setDefaultValues($view->model);
            }
        }
    }

    protected function setDefaultValues($model): void
    {
        $model->imap_host = 'mail.solwed.es';
        $model->imap_port = '993';
        $model->imap_user = 'facturas@solwed.es';
        $model->imap_password = '';
        $model->imap_folder = 'INBOX';
        $model->imap_ssl = true;
        $model->groq_api_key = '';
        $model->groq_model = 'llama-3.3-70b-versatile';
        $model->auto_create_supplier = true;
        $model->default_codserie = 'A';
    }

    protected function execPreviousAction($action): bool
    {
        switch ($action) {
            case 'test-imap':
                $this->testImapConnection();
                return true;

            case 'test-groq':
                $this->testGroqConnection();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    protected function testImapConnection(): void
    {
        $settings = new Settings();
        $settings->loadFromCode(self::SETTINGS_NAME);

        try {
            $imap = new ImapProcessor(
                $settings->imap_host ?? '',
                (int)($settings->imap_port ?? 993),
                $settings->imap_user ?? '',
                $settings->imap_password ?? '',
                $settings->imap_folder ?? 'INBOX',
                (bool)($settings->imap_ssl ?? true)
            );

            if ($imap->testConnection()) {
                $count = $imap->getUnreadCount();
                Tools::log()->notice('Conexion IMAP OK. Emails no leidos: ' . $count);
            } else {
                Tools::log()->error('Error conectando a IMAP');
            }
        } catch (\Exception $e) {
            Tools::log()->error('Error IMAP: ' . $e->getMessage());
        }
    }

    protected function testGroqConnection(): void
    {
        $settings = new Settings();
        $settings->loadFromCode(self::SETTINGS_NAME);

        $apiKey = $settings->groq_api_key ?? '';
        if (empty($apiKey)) {
            Tools::log()->warning('API Key de Groq no configurada');
            return;
        }

        try {
            $ch = curl_init('https://api.groq.com/openai/v1/models');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                Tools::log()->notice('Conexion Groq IA OK');
            } else {
                Tools::log()->error('Error Groq: HTTP ' . $httpCode);
            }
        } catch (\Exception $e) {
            Tools::log()->error('Error Groq: ' . $e->getMessage());
        }
    }

    /**
     * Obtener configuracion estatica
     */
    public static function getConfig(): array
    {
        $settings = new Settings();
        $settings->loadFromCode(self::SETTINGS_NAME);

        return [
            'imap_host' => $settings->imap_host ?? 'mail.solwed.es',
            'imap_port' => (int)($settings->imap_port ?? 993),
            'imap_user' => $settings->imap_user ?? '',
            'imap_password' => $settings->imap_password ?? '',
            'imap_folder' => $settings->imap_folder ?? 'INBOX',
            'imap_ssl' => (bool)($settings->imap_ssl ?? true),
            'groq_api_key' => $settings->groq_api_key ?? '',
            'groq_model' => $settings->groq_model ?? 'llama-3.3-70b-versatile',
            'auto_create_supplier' => (bool)($settings->auto_create_supplier ?? true),
            'default_codserie' => $settings->default_codserie ?? 'A',
        ];
    }
}
