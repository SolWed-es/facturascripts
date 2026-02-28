<?php
/**
 * ImportarFacturasEmail - Procesador principal de emails con facturas
 * Copyright (C) 2026 SOLWED <admin@solwed.es>
 */

namespace FacturaScripts\Plugins\ImportarFacturasEmail\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\LineaFacturaProveedor;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\ImportarFacturasEmail\Controller\EditConfigFacturasEmail;
use FacturaScripts\Plugins\ImportarFacturasEmail\Model\FacturaEmailLog;

class EmailInvoiceProcessor
{
    private array $config;
    private ?ImapProcessor $imap = null;
    private ?OcrProcessor $ocr = null;
    private ?InvoiceParser $parser = null;

    public function __construct()
    {
        $this->config = EditConfigFacturasEmail::getConfig();
    }

    /**
     * Procesa todos los emails nuevos con PDFs
     * @return array ['processed' => int, 'errors' => int]
     */
    public function processNewEmails(): array
    {
        $result = ['processed' => 0, 'errors' => 0];

        // Verificar configuracion
        if (!$this->validateConfig()) {
            Tools::log('ImportarFacturasEmail')->error('Configuracion incompleta');
            return $result;
        }

        // Inicializar componentes
        $this->initComponents();

        // Conectar a IMAP
        if (!$this->imap->connect()) {
            Tools::log('ImportarFacturasEmail')->error('No se pudo conectar a IMAP');
            return $result;
        }

        try {
            // Obtener emails con PDFs
            $emails = $this->imap->getUnreadEmailsWithPdf();

            foreach ($emails as $email) {
                if ($this->processEmail($email)) {
                    $result['processed']++;
                } else {
                    $result['errors']++;
                }
            }
        } finally {
            $this->imap->disconnect();
        }

        return $result;
    }

    /**
     * Procesa un email individual
     */
    private function processEmail(array $email): bool
    {
        $uid = $email['uid'];

        // Verificar si ya fue procesado
        if (FacturaEmailLog::emailAlreadyProcessed((string)$uid)) {
            Tools::log('ImportarFacturasEmail')->info('Email ya procesado: ' . $uid);
            $this->imap->markAsRead($uid);
            return true;
        }

        // Crear log
        $log = new FacturaEmailLog();
        $log->email_uid = (string)$uid;
        $log->email_from = $email['from'];
        $log->email_subject = $email['subject'];

        // Procesar primer PDF encontrado
        foreach ($email['pdf_attachments'] as $attachment) {
            $log->pdf_filename = $attachment['filename'];

            try {
                // Descargar PDF
                $pdfContent = $this->imap->downloadAttachment(
                    $uid,
                    $attachment['part'],
                    $attachment['encoding']
                );

                if (empty($pdfContent)) {
                    $log->markAsError('No se pudo descargar el PDF');
                    continue;
                }

                // Extraer texto con OCR
                $ocrText = $this->ocr->extractTextFromPdf($pdfContent);

                if (empty($ocrText)) {
                    $log->ocr_text = '';
                    $log->markAsError('No se pudo extraer texto del PDF (OCR vacio)');
                    continue;
                }

                $log->ocr_text = $ocrText;

                // Parsear datos con IA
                $parsedData = $this->parser->parseInvoiceText($ocrText);

                if (empty($parsedData) || empty($parsedData['cif_proveedor'])) {
                    $log->setParsedData($parsedData ?? []);
                    $log->markForReview('No se pudo extraer CIF del proveedor');
                    continue;
                }

                $log->setParsedData($parsedData);

                // Buscar o crear proveedor
                $proveedor = $this->findOrCreateProveedor($parsedData);

                if ($proveedor === null) {
                    $log->markAsError('No se pudo crear/encontrar proveedor');
                    continue;
                }

                // Crear factura
                $factura = $this->createFactura($proveedor, $parsedData, $email, $attachment);

                if ($factura === null) {
                    $log->codproveedor = $proveedor->codproveedor;
                    $log->markAsError('No se pudo crear la factura');
                    continue;
                }

                // Adjuntar PDF
                $idfile = $this->attachPdfToInvoice($factura, $pdfContent, $attachment['filename']);

                // Marcar como procesado
                $log->markAsProcessed($factura->idfactura, $proveedor->codproveedor, $idfile);

                // Marcar email como leido
                $this->imap->markAsRead($uid);

                Tools::log('ImportarFacturasEmail')->notice(
                    'Factura creada: ' . $factura->codigo . ' - ' . $proveedor->nombre
                );

                return true;

            } catch (\Exception $e) {
                Tools::log('ImportarFacturasEmail')->error('Error procesando email: ' . $e->getMessage());
                $log->markAsError($e->getMessage());
            }
        }

        // Si no se proceso ningun PDF, guardar log de error
        if ($log->estado === FacturaEmailLog::ESTADO_PENDIENTE) {
            $log->markAsError('No se pudo procesar ningun PDF');
        }

        return false;
    }

    /**
     * Busca proveedor por CIF o lo crea si esta habilitado
     */
    private function findOrCreateProveedor(array $data): ?Proveedor
    {
        $cif = $data['cif_proveedor'] ?? null;
        if (empty($cif)) {
            return null;
        }

        // Buscar por CIF
        $proveedor = new Proveedor();
        $where = [new DataBaseWhere('cifnif', $cif)];

        if ($proveedor->loadFromCode('', $where)) {
            return $proveedor;
        }

        // No existe, crear si esta habilitado
        if (!($this->config['auto_create_supplier'] ?? false)) {
            Tools::log('ImportarFacturasEmail')->warning('Proveedor no encontrado y creacion automatica deshabilitada: ' . $cif);
            return null;
        }

        $proveedor->cifnif = $cif;
        $proveedor->nombre = $data['nombre_proveedor'] ?? 'Proveedor ' . $cif;
        $proveedor->razonsocial = $data['nombre_proveedor'] ?? 'Proveedor ' . $cif;

        if (!$proveedor->save()) {
            Tools::log('ImportarFacturasEmail')->error('Error creando proveedor: ' . $cif);
            return null;
        }

        Tools::log('ImportarFacturasEmail')->notice('Proveedor creado: ' . $proveedor->nombre);
        return $proveedor;
    }

    /**
     * Crea una factura de proveedor
     */
    private function createFactura(Proveedor $proveedor, array $data, array $email = [], array $attachment = []): ?FacturaProveedor
    {
        $factura = new FacturaProveedor();
        $factura->setSubject($proveedor);

        // Datos de la factura
        $factura->numproveedor = $data['num_factura'] ?? '';

        if (!empty($data['fecha'])) {
            $factura->fecha = $data['fecha'];
            $factura->hora = Tools::hour();
        }

        $factura->codserie = $this->config['default_codserie'] ?? 'A';

        // Comentario detallado con información del email
        $comentario = "📧 Importada automáticamente desde email\n\n";
        if (!empty($email['from'])) {
            $comentario .= "Remitente: " . $email['from'] . "\n";
        }
        if (!empty($email['subject'])) {
            $comentario .= "Asunto: " . $email['subject'] . "\n";
        }
        if (!empty($attachment['filename'])) {
            $comentario .= "Archivo: " . $attachment['filename'] . "\n";
        }
        $comentario .= "Procesado: " . date('d/m/Y H:i');

        $factura->observaciones = $comentario;

        if (!$factura->save()) {
            return null;
        }

        // Crear linea
        $linea = new LineaFacturaProveedor();
        $linea->idfactura = $factura->idfactura;
        $linea->descripcion = $data['concepto'] ?? 'Factura ' . ($data['num_factura'] ?? 'N/A');

        // Calcular base desde total o usar base directa
        $base = $data['base_imponible'] ?? null;
        $iva = $data['iva_porcentaje'] ?? 21;
        $total = $data['total'] ?? null;

        if ($base !== null) {
            $linea->pvpunitario = $base;
        } elseif ($total !== null && $iva > 0) {
            // Calcular base desde total
            $linea->pvpunitario = $total / (1 + ($iva / 100));
        } else {
            $linea->pvpunitario = $total ?? 0;
        }

        $linea->cantidad = 1;
        $linea->iva = $iva;
        $linea->recargo = 0;

        if (!$linea->save()) {
            $factura->delete();
            return null;
        }

        $factura->save();

        return $factura;
    }

    /**
     * Adjunta el PDF a la factura
     */
    private function attachPdfToInvoice(FacturaProveedor $factura, string $pdfContent, string $filename): ?int
    {
        // Guardar archivo
        $file = new AttachedFile();

        // Generar ruta unica
        $year = date('Y');
        $month = date('m');
        $storagePath = 'MyFiles/' . $year . '/' . $month;
        $fullStoragePath = FS_FOLDER . '/' . $storagePath;

        if (!is_dir($fullStoragePath)) {
            mkdir($fullStoragePath, 0755, true);
        }

        // Nombre unico
        $uniqueName = uniqid('factura_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        $filePath = $fullStoragePath . '/' . $uniqueName;

        if (file_put_contents($filePath, $pdfContent) === false) {
            Tools::log('ImportarFacturasEmail')->error('Error guardando PDF: ' . $filePath);
            return null;
        }

        // Crear registro de archivo
        $file->path = $year . '/' . $month . '/' . $uniqueName;
        $file->filename = $filename;
        $file->size = strlen($pdfContent);
        $file->mimetype = 'application/pdf';

        if (!$file->save()) {
            @unlink($filePath);
            return null;
        }

        // Relacionar con factura
        $relation = new AttachedFileRelation();
        $relation->idfile = $file->idfile;
        $relation->model = 'FacturaProveedor';
        $relation->modelid = $factura->idfactura;
        $relation->modelcode = $factura->codigo;
        $relation->nick = 'cron';
        $relation->observations = 'PDF original de factura por email';

        if (!$relation->save()) {
            return $file->idfile; // Archivo guardado aunque relacion falle
        }

        return $file->idfile;
    }

    /**
     * Valida la configuracion
     */
    private function validateConfig(): bool
    {
        $required = ['imap_host', 'imap_user', 'imap_password', 'groq_api_key'];

        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                Tools::log('ImportarFacturasEmail')->warning('Configuracion faltante: ' . $key);
                return false;
            }
        }

        return true;
    }

    /**
     * Inicializa los componentes
     */
    private function initComponents(): void
    {
        $this->imap = new ImapProcessor(
            $this->config['imap_host'],
            $this->config['imap_port'],
            $this->config['imap_user'],
            $this->config['imap_password'],
            $this->config['imap_folder'],
            $this->config['imap_ssl']
        );

        $this->ocr = new OcrProcessor();

        $this->parser = new InvoiceParser(
            $this->config['groq_api_key'],
            $this->config['groq_model']
        );
    }
}
