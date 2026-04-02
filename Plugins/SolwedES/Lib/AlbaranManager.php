<?php

/**
 * Plugin SolwedES - Gestor de creación de albaranes
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use Exception;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\Serie;

/**
 * Gestiona la creación de albaranes desde webhooks de Stripe
 * SIN DEPENDENCIAS de SolwedES
 * v2.1 - Crea clientes automáticamente si no existen
 */
class AlbaranManager
{
    /**
     * Crea un albarán directamente desde invoice de Stripe
     * Busca cliente por email, si no existe lo crea automáticamente
     *
     * @param object $invoice Invoice object de Stripe
     * @return AlbaranCliente|null El albarán creado o null si falla
     */
    public static function createAlbaranFromStripe($invoice): ?AlbaranCliente
    {
        // 1. Verificar si ya existe albarán para este PaymentIntent (evitar duplicados)
        $paymentIntent = $invoice->payment_intent ?? null;
        if ($paymentIntent && self::checkDuplicate($paymentIntent)) {
            Tools::log('solwed')->warning(
                'Albaran already exists for PaymentIntent: ' . $invoice->payment_intent
            );
            return null;
        }

        // 2. Obtener email del customer desde Stripe
        $customerEmail = $invoice->customer_email ?? '';

        if (empty($customerEmail)) {
            Tools::log('solwed')->error('No customer email in invoice');
            return null;
        }

        // 3. Buscar o crear cliente en FacturaScripts
        $cliente = self::findOrCreateCliente($customerEmail, $invoice);
        if (!$cliente) {
            Tools::log('solwed')->error('Could not find or create client for email: ' . $customerEmail);
            return null;
        }

        try {
            // 4. Crear albarán
            $albaran = new AlbaranCliente();
            $albaran->setSubject($cliente);

            // Guardar referencia al PaymentIntent en observaciones
            $albaran->observaciones = sprintf(
                'Albarán Stripe | Subscription: %s | PaymentIntent: %s',
                $invoice->subscription ?? 'N/A',
                $invoice->payment_intent
            );

            // Marcar como pagado desde portal (pago confirmado por Stripe invoice.paid)
            $albaran->pc_paid = true;
            $albaran->pc_created = true;
            $albaran->pc_payment_intent_stripe = $invoice->payment_intent ?? '';

            // Configurar serie desde Settings
            $serieCodigo = StripeHelper::getSetting('serie_albaran', 'A');
            $serie = new Serie();
            if ($serie->load($serieCodigo)) {
                $albaran->codserie = $serie->codserie;
            }

            // Guardar el albarán primero
            if (!$albaran->save()) {
                Tools::log('solwed')->error('Could not save albaran');
                return null;
            }

            // 5. Añadir líneas desde el invoice de Stripe
            if (isset($invoice->lines) && isset($invoice->lines->data)) {
                foreach ($invoice->lines->data as $item) {
                    $linea = $albaran->getNewLine();
                    $linea->descripcion = $item->description ?? 'Suscripción Stripe';
                    $linea->pvpunitario = $item->amount / 100; // Stripe usa céntimos
                    $linea->cantidad = $item->quantity ?? 1;

                    // Calcular manualmente el pvptotal
                    $linea->pvptotal = $linea->pvpunitario * $linea->cantidad;

                    if (!$linea->save()) {
                        Tools::log('solwed')->error('Could not save albaran line');
                        $albaran->delete();
                        return null;
                    }
                }
            }

            // 6. Recalcular totales del albarán manualmente
            $total = 0;
            $lineas = $albaran->getLines();
            foreach ($lineas as $linea) {
                $total += $linea->pvptotal;
            }

            $albaran->neto = $total;
            $albaran->total = $total;
            $albaran->save();

            Tools::log('solwed')->info(sprintf(
                'Albaran created successfully: %s (Amount: %.2f EUR) for customer: %s',
                $albaran->codigo,
                $albaran->total,
                $customerEmail
            ));

            return $albaran;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error creating albaran: ' . $e->getMessage());

            // Limpiar albarán si quedó a medias
            if (isset($albaran) && $albaran->exists()) {
                $albaran->delete();
            }

            return null;
        }
    }

    /**
     * Busca cliente por email, si no existe lo crea automáticamente
     *
     * @param string $email Email del cliente
     * @param object $invoice Invoice de Stripe con datos del cliente
     * @return Cliente|null
     */
    private static function findOrCreateCliente(string $email, $invoice): ?Cliente
    {
        if (empty($email)) {
            return null;
        }

        // Buscar contacto existente por email
        $contacto = new Contacto();
        $where = [new DataBaseWhere('email', $email)];
        $contactos = $contacto->all($where, [], 0, 1);

        if (!empty($contactos)) {
            // Contacto encontrado, buscar cliente asociado
            $contacto = $contactos[0];

            if (!empty($contacto->codcliente)) {
                $cliente = new Cliente();
                if ($cliente->load($contacto->codcliente)) {
                    Tools::log('solwed')->info('Existing client found: ' . $cliente->nombre);
                    return $cliente;
                }
            }
        }

        // No existe, crear cliente automáticamente
        Tools::log('solwed')->info('Client not found, creating automatically for: ' . $email);
        return self::createClienteFromStripe($email, $invoice);
    }

    /**
     * Crea un nuevo cliente y contacto desde datos de Stripe
     *
     * @param string $email Email del cliente
     * @param object $invoice Invoice de Stripe con datos del cliente
     * @return Cliente|null
     */
    private static function createClienteFromStripe(string $email, $invoice): ?Cliente
    {
        try {
            // Extraer nombre del cliente desde Stripe
            $customerName = $invoice->customer_name ?? '';

            // Si no hay nombre, intentar extraerlo del email
            if (empty($customerName)) {
                $customerName = self::extractNameFromEmail($email);
            }

            // 1. Crear el cliente
            $cliente = new Cliente();
            $cliente->nombre = $customerName;
            $cliente->razonsocial = $customerName;
            $cliente->email = $email;
            $taxId = StripeHelper::getCustomerTaxId($invoice->customer ?? "");
            $cliente->cifnif = $taxId ?: "PENDIENTE";
            $cliente->telefono1 = $invoice->customer_phone ?? '';

            // Dirección si está disponible
            if (isset($invoice->customer_address)) {
                $addr = $invoice->customer_address;
                $cliente->direccion = $addr->line1 ?? '';
                $cliente->ciudad = $addr->city ?? '';
                $cliente->provincia = $addr->state ?? '';
                $cliente->codpostal = $addr->postal_code ?? '';
                $cliente->codpais = strtoupper($addr->country ?? 'ES');
            }

            // Observaciones
            $cliente->observaciones = sprintf(
                'Cliente creado automáticamente desde Stripe | Customer ID: %s | Fecha: %s',
                $invoice->customer ?? 'N/A',
                date('Y-m-d H:i:s')
            );

            if (!$cliente->save()) {
                Tools::log('solwed')->error('Could not save new client');
                return null;
            }

            Tools::log('solwed')->info('New client created: ' . $cliente->codcliente . ' - ' . $cliente->nombre);

            // 2. Crear contacto asociado
            $contacto = new Contacto();
            $contacto->codcliente = $cliente->codcliente;
            $contacto->nombre = $customerName;
            $contacto->email = $email;
            $contacto->cifnif = $cliente->cifnif;
            $contacto->telefono1 = $invoice->customer_phone ?? '';
            $contacto->direccion = $cliente->direccion;
            $contacto->ciudad = $cliente->ciudad;
            $contacto->provincia = $cliente->provincia;
            $contacto->codpostal = $cliente->codpostal;
            $contacto->codpais = $cliente->codpais;
            $contacto->descripcion = 'Contacto principal (Stripe)';

            if (!$contacto->save()) {
                Tools::log('solwed')->warning('Could not save contact, but client was created');
                // No fallamos, el cliente ya existe
            } else {
                Tools::log('solwed')->info('Contact created for client: ' . $cliente->codcliente);
            }

            return $cliente;
        } catch (Exception $e) {
            Tools::log('solwed')->error('Error creating client from Stripe: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extrae un nombre legible desde el email
     *
     * @param string $email Email del cliente
     * @return string Nombre extraído
     */
    private static function extractNameFromEmail(string $email): string
    {
        // Extraer la parte antes del @
        $parts = explode('@', $email);
        $localPart = $parts[0] ?? 'Cliente';

        // Convertir puntos y guiones en espacios
        $name = str_replace(['.', '_', '-'], ' ', $localPart);

        // Capitalizar cada palabra
        $name = ucwords(strtolower($name));

        return $name ?: 'Cliente Stripe';
    }

    /**
     * Verifica si ya existe un albarán para este PaymentIntent
     * Usa el campo pc_payment_intent_stripe para mayor eficiencia
     *
     * @param string $paymentIntentId ID del PaymentIntent de Stripe
     * @return bool True si ya existe
     */
    private static function checkDuplicate(string $paymentIntentId): bool
    {
        if (empty($paymentIntentId)) {
            return false;
        }

        $albaran = new AlbaranCliente();

        // Primero intentar con el campo dedicado (más eficiente)
        $where = [new DataBaseWhere('pc_payment_intent_stripe', $paymentIntentId)];
        $existentes = $albaran->all($where, [], 0, 1);

        if (!empty($existentes)) {
            return true;
        }

        // Fallback: buscar en observaciones para compatibilidad con registros antiguos
        $where = [new DataBaseWhere('observaciones', '%' . $paymentIntentId . '%', 'LIKE')];
        $existentes = $albaran->all($where, [], 0, 1);

        return !empty($existentes);
    }
}
