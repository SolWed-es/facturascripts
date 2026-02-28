<?php
/**
 * ImportarFacturasEmail - Cron para procesar emails con facturas
 * Copyright (C) 2026 SOLWED <admin@solwed.es>
 */

namespace FacturaScripts\Plugins\ImportarFacturasEmail;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\ImportarFacturasEmail\Lib\EmailInvoiceProcessor;

class Cron extends CronClass
{
    public const JOB_NAME = 'solwed-facturas-email';
    private const JOB_PERIOD = '1 hour';
    private const ENABLED = false;  // Desactivar procesamiento automático

    public function run(): void
    {
        // Si el cron está deshabilitado, no ejecutar
        if (!self::ENABLED) {
            return;
        }

        $this->job(self::JOB_NAME)
            ->every(self::JOB_PERIOD)
            ->run(function () {
                $processor = new EmailInvoiceProcessor();
                $processor->processNewEmails();
            });
    }
}
