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
use FacturaScripts\Plugins\SolwedES\Model\ContratServicio;
use FacturaScripts\Plugins\SolwedES\Model\Servicio;
use FacturaScripts\Plugins\SolwedES\Model\AccesoServicio;

/**
 * Gestiona los servicios contratados por clientes
 */
class ClienteServiciosManager
{
    // Estados de servicio (mapped from ContratServicio)
    public const ESTADO_ACTIVO = ContratServicio::ESTADO_ACTIVO;
    public const ESTADO_POR_VENCER = 'por_vencer';
    public const ESTADO_VENCIDO = ContratServicio::ESTADO_VENCIDO;
    public const ESTADO_CANCELADO = ContratServicio::ESTADO_CANCELADO;

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
        $contratoModel = new ContratServicio();
        $where = [
            new DataBaseWhere('idcontacto', implode(',', $contactIds), 'IN'),
            new DataBaseWhere('estado', ContratServicio::ESTADO_CANCELADO, '!=')
        ];
        $contratos = $contratoModel->all($where, ['fecha_vencimiento' => 'ASC']);

        foreach ($contratos as $contrato) {
            $servicioData = self::procesarContrato($contrato, $contactIds);
            if ($servicioData) {
                $servicios[] = $servicioData;
            }
        }

        return $servicios;
    }

    /**
     * Procesa un contrato y extrae información del servicio
     *
     * @param ContratServicio $contrato
     * @param array $contactIds Array of contact IDs belonging to the client
     * @return array|null
     */
    private static function procesarContrato(ContratServicio $contrato, array $contactIds = []): ?array
    {
        $servicio = $contrato->getServicio();
        if (!$servicio || empty($servicio->id)) {
            return null;
        }

        // Calcular estado y días restantes
        $diasRestantes = self::getDiasRestantes($contrato);
        $estado = self::getEstadoServicio($contrato, $diasRestantes);

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
        $contacto = $contrato->getContacto();
        $codcliente = $contacto->codcliente ?? '';

        return [
            'id_contrato' => $contrato->id,
            'servicio' => $servicio,
            'nombre' => $servicio->nombre,
            'descripcion' => $servicio->descripcion ?? '',
            'categoria' => $servicio->categoria ?? 'General',
            'icono' => $servicio->icono ?? 'fa-solid fa-cube',
            'color' => $servicio->color ?? '#6c757d',
            'precio' => $contrato->importe,
            'periodo' => self::getDescripcionPeriodo($contrato),
            'fecha_inicio' => $contrato->fecha_inicio,
            'fecha_renovacion' => $contrato->fecha_vencimiento,
            'fecha_fin' => $contrato->estado === ContratServicio::ESTADO_CANCELADO ? $contrato->fecha_vencimiento : null,
            'dias_restantes' => $diasRestantes,
            'estado' => $estado,
            'estado_label' => self::getEstadoLabel($estado),
            'estado_class' => self::getEstadoClass($estado),
            'cancelable' => self::esCancelable($contrato),
            'upgradeable' => self::tieneUpgradesDisponibles($servicio),
            'acceso_sso' => $accesoSSO,
            'codcliente' => $codcliente,
            'metodo_pago' => $contrato->metodo_pago,
            'auto_renovar' => $contrato->auto_renovar
        ];
    }

    /**
     * Calcula los días restantes hasta la próxima renovación
     *
     * @param ContratServicio $contrato
     * @return int
     */
    public static function getDiasRestantes(ContratServicio $contrato): int
    {
        if (empty($contrato->fecha_vencimiento)) {
            return -1;
        }

        $hoy = new \DateTime();
        $vencimiento = new \DateTime($contrato->fecha_vencimiento);
        $diff = $hoy->diff($vencimiento);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Determina el estado del servicio
     *
     * @param ContratServicio $contrato
     * @param int|null $diasRestantes
     * @return string
     */
    public static function getEstadoServicio(ContratServicio $contrato, ?int $diasRestantes = null): string
    {
        // If contract is already cancelled or suspended
        if ($contrato->estado === ContratServicio::ESTADO_CANCELADO) {
            return self::ESTADO_CANCELADO;
        }

        if ($diasRestantes === null) {
            $diasRestantes = self::getDiasRestantes($contrato);
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
    private static function getDescripcionPeriodo(ContratServicio $contrato): string
    {
        if (empty($contrato->fecha_inicio) || empty($contrato->fecha_vencimiento)) {
            return '';
        }

        $inicio = new \DateTime($contrato->fecha_inicio);
        $fin = new \DateTime($contrato->fecha_vencimiento);
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
    private static function esCancelable(ContratServicio $contrato): bool
    {
        return $contrato->estado === ContratServicio::ESTADO_ACTIVO;
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
    public static function calcularProrrateoUpgrade(ContratServicio $contratoActual, Servicio $servicioNuevo): float
    {
        $diasRestantes = self::getDiasRestantes($contratoActual);
        if ($diasRestantes <= 0) {
            return $servicioNuevo->precio;
        }

        $precioActual = $contratoActual->importe;
        $diferenciaPrecio = $servicioNuevo->precio - $precioActual;

        if ($diferenciaPrecio <= 0) {
            return 0; // Es un downgrade, no hay cargo adicional
        }

        // Calcular días del período
        $diasPeriodo = self::getDiasPeriodo($contratoActual);
        if ($diasPeriodo <= 0) {
            return $diferenciaPrecio;
        }

        $precioPorDia = $diferenciaPrecio / $diasPeriodo;
        return round($precioPorDia * $diasRestantes, 2);
    }

    /**
     * Obtiene los días del período de facturación
     */
    private static function getDiasPeriodo(ContratServicio $contrato): int
    {
        if (empty($contrato->fecha_inicio) || empty($contrato->fecha_vencimiento)) {
            return 30; // Default
        }

        $inicio = new \DateTime($contrato->fecha_inicio);
        $fin = new \DateTime($contrato->fecha_vencimiento);

        return $inicio->diff($fin)->days;
    }

    /**
     * Obtiene servicios próximos a vencer
     */
    public static function getServiciosProximosAVencer(int $diasLimite = 30): array
    {
        $contratos = ContratServicio::getProximosAVencer($diasLimite);
        $serviciosProximos = [];

        foreach ($contratos as $contrato) {
            $contactIds = [$contrato->idcontacto];
            $servicioData = self::procesarContrato($contrato, $contactIds);
            if ($servicioData) {
                $serviciosProximos[] = $servicioData;
            }
        }

        return $serviciosProximos;
    }

    /**
     * Obtiene información de un servicio específico por ID de contrato
     */
    public static function getServicioInfo(int $idContrato, ?int $idcontacto = null): ?array
    {
        $contrato = new ContratServicio();
        if (!$contrato->load($idContrato)) {
            return null;
        }

        $contactIds = $idcontacto !== null ? [$idcontacto] : [$contrato->idcontacto];

        return self::procesarContrato($contrato, $contactIds);
    }

    /**
     * Verifica si un contrato pertenece a un cliente
     */
    public static function perteneceACliente(int $idContrato, string $codcliente): bool
    {
        $contrato = new ContratServicio();
        if (!$contrato->load($idContrato)) {
            return false;
        }

        $contacto = $contrato->getContacto();
        return $contacto->codcliente === $codcliente;
    }

    /**
     * Cancela un servicio
     */
    public static function cancelarServicio(int $idContrato, string $codcliente): bool
    {
        $contrato = new ContratServicio();
        if (!$contrato->load($idContrato)) {
            return false;
        }

        // Verificar que pertenece al cliente
        $contacto = $contrato->getContacto();
        if ($contacto->codcliente !== $codcliente) {
            return false;
        }

        $contrato->estado = ContratServicio::ESTADO_CANCELADO;
        $contrato->auto_renovar = false;

        if ($contrato->save()) {
            SolwedLogger::stripe("Contrato cancelado: ID={$idContrato} Cliente={$codcliente}");
            return true;
        }

        return false;
    }
}
