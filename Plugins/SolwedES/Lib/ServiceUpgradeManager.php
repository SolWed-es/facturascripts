<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Manager para gestionar upgrades y renovaciones de servicios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\SolwedES\Model\ContratServicio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;

/**
 * Gestiona los upgrades, downgrades y renovaciones de servicios
 */
class ServiceUpgradeManager
{
    /**
     * Procesa un upgrade de servicio
     *
     * @param int $idContrato ID del contrato actual
     * @param int $idServicioNuevo ID del nuevo servicio
     * @param string $codcliente Código del cliente
     * @return array
     */
    public static function procesarUpgrade(int $idContrato, int $idServicioNuevo, string $codcliente): array
    {
        $contrato = new ContratServicio();
        if (!$contrato->load($idContrato)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('contract-not-found')];
        }

        // Verificar que pertenece al cliente
        $contacto = $contrato->getContacto();
        if ($contacto->codcliente !== $codcliente) {
            return ['ok' => false, 'error' => Tools::lang()->trans('service-not-belongs-to-client')];
        }

        // Cargar nuevo servicio
        $servicioNuevo = new Servicio();
        if (!$servicioNuevo->load($idServicioNuevo)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('new-service-not-found')];
        }

        // Calcular precio prorrateado
        $precioProrrateo = ClienteServiciosManager::calcularProrrateoUpgrade($contrato, $servicioNuevo);

        $resultFactura = null;

        // Crear factura por la diferencia si hay cargo
        if ($precioProrrateo > 0) {
            $resultFactura = self::crearFacturaUpgrade($contrato, $servicioNuevo, $precioProrrateo);
            if (!$resultFactura['ok']) {
                return $resultFactura;
            }
        }

        // Actualizar el contrato con el nuevo servicio
        $resultActualizacion = self::actualizarContrato($contrato, $servicioNuevo);
        if (!$resultActualizacion['ok']) {
            return $resultActualizacion;
        }

        SolwedLogger::stripe("Upgrade procesado: Contrato={$idContrato} NuevoServicio={$servicioNuevo->nombre}");

        return [
            'ok' => true,
            'message' => Tools::lang()->trans('upgrade-processed-successfully'),
            'redirect' => $resultFactura['redirect'] ?? null
        ];
    }

    /**
     * Crea una factura por el upgrade
     */
    private static function crearFacturaUpgrade(ContratServicio $contrato, Servicio $servicioNuevo, float $precio): array
    {
        $contacto = $contrato->getContacto();
        if (empty($contacto->codcliente)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('contact-has-no-client')];
        }

        $cliente = new Cliente();
        if (!$cliente->load($contacto->codcliente)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('client-not-found')];
        }

        // Crear factura
        $factura = new FacturaCliente();
        $factura->setSubject($cliente);
        $factura->codserie = Tools::settings('default', 'codserie');
        $factura->codalmacen = Tools::settings('default', 'codalmacen');
        $factura->codpago = Tools::settings('default', 'codpago');
        $factura->observaciones = Tools::lang()->trans('upgrade-invoice-notes', [
            '%service%' => $servicioNuevo->nombre
        ]);

        if (!$factura->save()) {
            return ['ok' => false, 'error' => Tools::lang()->trans('error-creating-invoice')];
        }

        // Crear línea de factura
        $linea = $factura->getNewLine();
        $linea->descripcion = Tools::lang()->trans('upgrade-to-service', ['%service%' => $servicioNuevo->nombre]);
        $linea->cantidad = 1;
        $linea->pvpunitario = $precio;

        if (!empty($servicioNuevo->idproducto)) {
            $producto = new Producto();
            if ($producto->load($servicioNuevo->idproducto)) {
                $linea->idproducto = $producto->idproducto;
                $linea->referencia = $producto->referencia;
                $linea->codimpuesto = $producto->codimpuesto;
            }
        }

        if (!$linea->save()) {
            $factura->delete();
            return ['ok' => false, 'error' => Tools::lang()->trans('error-creating-invoice-line')];
        }

        // Recalcular totales
        $lines = $factura->getLines();
        \FacturaScripts\Core\Lib\Calculator::calculate($factura, $lines, true);

        return [
            'ok' => true,
            'factura' => $factura,
            'redirect' => 'PortalFactura?code=' . $factura->idfactura
        ];
    }

    /**
     * Actualiza el contrato con el nuevo servicio
     */
    private static function actualizarContrato(ContratServicio $contrato, Servicio $servicioNuevo): array
    {
        $contrato->idservicio = $servicioNuevo->id;
        $contrato->importe = $servicioNuevo->precio;

        if (!$contrato->save()) {
            return ['ok' => false, 'error' => Tools::lang()->trans('error-updating-contract')];
        }

        return ['ok' => true];
    }

    /**
     * Renueva un servicio anticipadamente
     */
    public static function renovarAnticipado(int $idContrato): array
    {
        $contrato = new ContratServicio();
        if (!$contrato->load($idContrato)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('contract-not-found')];
        }

        $contacto = $contrato->getContacto();
        if (empty($contacto->codcliente)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('contact-has-no-client')];
        }

        $cliente = new Cliente();
        if (!$cliente->load($contacto->codcliente)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('client-not-found')];
        }

        $servicio = $contrato->getServicio();
        if (!$servicio || empty($servicio->id)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('service-not-found')];
        }

        // Crear factura de renovación
        $factura = new FacturaCliente();
        $factura->setSubject($cliente);
        $factura->codserie = Tools::settings('default', 'codserie');
        $factura->codalmacen = Tools::settings('default', 'codalmacen');
        $factura->codpago = Tools::settings('default', 'codpago');
        $factura->observaciones = Tools::lang()->trans('early-renewal-notes', [
            '%name%' => $servicio->nombre
        ]);

        if (!$factura->save()) {
            return ['ok' => false, 'error' => Tools::lang()->trans('error-creating-invoice')];
        }

        // Crear línea de factura
        $linea = $factura->getNewLine();
        $linea->descripcion = $servicio->nombre . ' - ' . Tools::lang()->trans('renewal');
        $linea->cantidad = 1;
        $linea->pvpunitario = $contrato->importe;

        if (!empty($servicio->idproducto)) {
            $producto = new Producto();
            if ($producto->load($servicio->idproducto)) {
                $linea->idproducto = $producto->idproducto;
                $linea->referencia = $producto->referencia;
                $linea->codimpuesto = $producto->codimpuesto;
            }
        }

        if (!$linea->save()) {
            $factura->delete();
            return ['ok' => false, 'error' => Tools::lang()->trans('error-creating-invoice-line')];
        }

        // Recalcular totales
        $lines = $factura->getLines();
        \FacturaScripts\Core\Lib\Calculator::calculate($factura, $lines, true);

        // Extender la fecha de vencimiento
        $resultExtension = self::extenderFechaVencimiento($contrato);
        if (!$resultExtension['ok']) {
            SolwedLogger::stripe('Error extendiendo fecha de renovación: ' . $resultExtension['error']);
        }

        SolwedLogger::stripe("Renovación anticipada: Contrato={$idContrato} Factura={$factura->idfactura}");

        return [
            'ok' => true,
            'message' => Tools::lang()->trans('renewal-processed-successfully'),
            'factura' => $factura,
            'redirect' => 'PortalFactura?code=' . $factura->idfactura
        ];
    }

    /**
     * Extiende la fecha de vencimiento del contrato
     */
    private static function extenderFechaVencimiento(ContratServicio $contrato): array
    {
        if (empty($contrato->fecha_vencimiento)) {
            return ['ok' => false, 'error' => 'No hay fecha de vencimiento'];
        }

        // Calculate period based on current dates
        $inicio = new \DateTime($contrato->fecha_inicio);
        $vencimiento = new \DateTime($contrato->fecha_vencimiento);
        $diasPeriodo = $inicio->diff($vencimiento)->days;

        if ($diasPeriodo <= 0) {
            $diasPeriodo = 365; // Default to yearly
        }

        // Extend from current vencimiento
        $nuevaFecha = new \DateTime($contrato->fecha_vencimiento);
        $nuevaFecha->modify("+{$diasPeriodo} days");

        $contrato->fecha_ultimo_pago = date('Y-m-d');
        $contrato->fecha_vencimiento = $nuevaFecha->format('Y-m-d');
        $contrato->fecha_proximo_pago = $nuevaFecha->format('Y-m-d');

        if (!$contrato->save()) {
            return ['ok' => false, 'error' => 'Error guardando el contrato'];
        }

        return ['ok' => true, 'nueva_fecha' => $contrato->fecha_vencimiento];
    }

    /**
     * Procesa un downgrade de servicio
     */
    public static function procesarDowngrade(int $idContrato, int $idServicioNuevo, string $codcliente): array
    {
        $contrato = new ContratServicio();
        if (!$contrato->load($idContrato)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('contract-not-found')];
        }

        // Verificar que pertenece al cliente
        $contacto = $contrato->getContacto();
        if ($contacto->codcliente !== $codcliente) {
            return ['ok' => false, 'error' => Tools::lang()->trans('service-not-belongs-to-client')];
        }

        // Cargar nuevo servicio
        $servicioNuevo = new Servicio();
        if (!$servicioNuevo->load($idServicioNuevo)) {
            return ['ok' => false, 'error' => Tools::lang()->trans('new-service-not-found')];
        }

        // En un downgrade no se cobra, simplemente se actualiza el contrato
        $resultActualizacion = self::actualizarContrato($contrato, $servicioNuevo);
        if (!$resultActualizacion['ok']) {
            return $resultActualizacion;
        }

        SolwedLogger::stripe("Downgrade procesado: Contrato={$idContrato} NuevoServicio={$servicioNuevo->nombre}");

        return [
            'ok' => true,
            'message' => Tools::lang()->trans('downgrade-processed-successfully')
        ];
    }
}
