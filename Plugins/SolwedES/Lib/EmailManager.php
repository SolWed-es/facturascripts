<?php

/**
 * Plugin SolwedES - Gestor de emails con documentos comerciales
 *
 * Envía emails con albaranes y facturas generados desde webhooks de Stripe.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use Exception;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\Contacto;

/**
 * Gestiona el envío de emails con documentos comerciales de Stripe
 */
class EmailManager
{
    /**
     * Envía email con el albarán y detalles del pago
     *
     * @param AlbaranCliente $albaran Albarán generado
     * @param array $paymentDetails Detalles del pago de Stripe
     * @param Contacto $contacto Contacto del cliente
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendAlbaranEmail(
        AlbaranCliente $albaran,
        array $paymentDetails,
        Contacto $contacto
    ): array {
        if (empty($contacto->email)) {
            Tools::log('solwed')->error('Contact has no email address');
            return ['success' => false, 'error' => 'Contact has no email'];
        }

        try {
            // 1. Generar PDF del albarán
            $pdfPath = self::generateAlbaranPDF($albaran);
            if (!$pdfPath || !file_exists($pdfPath)) {
                return ['success' => false, 'error' => 'Could not generate PDF'];
            }

            // 2. Construir HTML del email
            $emailBody = self::buildEmailHTML($albaran, $paymentDetails, $contacto);

            // 3. Enviar email
            $asunto = sprintf(
                'Albarán %s - Pago recibido por suscripción',
                $albaran->codigo
            );

            $sent = self::sendEmail(
                $contacto->email,
                $asunto,
                $emailBody,
                $pdfPath
            );

            // 4. Limpiar archivo temporal
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }

            if ($sent) {
                Tools::log('solwed')->info(sprintf(
                    'Email sent to %s (Albaran: %s)',
                    $contacto->email,
                    $albaran->codigo
                ));
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Email sending failed'];
            }
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error sending albaran email: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Envía email con la factura y detalles del pago
     *
     * @param FacturaCliente $factura Factura generada
     * @param array $paymentDetails Detalles del pago de Stripe
     * @param Contacto $contacto Contacto del cliente
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendFacturaEmail(
        FacturaCliente $factura,
        array $paymentDetails,
        Contacto $contacto
    ): array {
        if (empty($contacto->email)) {
            Tools::log('solwed')->error('Contact has no email address');
            return ['success' => false, 'error' => 'Contact has no email'];
        }

        try {
            // 1. Generar PDF de la factura
            $pdfPath = self::generateFacturaPDF($factura);
            if (!$pdfPath || !file_exists($pdfPath)) {
                return ['success' => false, 'error' => 'Could not generate PDF'];
            }

            // 2. Construir HTML del email
            $emailBody = self::buildFacturaEmailHTML($factura, $paymentDetails, $contacto);

            // 3. Enviar email
            $asunto = sprintf(
                'Factura %s - Pago confirmado',
                $factura->codigo
            );

            $sent = self::sendEmail(
                $contacto->email,
                $asunto,
                $emailBody,
                $pdfPath
            );

            // 4. Limpiar archivo temporal
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }

            if ($sent) {
                Tools::log('solwed')->info(sprintf(
                    'Factura email sent to %s (Factura: %s)',
                    $contacto->email,
                    $factura->codigo
                ));
                return ['success' => true];
            } else {
                return ['success' => false, 'error' => 'Email sending failed'];
            }
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error sending factura email: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Genera PDF de la factura y devuelve ruta temporal
     *
     * @param FacturaCliente $factura
     * @return string|null Ruta al archivo PDF temporal
     */
    private static function generateFacturaPDF(FacturaCliente $factura): ?string
    {
        try {
            $exportManager = new ExportManager();
            $exportManager->setOrientation('portrait');
            $exportManager->newDoc('PDF');
            $exportManager->addBusinessDocPage($factura);

            $pdfContent = $exportManager->getDoc();
            if (empty($pdfContent)) {
                Tools::log('solwed')->error('Factura PDF generation returned empty content');
                return null;
            }

            // Crear archivo temporal
            $tmpPath = sys_get_temp_dir() . '/factura_' . $factura->codigo . '_' . time() . '.pdf';
            file_put_contents($tmpPath, $pdfContent);

            Tools::log('solwed')->info('Factura PDF generated: ' . $tmpPath);
            return $tmpPath;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error generating Factura PDF: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Construye el HTML del email de factura
     *
     * @param FacturaCliente $factura
     * @param array $paymentDetails Detalles del pago de Stripe
     * @param Contacto $contacto
     * @return string HTML del email
     */
    private static function buildFacturaEmailHTML(
        FacturaCliente $factura,
        array $paymentDetails,
        Contacto $contacto
    ): string {
        $nombreCliente = $contacto->fullName();
        $codigoFactura = $factura->codigo;
        $fechaFactura = $factura->fecha;
        $totalFactura = Tools::money($factura->total);

        // Detalles del pago
        $metodoPago = $paymentDetails['brand'] ?? 'Desconocido';
        $last4 = $paymentDetails['last4'] ?? 'N/A';
        $fechaPago = $paymentDetails['fecha'] ?? date('Y-m-d H:i:s');
        $importePago = isset($paymentDetails['amount'])
            ? Tools::money($paymentDetails['amount'])
            : $totalFactura;

        // Template HTML responsive
        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura - Pago confirmado</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #2E3536 0%, #3A4344 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #F2E501;
        }
        .content {
            padding: 30px;
        }
        .info-box {
            background: #f8f9fa;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            border-left: 4px solid #F2E501;
        }
        .info-box h3 {
            margin-top: 0;
            color: #2E3536;
            font-size: 18px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #555;
        }
        .info-value {
            color: #333;
        }
        .highlight {
            color: #2E3536;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #888;
            font-size: 12px;
            background-color: #2E3536;
            color: #B0B0B0;
        }
        .footer a {
            color: #F2E501;
            text-decoration: none;
        }
        .success-badge {
            display: inline-block;
            background-color: #48BB78;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            margin: 10px 0;
        }
        @media only screen and (max-width: 600px) {
            body {
                padding: 10px;
            }
            .content {
                padding: 20px;
            }
            .info-row {
                flex-direction: column;
            }
            .info-value {
                margin-top: 5px;
                font-weight: bold;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>FACTURA EMITIDA</h1>
            <div class="success-badge">Pago Confirmado</div>
        </div>

        <div class="content">
            <p>Estimado/a <strong>{$nombreCliente}</strong>,</p>

            <p>Hemos recibido correctamente su pago. Adjunto encontrará su factura correspondiente.</p>

            <div class="info-box">
                <h3>Detalles de la Factura</h3>
                <div class="info-row">
                    <span class="info-label">Número de Factura:</span>
                    <span class="info-value highlight">{$codigoFactura}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Fecha:</span>
                    <span class="info-value">{$fechaFactura}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Importe Total:</span>
                    <span class="info-value highlight">{$totalFactura}</span>
                </div>
            </div>

            <div class="info-box">
                <h3>Detalles del Pago</h3>
                <div class="info-row">
                    <span class="info-label">Método de Pago:</span>
                    <span class="info-value">{$metodoPago}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Tarjeta:</span>
                    <span class="info-value">**** **** **** {$last4}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Fecha del Pago:</span>
                    <span class="info-value">{$fechaPago}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Importe Pagado:</span>
                    <span class="info-value highlight">{$importePago}</span>
                </div>
            </div>

            <p style="margin-top: 30px;">
                <strong>La factura completa se encuentra adjunta a este email en formato PDF.</strong>
            </p>

            <p>
                Si tiene alguna duda o necesita más información, no dude en contactarnos.
            </p>

            <p>Gracias por confiar en SOLWED.</p>

            <p style="margin-top: 30px;">
                Saludos cordiales,<br>
                <strong>Equipo SOLWED</strong>
            </p>
        </div>

        <div class="footer">
            <p>Este es un email automático generado por el sistema SOLWED.</p>
            <p>&copy; 2025 SOLUTIONS WEBSITE DESIGN SLU - Todos los derechos reservados</p>
            <p><a href="https://solwed.es">www.solwed.es</a></p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Genera PDF del albarán y devuelve ruta temporal
     *
     * @param AlbaranCliente $albaran
     * @return string|null Ruta al archivo PDF temporal
     */
    private static function generateAlbaranPDF(AlbaranCliente $albaran): ?string
    {
        try {
            $exportManager = new ExportManager();
            $exportManager->setOrientation('portrait');
            $exportManager->newDoc('PDF');
            $exportManager->addBusinessDocPage($albaran);

            $pdfContent = $exportManager->getDoc();
            if (empty($pdfContent)) {
                Tools::log('solwed')->error('PDF generation returned empty content');
                return null;
            }

            // Crear archivo temporal
            $tmpPath = sys_get_temp_dir() . '/albaran_' . $albaran->codigo . '_' . time() . '.pdf';
            file_put_contents($tmpPath, $pdfContent);

            Tools::log('solwed')->info('PDF generated: ' . $tmpPath);
            return $tmpPath;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error generating PDF: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Construye el HTML del email con los datos del albarán y pago
     *
     * @param AlbaranCliente $albaran
     * @param array $paymentDetails Detalles del pago de Stripe
     * @param Contacto $contacto
     * @return string HTML del email
     */
    private static function buildEmailHTML(
        AlbaranCliente $albaran,
        array $paymentDetails,
        Contacto $contacto
    ): string {
        $nombreCliente = $contacto->fullName();
        $codigoAlbaran = $albaran->codigo;
        $fechaAlbaran = $albaran->fecha;
        $totalAlbaran = Tools::money($albaran->total);

        // Detalles del pago
        $metodoPago = $paymentDetails['brand'] ?? 'Desconocido';
        $last4 = $paymentDetails['last4'] ?? 'N/A';
        $fechaPago = $paymentDetails['fecha'] ?? date('Y-m-d H:i:s');
        $importePago = isset($paymentDetails['amount'])
            ? Tools::money($paymentDetails['amount'])
            : $totalAlbaran;

        // Template HTML responsive
        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Albarán - Pago recibido</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 30px;
        }
        .info-box {
            background: #f8f9fa;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .info-box h3 {
            margin-top: 0;
            color: #667eea;
            font-size: 18px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #555;
        }
        .info-value {
            color: #333;
        }
        .highlight {
            color: #667eea;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #888;
            font-size: 12px;
            background-color: #f8f9fa;
        }
        .success-badge {
            display: inline-block;
            background-color: #28a745;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            margin: 10px 0;
        }
        @media only screen and (max-width: 600px) {
            body {
                padding: 10px;
            }
            .content {
                padding: 20px;
            }
            .info-row {
                flex-direction: column;
            }
            .info-value {
                margin-top: 5px;
                font-weight: bold;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Pago Recibido</h1>
            <div class="success-badge">Suscripción SOLWED</div>
        </div>

        <div class="content">
            <p>Estimado/a <strong>{$nombreCliente}</strong>,</p>

            <p>Hemos recibido correctamente el pago de su suscripción. A continuación encontrará los detalles:</p>

            <div class="info-box">
                <h3>📄 Detalles del Albarán</h3>
                <div class="info-row">
                    <span class="info-label">Número de Albarán:</span>
                    <span class="info-value highlight">{$codigoAlbaran}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Fecha:</span>
                    <span class="info-value">{$fechaAlbaran}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Importe Total:</span>
                    <span class="info-value highlight">{$totalAlbaran}</span>
                </div>
            </div>

            <div class="info-box">
                <h3>💳 Detalles del Pago</h3>
                <div class="info-row">
                    <span class="info-label">Método de Pago:</span>
                    <span class="info-value">{$metodoPago}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Últimos 4 Dígitos:</span>
                    <span class="info-value">**** **** **** {$last4}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Fecha del Pago:</span>
                    <span class="info-value">{$fechaPago}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Importe Pagado:</span>
                    <span class="info-value highlight">{$importePago}</span>
                </div>
            </div>

            <p style="margin-top: 30px;">
                <strong>El albarán completo se encuentra adjunto a este email en formato PDF.</strong>
            </p>

            <p>
                Si tiene alguna duda o necesita más información, no dude en contactarnos respondiendo a este email.
            </p>

            <p>Gracias por confiar en SOLWED.</p>

            <p style="margin-top: 30px;">
                Saludos cordiales,<br>
                <strong>Equipo SOLWED</strong>
            </p>
        </div>

        <div class="footer">
            <p>Este es un email automático generado por el sistema de suscripciones SOLWED.</p>
            <p>© 2025 SOLUTIONS WEBSITE DESIGN SLU - Todos los derechos reservados</p>
            <p><a href="https://solwed.es" style="color: #667eea; text-decoration: none;">www.solwed.es</a></p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Envía el email usando PHPMailer
     *
     * @param string $to Destinatario
     * @param string $subject Asunto
     * @param string $body Cuerpo HTML
     * @param string $attachmentPath Ruta al PDF
     * @return bool
     */
    private static function sendEmail(
        string $to,
        string $subject,
        string $body,
        string $attachmentPath
    ): bool {
        try {
            // FacturaScripts usa PHPMailer internamente
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // Configuración SMTP desde settings de FacturaScripts
            $mail->isSMTP();
            $mail->Host = Tools::settings('default', 'email_host', 'localhost');
            $mail->SMTPAuth = (bool)Tools::settings('default', 'email_auth', false);
            $mail->Username = Tools::settings('default', 'email_user', '');
            $mail->Password = Tools::settings('default', 'email_password', '');
            $mail->SMTPSecure = Tools::settings('default', 'email_encryption', 'tls');
            $mail->Port = (int)Tools::settings('default', 'email_port', 587);
            $mail->CharSet = 'UTF-8';

            // Remitente
            $fromEmail = Tools::settings('default', 'email_from', 'noreply@solwed.es');
            $fromName = Tools::settings('default', 'email_from_name', 'SOLWED');
            $mail->setFrom($fromEmail, $fromName);

            // Destinatario
            $mail->addAddress($to);

            // Asunto y cuerpo
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);

            // Adjuntar PDF
            if (file_exists($attachmentPath)) {
                $mail->addAttachment($attachmentPath, basename($attachmentPath));
            }

            // Enviar
            $result = $mail->send();

            if (!$result) {
                Tools::log('solwed')->error('PHPMailer error: ' . $mail->ErrorInfo);
            }

            return $result;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Email sending exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sends a simple text/html email without attachments
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (plain text or HTML)
     * @return bool
     */
    public static function sendSimpleEmail(string $to, string $subject, string $body): bool
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $host = Tools::settings('default', 'email_host', 'localhost');
            $auth = (bool)Tools::settings('default', 'email_auth', false);
            $user = Tools::settings('default', 'email_user', '');
            $encryption = Tools::settings('default', 'email_encryption', 'tls');
            $port = (int)Tools::settings('default', 'email_port', 587);
            $fromEmail = Tools::settings('default', 'email_from', 'noreply@solwed.es');
            $fromName = Tools::settings('default', 'email_from_name', 'SOLWED');

            SolwedLogger::stripe('DEBUG [EMAIL-1]: Sending email');
            SolwedLogger::stripe("DEBUG [EMAIL-1a]: To: {$to}");
            SolwedLogger::stripe("DEBUG [EMAIL-1b]: Subject: {$subject}");
            SolwedLogger::stripe("DEBUG [EMAIL-1c]: SMTP Host: {$host}:{$port}");
            SolwedLogger::stripe("DEBUG [EMAIL-1d]: SMTP Auth: " . ($auth ? 'YES' : 'NO') . ", User: {$user}");
            SolwedLogger::stripe("DEBUG [EMAIL-1e]: Encryption: {$encryption}");
            SolwedLogger::stripe("DEBUG [EMAIL-1f]: From: {$fromName} <{$fromEmail}>");

            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = $auth;
            $mail->Username = $user;
            $mail->Password = Tools::settings('default', 'email_password', '');
            $mail->SMTPSecure = $encryption;
            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';
            // Enable SMTP debug output to our logger
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function ($str, $level) {
                SolwedLogger::stripe("DEBUG [EMAIL-SMTP]: [{$level}] " . trim($str));
            };

            $mail->setFrom($fromEmail, $fromName);

            $mail->addAddress($to);
            $mail->Subject = $subject;

            // Detect if body contains HTML
            if (strip_tags($body) !== $body) {
                $mail->isHTML(true);
                $mail->Body = $body;
                $mail->AltBody = strip_tags($body);
            } else {
                $mail->isHTML(false);
                $mail->Body = $body;
            }

            SolwedLogger::stripe('DEBUG [EMAIL-2]: Calling PHPMailer->send()');
            $result = $mail->send();
            SolwedLogger::stripe('DEBUG [EMAIL-3]: Send result: ' . ($result ? 'SUCCESS' : 'FAILED - ' . $mail->ErrorInfo));

            return $result;
        } catch (Exception $e) {
            SolwedLogger::stripe('DEBUG [EMAIL-ERROR]: Exception: ' . $e->getMessage());
            Tools::log('solwed')->error('Simple email failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sends WordPress hosting credentials to customer
     *
     * @param Contacto $contacto Customer contact
     * @param string $domain Domain name
     * @param string $adminUrl WordPress admin URL
     * @param string $username WordPress admin username
     * @param string $password WordPress admin password
     * @param string $ftpUser FTP username
     * @param string $ftpPassword FTP password
     * @return bool
     */
    public static function sendWordPressCredentials(
        Contacto $contacto,
        string $domain,
        string $adminUrl,
        string $username,
        string $password,
        string $ftpUser,
        string $ftpPassword
    ): bool {
        if (empty($contacto->email)) {
            Tools::log('solwed')->error('Cannot send WordPress credentials: contact has no email');
            return false;
        }

        $nombreCliente = $contacto->fullName();
        $subject = "Tu hosting WordPress {$domain} - Credenciales de acceso";

        $html = self::buildWordPressCredentialsHTML(
            $nombreCliente,
            $domain,
            $adminUrl,
            $username,
            $password,
            $ftpUser,
            $ftpPassword
        );

        try {
            return self::sendSimpleEmail($contacto->email, $subject, $html);
        } catch (Exception $e) {
            Tools::log('solwed')->error('Failed to send WordPress credentials email: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sends provisioning failure alert to admin
     *
     * @param \FacturaScripts\Plugins\SolwedES\Model\Suscripcion $contrato
     * @param Contacto $contacto
     * @param string $domain
     * @param string $error Error message
     * @return bool
     */
    public static function sendProvisioningFailureAlert(
        \FacturaScripts\Plugins\SolwedES\Model\Suscripcion $suscripcion,
        Contacto $contacto,
        string $domain,
        string $error
    ): bool {
        // Get admin email from settings
        $adminEmail = Tools::settings('default', 'email_from', 'admin@solwed.es');

        $subject = "[ALERTA] Fallo en aprovisionamiento WordPress: {$domain}";

        $html = self::buildProvisioningFailureAlertHTML(
            $suscripcion,
            $contacto,
            $domain,
            $error
        );

        try {
            return self::sendSimpleEmail($adminEmail, $subject, $html);
        } catch (Exception $e) {
            Tools::log('solwed')->error('Failed to send provisioning failure alert: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Builds HTML for WordPress credentials email
     *
     * @param string $nombreCliente Customer name
     * @param string $domain Domain name
     * @param string $adminUrl WordPress admin URL
     * @param string $username WordPress admin username
     * @param string $password WordPress admin password
     * @param string $ftpUser FTP username
     * @param string $ftpPassword FTP password
     * @return string HTML content
     */
    private static function buildWordPressCredentialsHTML(
        string $nombreCliente,
        string $domain,
        string $adminUrl,
        string $username,
        string $password,
        string $ftpUser,
        string $ftpPassword
    ): string {
        $siteUrl = "https://{$domain}";
        $ftpHost = "ftp.{$domain}";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tu hosting WordPress - Credenciales</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #2E3536 0%, #3A4344 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #F2E501;
        }
        .content {
            padding: 30px;
        }
        .info-box {
            background: #f8f9fa;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            border-left: 4px solid #F2E501;
        }
        .info-box h3 {
            margin-top: 0;
            color: #2E3536;
            font-size: 18px;
        }
        .credential-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .credential-row:last-child {
            border-bottom: none;
        }
        .credential-label {
            font-weight: bold;
            color: #555;
        }
        .credential-value {
            font-family: monospace;
            background: #e9ecef;
            padding: 4px 8px;
            border-radius: 4px;
            color: #333;
        }
        .highlight {
            color: #2E3536;
            font-weight: bold;
        }
        .btn {
            display: inline-block;
            background-color: #F2E501;
            color: #2E3536;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin: 10px 0;
        }
        .btn:hover {
            background-color: #d9cc01;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #B0B0B0;
            font-size: 12px;
            background-color: #2E3536;
        }
        .footer a {
            color: #F2E501;
            text-decoration: none;
        }
        .success-badge {
            display: inline-block;
            background-color: #48BB78;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            margin: 10px 0;
        }
        @media only screen and (max-width: 600px) {
            body {
                padding: 10px;
            }
            .content {
                padding: 20px;
            }
            .credential-row {
                flex-direction: column;
            }
            .credential-value {
                margin-top: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>TU WORDPRESS ESTA LISTO</h1>
            <div class="success-badge">Hosting Activado</div>
        </div>

        <div class="content">
            <p>Estimado/a <strong>{$nombreCliente}</strong>,</p>

            <p>Tu hosting WordPress para <strong>{$domain}</strong> ha sido configurado correctamente. A continuacion encontraras todos los datos de acceso:</p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="{$siteUrl}" class="btn" style="margin-right: 10px;">Ver tu sitio web</a>
                <a href="{$adminUrl}" class="btn">Acceder a WordPress</a>
            </div>

            <div class="info-box">
                <h3>Acceso a WordPress</h3>
                <div class="credential-row">
                    <span class="credential-label">URL Admin:</span>
                    <span class="credential-value">{$adminUrl}</span>
                </div>
                <div class="credential-row">
                    <span class="credential-label">Usuario:</span>
                    <span class="credential-value">{$username}</span>
                </div>
                <div class="credential-row">
                    <span class="credential-label">Contrasena:</span>
                    <span class="credential-value">{$password}</span>
                </div>
            </div>

            <div class="info-box">
                <h3>Acceso FTP</h3>
                <div class="credential-row">
                    <span class="credential-label">Servidor:</span>
                    <span class="credential-value">{$ftpHost}</span>
                </div>
                <div class="credential-row">
                    <span class="credential-label">Puerto:</span>
                    <span class="credential-value">21</span>
                </div>
                <div class="credential-row">
                    <span class="credential-label">Usuario:</span>
                    <span class="credential-value">{$ftpUser}</span>
                </div>
                <div class="credential-row">
                    <span class="credential-label">Contrasena:</span>
                    <span class="credential-value">{$ftpPassword}</span>
                </div>
            </div>

            <div class="warning">
                <strong>IMPORTANTE - Seguridad:</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>Cambia tu contrasena de WordPress despues del primer acceso</li>
                    <li>Guarda estas credenciales en un lugar seguro</li>
                    <li>No compartas estos datos con personas no autorizadas</li>
                </ul>
            </div>

            <p>Si necesitas ayuda para configurar tu WordPress, no dudes en contactarnos. Estamos aqui para ayudarte.</p>

            <p style="margin-top: 30px;">
                Saludos cordiales,<br>
                <strong>Equipo SOLWED</strong>
            </p>
        </div>

        <div class="footer">
            <p>Este es un email automatico generado por el sistema SOLWED.</p>
            <p>&copy; 2025 SOLUTIONS WEBSITE DESIGN SLU - Todos los derechos reservados</p>
            <p><a href="https://solwed.es">www.solwed.es</a></p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Builds HTML for provisioning failure alert
     *
     * @param \FacturaScripts\Plugins\SolwedES\Model\Suscripcion $contrato
     * @param Contacto $contacto
     * @param string $domain
     * @param string $error
     * @return string HTML content
     */
    private static function buildProvisioningFailureAlertHTML(
        \FacturaScripts\Plugins\SolwedES\Model\Suscripcion $suscripcion,
        Contacto $contacto,
        string $domain,
        string $error
    ): string {
        $fechaHora = date('Y-m-d H:i:s');
        $nombreCliente = $contacto->fullName();
        $emailCliente = $contacto->email;
        $contratoId = $contrato->id;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerta - Fallo en Aprovisionamiento</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
        }
        .content {
            padding: 30px;
        }
        .info-box {
            background: #f8f9fa;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            border-left: 4px solid #dc3545;
        }
        .info-box h3 {
            margin-top: 0;
            color: #dc3545;
        }
        .error-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .error-box code {
            display: block;
            background: #fff;
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .info-row {
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #555;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #888;
            font-size: 12px;
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>FALLO EN APROVISIONAMIENTO WORDPRESS</h1>
        </div>

        <div class="content">
            <p>Se ha producido un error durante el aprovisionamiento automatico de un hosting WordPress. Se requiere intervencion manual.</p>

            <div class="info-box">
                <h3>Datos del Cliente</h3>
                <div class="info-row">
                    <span class="info-label">Nombre:</span> {$nombreCliente}
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span> {$emailCliente}
                </div>
                <div class="info-row">
                    <span class="info-label">ID Contrato:</span> {$contratoId}
                </div>
            </div>

            <div class="info-box">
                <h3>Datos del Servicio</h3>
                <div class="info-row">
                    <span class="info-label">Dominio:</span> {$domain}
                </div>
                <div class="info-row">
                    <span class="info-label">Fecha/Hora:</span> {$fechaHora}
                </div>
            </div>

            <div class="error-box">
                <strong>Error:</strong>
                <code>{$error}</code>
            </div>

            <p><strong>Acciones requeridas:</strong></p>
            <ol>
                <li>Verificar el estado del servidor Plesk</li>
                <li>Revisar los logs de aprovisionamiento</li>
                <li>Aprovisionar manualmente el hosting si es necesario</li>
                <li>Contactar al cliente para informar del estado</li>
                <li>Actualizar el estado del contrato en FacturaScripts</li>
            </ol>
        </div>

        <div class="footer">
            <p>Este es un email automatico del sistema SOLWED</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Notifies customer that recurring subscription payment failed
     * (subscription suspended until they update payment method).
     *
     * @param Contacto $contacto Customer contact
     * @param object $invoice Stripe invoice object
     * @return bool
     */
    public static function sendPaymentFailedEmail(Contacto $contacto, object $invoice): bool
    {
        if (empty($contacto->email)) {
            Tools::log('solwed')->warning('sendPaymentFailedEmail: contacto sin email');
            return false;
        }

        $importe = number_format(($invoice->amount_due ?? 0) / 100, 2, ',', '.');
        $moneda = strtoupper($invoice->currency ?? 'EUR');
        $portalUrl = Tools::settings('default', 'site_url', 'https://app.solwed.es') . '/billing';
        $subject = "Pago fallido — actualiza tu metodo de pago";

        $html = self::buildPaymentFailedHTML($contacto, $importe, $moneda, $portalUrl);

        try {
            return self::sendSimpleEmail($contacto->email, $subject, $html);
        } catch (Exception $e) {
            Tools::log('solwed')->error('Failed to send payment_failed email: ' . $e->getMessage());
            return false;
        }
    }

    private static function buildPaymentFailedHTML(
        Contacto $contacto,
        string $importe,
        string $moneda,
        string $portalUrl
    ): string {
        $nombre = htmlspecialchars($contacto->nombre ?? 'cliente', ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pago fallido</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background:#f4f4f4; }
        .container { background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
        .header { background:linear-gradient(135deg,#2E3536,#3A4344); color:#fff; padding:24px; text-align:center; }
        .header h1 { margin:0; color:#F2E501; font-size:22px; }
        .content { padding:28px; line-height:1.6; }
        .alert-box { background:#fff3cd; border-left:4px solid #f0ad4e; padding:16px; border-radius:6px; margin:16px 0; }
        .cta { display:inline-block; background:#F2E501; color:#2E3536 !important; padding:12px 28px; border-radius:6px; text-decoration:none; font-weight:bold; margin:16px 0; }
        .footer { background:#f8f9fa; padding:16px; text-align:center; color:#888; font-size:12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header"><h1>SOLWED</h1></div>
        <div class="content">
            <p>Hola <strong>{$nombre}</strong>,</p>
            <p>No hemos podido cobrar tu suscripcion ({$importe} {$moneda}). Como medida de seguridad hemos suspendido temporalmente el servicio.</p>
            <div class="alert-box">
                <strong>Que necesitas hacer?</strong> Actualiza tu metodo de pago en el portal y reanudaremos el servicio automaticamente.
            </div>
            <p style="text-align:center;">
                <a href="{$portalUrl}" class="cta">Actualizar metodo de pago</a>
            </p>
            <p>Si crees que es un error o necesitas ayuda, contacta con soporte.</p>
        </div>
        <div class="footer">SOLWED &mdash; aviso automatico</div>
    </div>
</body>
</html>
HTML;
    }
}
