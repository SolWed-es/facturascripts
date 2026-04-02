<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 * Manager para gestionar los servicios contratados por clientes
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\SolwedES\Model\Suscripcion;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;

/**
 * Gestiona los servicios contratados por clientes
 */
class ClienteServiciosManager
{
    // Estados de servicio (mapped from Suscripcion)
    public const ESTADO_ACTIVO = Suscripcion::ESTADO_ACTIVO;
    public const ESTADO_POR_VENCER = 'por_vencer';
    public const ESTADO_VENCIDO = Suscripcion::ESTADO_VENCIDO;
    public const ESTADO_CANCELADO = Suscripcion::ESTADO_CANCELADO;

    // Días para considerar "próximo a vencer"
    public const DIAS_ALERTA_VENCIMIENTO = 30;

    /**
     * Obtiene todos los servicios contratados de un cliente
     *
     * @param string $codcliente
     * @param int|null $idcontacto Optional contact ID for proper SSO access filtering
     * @return array
     */
    public static function getServiciosCliente(string $codcliente, ?int $idcontacto = null): array
    {
        $servicios = [];

        // Get contact IDs for this client
        $contactIds = [];
        if ($idcontacto !== null) {
            $contactIds = [$idcontacto];
        } else {
            $contacto = new Contacto();
            $contactWhere = [new DataBaseWhere('codcliente', $codcliente)];
            $contactos = $contacto->all($contactWhere);
            foreach ($contactos as $c) {
                $contactIds[] = $c->idcontacto;
            }
        }

        if (empty($contactIds)) {
            return [];
        }

        // Get all contracts for these contacts
        $suscripcionModel = new Suscripcion();
        $where = [
            new DataBaseWhere('idcontacto', implode(',', $contactIds), 'IN'),
            new DataBaseWhere('estado', Suscripcion::ESTADO_CANCELADO, '!=')
        ];
        $suscripciones = $suscripcionModel->all($where, ['fecha_vencimiento' => 'ASC']);

        foreach ($suscripciones as $suscripcion) {
            $servicioData = self::procesarSuscripcion($suscripcion, $contactIds);
            if ($servicioData) {
                $servicios[] = $servicioData;
            }
        }

        return $servicios;
    }

    /**
     * Procesa un contrato y extrae información del servicio
     *
     * @param Suscripcion $suscripcion
     * @param array $contactIds Array of contact IDs belonging to the client
     * @return array|null
     */
    private static function procesarSuscripcion(Suscripcion $suscripcion, array $contactIds = []): ?array
    {
        $servicio = $suscripcion->getServicio();
        if (!$servicio || empty($servicio->id)) {
            return null;
        }

        // Calcular estado y días restantes
        $diasRestantes = self::getDiasRestantes($suscripcion);
        $estado = self::getEstadoServicio($suscripcion, $diasRestantes);

        // Obtener acceso SSO si existe
        $accesoSSO = null;
        if (!empty($contactIds)) {
            foreach ($contactIds as $contactId) {
                $acceso = AccesoServicio::getByClienteServicio($contactId, $servicio->id);
                if ($acceso) {
                    $accesoSSO = $acceso;
                    break;
                }
            }
        }

        // Get cliente code from contact
        $contacto = $suscripcion->getContacto();
        $codcliente = $contacto->codcliente ?? '';

        return [
            'id_suscripcion' => $suscripcion->id,
            'servicio' => $servicio,
            'nombre' => $servicio->nombre,
            'descripcion' => $servicio->descripcion ?? '',
            'categoria' => $servicio->categoria ?? 'General',
            'icono' => $servicio->icono ?? 'fa-solid fa-cube',
            'color' => $servicio->color ?? '#6c757d',
            'precio' => $suscripcion->importe,
            'periodo' => self::getDescripcionPeriodo($suscripcion),
            'fecha_inicio' => $suscripcion->fecha_inicio,
            'fecha_renovacion' => $suscripcion->fecha_vencimiento,
            'fecha_fin' => $suscripcion->estado === Suscripcion::ESTADO_CANCELADO ? $suscripcion->fecha_vencimiento : null,
            'dias_restantes' => $diasRestantes,
            'estado' => $estado,
            'estado_label' => self::getEstadoLabel($estado),
            'estado_class' => self::getEstadoClass($estado),
            'cancelable' => self::esCancelable($suscripcion),
            'upgradeable' => self::tieneUpgradesDisponibles($servicio),
            'acceso_sso' => $accesoSSO,
            'codcliente' => $codcliente,
            'metodo_pago' => $suscripcion->metodo_pago,
            'auto_renovar' => $suscripcion->auto_renovar
        ];
    }

    /**
     * Calcula los días restantes hasta la próxima renovación
     *
     * @param Suscripcion $suscripcion
     * @return int
     */
    public static function getDiasRestantes(Suscripcion $suscripcion): int
    {
        if (empty($suscripcion->fecha_vencimiento)) {
            return -1;
        }

        $hoy = new \DateTime();
        $vencimiento = new \DateTime($suscripcion->fecha_vencimiento);
        $diff = $hoy->diff($vencimiento);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Determina el estado del servicio
     *
     * @param Suscripcion $suscripcion
     * @param int|null $diasRestantes
     * @return string
     */
    public static function getEstadoServicio(Suscripcion $suscripcion, ?int $diasRestantes = null): string
    {
        // If contract is already cancelled or suspended
        if ($suscripcion->estado === Suscripcion::ESTADO_CANCELADO) {
            return self::ESTADO_CANCELADO;
        }

        if ($diasRestantes === null) {
            $diasRestantes = self::getDiasRestantes($suscripcion);
        }

        // Si la fecha de renovación ya pasó
        if ($diasRestantes < 0) {
            return self::ESTADO_VENCIDO;
        }

        // Si está próximo a vencer
        if ($diasRestantes <= self::DIAS_ALERTA_VENCIMIENTO) {
            return self::ESTADO_POR_VENCER;
        }

        return self::ESTADO_ACTIVO;
    }

    /**
     * Obtiene la etiqueta del estado
     */
    public static function getEstadoLabel(string $estado): string
    {
        $labels = [
            self::ESTADO_ACTIVO => Tools::lang()->trans('service-status-active'),
            self::ESTADO_POR_VENCER => Tools::lang()->trans('service-status-expiring'),
            self::ESTADO_VENCIDO => Tools::lang()->trans('service-status-expired'),
            self::ESTADO_CANCELADO => Tools::lang()->trans('service-status-cancelled')
        ];

        return $labels[$estado] ?? $estado;
    }

    /**
     * Obtiene la clase CSS del estado
     */
    public static function getEstadoClass(string $estado): string
    {
        $classes = [
            self::ESTADO_ACTIVO => 'success',
            self::ESTADO_POR_VENCER => 'warning',
            self::ESTADO_VENCIDO => 'danger',
            self::ESTADO_CANCELADO => 'secondary'
        ];

        return $classes[$estado] ?? 'secondary';
    }

    /**
     * Obtiene descripción del período de facturación
     */
    private static function getDescripcionPeriodo(Suscripcion $suscripcion): string
    {
        if (empty($suscripcion->fecha_inicio) || empty($suscripcion->fecha_vencimiento)) {
            return '';
        }

        $inicio = new \DateTime($suscripcion->fecha_inicio);
        $fin = new \DateTime($suscripcion->fecha_vencimiento);
        $diff = $inicio->diff($fin);

        if ($diff->y >= 1) {
            return $diff->y == 1 ? Tools::lang()->trans('yearly') : Tools::lang()->trans('every-x-years', ['%years%' => $diff->y]);
        } elseif ($diff->m >= 1) {
            return $diff->m == 1 ? Tools::lang()->trans('monthly') : Tools::lang()->trans('every-x-months', ['%months%' => $diff->m]);
        } elseif ($diff->d >= 7) {
            $weeks = (int)($diff->d / 7);
            return $weeks == 1 ? Tools::lang()->trans('weekly') : Tools::lang()->trans('every-x-weeks', ['%weeks%' => $weeks]);
        }

        return Tools::lang()->trans('every-x-days', ['%days%' => $diff->d]);
    }

    /**
     * Verifica si el servicio es cancelable
     */
    private static function esCancelable(Suscripcion $suscripcion): bool
    {
        return $suscripcion->estado === Suscripcion::ESTADO_ACTIVO;
    }

    /**
     * Verifica si hay upgrades disponibles para un servicio
     */
    private static function tieneUpgradesDisponibles(Servicio $servicio): bool
    {
        $upgrades = self::getUpgradesDisponibles($servicio);
        return !empty($upgrades);
    }

    /**
     * Obtiene servicios disponibles para upgrade (misma categoría, mayor precio)
     */
    public static function getUpgradesDisponibles(Servicio $servicioActual): array
    {
        $servicioModel = new Servicio();
        $where = [
            new DataBaseWhere('categoria', $servicioActual->categoria),
            new DataBaseWhere('activo', true),
            new DataBaseWhere('comprable', true),
            new DataBaseWhere('precio', $servicioActual->precio, '>'),
            new DataBaseWhere('id', $servicioActual->id, '!=')
        ];

        return $servicioModel->all($where, ['precio' => 'ASC']);
    }

    /**
     * Calcula el precio prorrateado para un upgrade
     */
    public static function calcularProrrateoUpgrade(Suscripcion $suscripcionActual, Servicio $servicioNuevo): float
    {
        $diasRestantes = self::getDiasRestantes($suscripcionActual);
        if ($diasRestantes <= 0) {
            return $servicioNuevo->precio;
        }

        $precioActual = $suscripcionActual->importe;
        $diferenciaPrecio = $servicioNuevo->precio - $precioActual;

        if ($diferenciaPrecio <= 0) {
            return 0; // Es un downgrade, no hay cargo adicional
        }

        // Calcular días del período
        $diasPeriodo = self::getDiasPeriodo($suscripcionActual);
        if ($diasPeriodo <= 0) {
            return $diferenciaPrecio;
        }

        $precioPorDia = $diferenciaPrecio / $diasPeriodo;
        return round($precioPorDia * $diasRestantes, 2);
    }

    /**
     * Obtiene los días del período de facturación
     */
    private static function getDiasPeriodo(Suscripcion $suscripcion): int
    {
        if (empty($suscripcion->fecha_inicio) || empty($suscripcion->fecha_vencimiento)) {
            return 30; // Default
        }

        $inicio = new \DateTime($suscripcion->fecha_inicio);
        $fin = new \DateTime($suscripcion->fecha_vencimiento);

        return $inicio->diff($fin)->days;
    }

    /**
     * Obtiene servicios próximos a vencer
     */
    public static function getServiciosProximosAVencer(int $diasLimite = 30): array
    {
        $suscripciones = Suscripcion::getProximosAVencer($diasLimite);
        $serviciosProximos = [];

        foreach ($suscripciones as $suscripcion) {
            $contactIds = [$suscripcion->idcontacto];
            $servicioData = self::procesarSuscripcion($suscripcion, $contactIds);
            if ($servicioData) {
                $serviciosProximos[] = $servicioData;
            }
        }

        return $serviciosProximos;
    }

    /**
     * Obtiene información de un servicio específico por ID de contrato
     */
    public static function getServicioInfo(int $idSuscripcion, ?int $idcontacto = null): ?array
    {
        $suscripcion = new Suscripcion();
        if (!$suscripcion->load($idSuscripcion)) {
            return null;
        }

        $contactIds = $idcontacto !== null ? [$idcontacto] : [$suscripcion->idcontacto];

        return self::procesarSuscripcion($suscripcion, $contactIds);
    }

    /**
     * Verifica si un contrato pertenece a un cliente
     */
    public static function perteneceACliente(int $idSuscripcion, string $codcliente): bool
    {
        $suscripcion = new Suscripcion();
        if (!$suscripcion->load($idSuscripcion)) {
            return false;
        }

        $contacto = $suscripcion->getContacto();
        return $contacto->codcliente === $codcliente;
    }

    /**
     * Cancela un servicio
     */
    public static function cancelarServicio(int $idSuscripcion, string $codcliente): bool
    {
        $suscripcion = new Suscripcion();
        if (!$suscripcion->load($idSuscripcion)) {
            return false;
        }

        // Verificar que pertenece al cliente
        $contacto = $suscripcion->getContacto();
        if ($contacto->codcliente !== $codcliente) {
            return false;
        }

        $suscripcion->estado = Suscripcion::ESTADO_CANCELADO;
        $suscripcion->auto_renovar = false;

        if ($suscripcion->save()) {
            SolwedLogger::stripe("Suscripcion cancelada: ID={$idSuscripcion} Cliente={$codcliente}");
            return true;
        }

        return false;
    }
}
