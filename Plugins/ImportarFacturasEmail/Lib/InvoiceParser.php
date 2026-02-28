<?php
/**
 * ImportarFacturasEmail - Parser de facturas usando Groq IA
 * Copyright (C) 2026 SOLWED <admin@solwed.es>
 */

namespace FacturaScripts\Plugins\ImportarFacturasEmail\Lib;

use FacturaScripts\Core\Tools;

class InvoiceParser
{
    private string $apiKey;
    private string $model;
    private const GROQ_API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(string $apiKey, string $model = 'llama-3.3-70b-versatile')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    /**
     * Extrae datos estructurados de texto OCR de factura
     */
    public function parseInvoiceText(string $ocrText): ?array
    {
        if (empty(trim($ocrText))) {
            return null;
        }

        $prompt = $this->buildPrompt($ocrText);

        try {
            $response = $this->callGroqApi($prompt);

            if ($response === null) {
                return null;
            }

            $parsed = $this->parseGroqResponse($response);

            return $parsed;
        } catch (\Exception $e) {
            Tools::log('ImportarFacturasEmail')->error('Error parsing invoice: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Construye el prompt para Groq
     */
    private function buildPrompt(string $ocrText): string
    {
        // Limitar texto para no exceder tokens
        $maxChars = 8000;
        if (strlen($ocrText) > $maxChars) {
            $ocrText = substr($ocrText, 0, $maxChars) . '...';
        }

        return <<<PROMPT
Extrae estos datos de la factura de proveedor (texto OCR). IMPORTANTE: El CIF/NIF del EMISOR/PROVEEDOR es quien emite la factura, NO el cliente/receptor.

Datos a extraer:
- cif_proveedor: CIF/NIF del EMISOR de la factura (quien cobra)
- nombre_proveedor: Nombre o razon social del EMISOR
- num_factura: Numero de factura
- fecha: Fecha de factura (formato YYYY-MM-DD)
- base_imponible: Base imponible (solo numero, sin simbolo de moneda)
- iva_porcentaje: Porcentaje de IVA aplicado (solo numero)
- iva_importe: Importe del IVA (solo numero)
- total: Total factura (solo numero)
- concepto: Descripcion principal o concepto

REGLAS:
1. Si un campo no se puede determinar, usa null
2. Los importes deben ser numeros (usar punto decimal si hay decimales)
3. Si hay varios conceptos, concatenalos brevemente
4. El CIF puede tener formato A12345678, 12345678A, B-12345678, etc.
5. Busca el CIF cerca de palabras como "NIF", "CIF", "N.I.F.", "C.I.F.", "Emisor", "Proveedor"

Responde SOLO con un JSON valido, sin explicaciones ni texto adicional.

Texto de la factura:
---
{$ocrText}
---
PROMPT;
    }

    /**
     * Llama a la API de Groq
     */
    private function callGroqApi(string $prompt): ?string
    {
        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Eres un experto en extraer datos de facturas. Siempre respondes con JSON valido.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1,
            'max_tokens' => 1000
        ];

        $ch = curl_init(self::GROQ_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Tools::log('ImportarFacturasEmail')->error('Groq API curl error: ' . $error);
            return null;
        }

        if ($httpCode !== 200) {
            Tools::log('ImportarFacturasEmail')->error('Groq API HTTP ' . $httpCode . ': ' . $response);
            return null;
        }

        $result = json_decode($response, true);

        if (!isset($result['choices'][0]['message']['content'])) {
            Tools::log('ImportarFacturasEmail')->error('Groq API invalid response');
            return null;
        }

        return $result['choices'][0]['message']['content'];
    }

    /**
     * Parsea la respuesta de Groq
     */
    private function parseGroqResponse(string $response): ?array
    {
        // Limpiar posibles bloques de codigo markdown
        $response = preg_replace("/^```(json)?\s*/i", "", $response);
        $response = preg_replace('/```json\s*/i', '', $response);
        $response = preg_replace('/```\s*$/i', '', $response);
        $response = trim($response);

        $data = json_decode($response, true);

        if (!is_array($data)) {
            Tools::log('ImportarFacturasEmail')->warning('Failed to parse Groq response as JSON: ' . $response);
            return null;
        }

        // Normalizar campos
        return [
            'cif_proveedor' => $this->normalizeCif($data['cif_proveedor'] ?? null),
            'nombre_proveedor' => $this->cleanString($data['nombre_proveedor'] ?? null),
            'num_factura' => $this->cleanString($data['num_factura'] ?? null),
            'fecha' => $this->normalizeDate($data['fecha'] ?? null),
            'base_imponible' => $this->normalizeAmount($data['base_imponible'] ?? null),
            'iva_porcentaje' => $this->normalizeAmount($data['iva_porcentaje'] ?? null),
            'iva_importe' => $this->normalizeAmount($data['iva_importe'] ?? null),
            'total' => $this->normalizeAmount($data['total'] ?? null),
            'concepto' => $this->cleanString($data['concepto'] ?? null),
        ];
    }

    /**
     * Normaliza un CIF/NIF
     */
    private function normalizeCif(?string $cif): ?string
    {
        if (empty($cif)) {
            return null;
        }

        // Eliminar espacios, guiones, puntos
        $cif = preg_replace('/[\s\-\.]/', '', strtoupper($cif));

        return $cif;
    }

    /**
     * Normaliza una fecha a YYYY-MM-DD
     */
    private function normalizeDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        // Ya esta en formato correcto
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // Intentar parsear varios formatos
        $formats = [
            'd/m/Y', 'd-m-Y', 'd.m.Y',
            'Y/m/d', 'Y-m-d', 'Y.m.d',
            'd/m/y', 'd-m-y'
        ];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $date);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        // Intentar con strtotime
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /**
     * Normaliza un importe numerico
     */
    private function normalizeAmount($amount): ?float
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (is_numeric($amount)) {
            return (float)$amount;
        }

        // Eliminar caracteres no numericos excepto coma y punto
        $clean = preg_replace('/[^\d,.\-]/', '', (string)$amount);

        // Convertir coma decimal a punto
        // Si tiene coma y punto, la coma es miles
        if (strpos($clean, ',') !== false && strpos($clean, '.') !== false) {
            if (strrpos($clean, ',') > strrpos($clean, '.')) {
                // 1.234,56 -> coma es decimal
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                // 1,234.56 -> punto es decimal
                $clean = str_replace(',', '', $clean);
            }
        } elseif (strpos($clean, ',') !== false) {
            // Solo coma -> asumir decimal
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float)$clean : null;
    }

    /**
     * Limpia un string
     */
    private function cleanString(?string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        // Eliminar espacios multiples
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }

    /**
     * Verifica si la API key es valida
     */
    public function testApiKey(): bool
    {
        $ch = curl_init('https://api.groq.com/openai/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => 10
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }
}
