<?php
/**
 * ImportarFacturasEmail - Procesador OCR con Tesseract
 * Copyright (C) 2026 SOLWED <admin@solwed.es>
 */

namespace FacturaScripts\Plugins\ImportarFacturasEmail\Lib;

use FacturaScripts\Core\Tools;

class OcrProcessor
{
    private string $tempDir;
    private string $tesseractPath;
    private string $pdfToImagePath;

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir();
        $this->tesseractPath = $this->findExecutable('tesseract');
        $this->pdfToImagePath = $this->findExecutable('pdftoppm');
    }

    /**
     * Extrae texto de un PDF usando OCR
     */
    public function extractTextFromPdf(string $pdfContent): ?string
    {
        // Guardar PDF temporalmente
        $pdfPath = $this->tempDir . '/ocr_' . uniqid() . '.pdf';
        file_put_contents($pdfPath, $pdfContent);

        try {
            // Primero intentar extraer texto directamente del PDF (si es texto)
            $directText = $this->extractDirectText($pdfPath);
            if (!empty(trim($directText)) && strlen(trim($directText)) > 100) {
                @unlink($pdfPath);
                return $directText;
            }

            // Si no hay texto, usar OCR
            $text = $this->performOcr($pdfPath);

            return $text;
        } finally {
            @unlink($pdfPath);
        }
    }

    /**
     * Intenta extraer texto directamente del PDF (sin OCR)
     */
    private function extractDirectText(string $pdfPath): string
    {
        // Usar pdftotext si existe
        $pdftotextPath = $this->findExecutable('pdftotext');
        if (!$pdftotextPath) {
            return '';
        }

        $outputPath = $this->tempDir . '/text_' . uniqid() . '.txt';

        $command = escapeshellcmd($pdftotextPath) . ' -layout '
            . escapeshellarg($pdfPath) . ' '
            . escapeshellarg($outputPath) . ' 2>/dev/null';

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            @unlink($outputPath);
            return '';
        }

        $text = file_get_contents($outputPath);
        @unlink($outputPath);

        return $text ?: '';
    }

    /**
     * Realiza OCR sobre un PDF
     */
    private function performOcr(string $pdfPath): ?string
    {
        if (!$this->tesseractPath) {
            Tools::log('ImportarFacturasEmail')->warning('Tesseract no encontrado');
            return null;
        }

        if (!$this->pdfToImagePath) {
            Tools::log('ImportarFacturasEmail')->warning('pdftoppm no encontrado');
            return null;
        }

        // Convertir PDF a imagenes
        $imageBase = $this->tempDir . '/page_' . uniqid();

        $convertCmd = escapeshellcmd($this->pdfToImagePath) . ' -png -r 300 '
            . escapeshellarg($pdfPath) . ' '
            . escapeshellarg($imageBase) . ' 2>/dev/null';

        exec($convertCmd, $output, $returnCode);

        if ($returnCode !== 0) {
            Tools::log('ImportarFacturasEmail')->warning('Error convirtiendo PDF a imagen');
            return null;
        }

        // Buscar imagenes generadas
        $images = glob($imageBase . '*.png');
        if (empty($images)) {
            return null;
        }

        // OCR cada imagen
        $fullText = '';
        foreach ($images as $imagePath) {
            $outputBase = $imagePath . '_ocr';

            $ocrCmd = escapeshellcmd($this->tesseractPath) . ' '
                . escapeshellarg($imagePath) . ' '
                . escapeshellarg($outputBase) . ' '
                . '-l spa+eng --psm 3 2>/dev/null';

            exec($ocrCmd, $output, $returnCode);

            $textPath = $outputBase . '.txt';
            if (file_exists($textPath)) {
                $fullText .= file_get_contents($textPath) . "\n";
                @unlink($textPath);
            }

            @unlink($imagePath);
        }

        return trim($fullText) ?: null;
    }

    /**
     * Busca un ejecutable en el sistema
     */
    private function findExecutable(string $name): ?string
    {
        $paths = [
            '/usr/bin/' . $name,
            '/usr/local/bin/' . $name,
            '/opt/homebrew/bin/' . $name,
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Intentar con which
        $which = trim(shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null') ?? '');
        if (!empty($which) && file_exists($which)) {
            return $which;
        }

        return null;
    }

    /**
     * Verifica si Tesseract esta disponible
     */
    public function isAvailable(): bool
    {
        return $this->tesseractPath !== null;
    }

    /**
     * Obtiene la version de Tesseract
     */
    public function getVersion(): ?string
    {
        if (!$this->tesseractPath) {
            return null;
        }

        $output = shell_exec(escapeshellcmd($this->tesseractPath) . ' --version 2>&1');
        if (preg_match('/tesseract\s+([\d.]+)/i', $output, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
