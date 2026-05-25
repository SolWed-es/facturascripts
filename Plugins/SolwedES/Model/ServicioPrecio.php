<?php

/**
 * Plugin SolwedES - Precios de Servicios (múltiples períodos de facturación)
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

/**
 * Modelo para gestión de precios de servicios con múltiples períodos
 *
 * Permite definir diferentes precios para un mismo servicio según el período:
 * - mensual (1 mes)
 * - anual (12 meses)
 * - horario (0 meses, pago único por hora)
 * - unico (0 meses, pago único)
 */
class ServicioPrecio extends ModelClass
{
    use ModelTrait;

    /** @var int Identificador único */
    public $id;

    /** @var int ID del servicio padre */
    public $idservicio;

    /** @var string Período de facturación (mensual, anual, horario, unico) */
    public $periodo;

    /** @var int Meses de recurrencia (0=único/horario, 1=mensual, 12=anual) */
    public $meses;

    /** @var float Precio para este período */
    public $precio;

    /** @var string ID del precio en Stripe */
    public $stripe_price_id;

    /** @var string Etiqueta de descuento opcional (ej: "2 meses gratis") */
    public $descuento_label;

    /** @var bool Si este precio está activo */
    public $activo;

    /** @var int Orden de visualización */
    public $orden;

    /** @var string Fecha de creación */
    public $creation_date;

    /** @var string Última actualización */
    public $last_update;

    /**
     * Períodos válidos con su configuración
     */
    public const PERIODOS = [
        'mensual' => ['label' => 'Mensual', 'meses' => 1],
        'anual' => ['label' => 'Anual', 'meses' => 12],
        'semestral' => ['label' => 'Semestral', 'meses' => 6],
        'trimestral' => ['label' => 'Trimestral', 'meses' => 3],
        'horario' => ['label' => 'Por Hora', 'meses' => 0],
        'unico' => ['label' => 'Pago Único', 'meses' => 0],
    ];

    /**
     * Limpia los datos del modelo
     */
    public function clear(): void
    {
        parent::clear();
        $this->activo = true;
        $this->orden = 0;
        $this->precio = 0.0;
        $this->meses = 1;
        $this->periodo = 'mensual';
        $this->creation_date = date('Y-m-d H:i:s');
        $this->last_update = date('Y-m-d H:i:s');
    }

    /**
     * Devuelve el nombre de la columna que es clave primaria
     */
    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Devuelve el nombre de la tabla
     */
    public static function tableName(): string
    {
        return 'solwedes_servicios_precios';
    }

    /**
     * Devuelve el servicio padre
     */
    public function getServicio(): ?Servicio
    {
        if (empty($this->idservicio)) {
            return null;
        }

        $servicio = new Servicio();
        if ($servicio->load($this->idservicio)) {
            return $servicio;
        }

        return null;
    }

    /**
     * Devuelve la etiqueta legible del período
     */
    public function getPeriodoLabel(): string
    {
        return self::PERIODOS[$this->periodo]['label'] ?? ucfirst($this->periodo);
    }

    /**
     * Devuelve todos los precios de un servicio
     *
     * @param int $idservicio
     * @param bool $soloActivos
     * @return self[]
     */
    public static function getByServicio(int $idservicio, bool $soloActivos = true): array
    {
        $precio = new self();
        $where = [new DataBaseWhere('idservicio', $idservicio)];

        if ($soloActivos) {
            $where[] = new DataBaseWhere('activo', true);
        }

        return $precio->all($where, ['orden' => 'ASC', 'meses' => 'ASC'], 50);
    }

    /**
     * Devuelve un precio específico por servicio y período
     *
     * @param int $idservicio
     * @param string $periodo
     * @return self|null
     */
    public static function getByServicioYPeriodo(int $idservicio, string $periodo): ?self
    {
        $precio = new self();
        $where = [
            new DataBaseWhere('idservicio', $idservicio),
            new DataBaseWhere('periodo', $periodo),
        ];

        $results = $precio->all($where, [], 0, 1);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Devuelve un precio por su stripe_price_id
     *
     * @param string $stripePriceId
     * @return self|null
     */
    public static function getByStripePriceId(string $stripePriceId): ?self
    {
        $precio = new self();
        $where = [new DataBaseWhere('stripe_price_id', $stripePriceId)];

        $results = $precio->all($where, [], 0, 1);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Calcula el ahorro porcentual comparado con el precio mensual
     *
     * @return float|null Porcentaje de ahorro o null si no aplica
     */
    public function calcularAhorro(): ?float
    {
        if ($this->periodo === 'mensual' || $this->meses <= 1) {
            return null;
        }

        $precioMensual = self::getByServicioYPeriodo($this->idservicio, 'mensual');
        if (!$precioMensual || $precioMensual->precio <= 0) {
            return null;
        }

        $costoSinDescuento = $precioMensual->precio * $this->meses;
        if ($costoSinDescuento <= 0) {
            return null;
        }

        $ahorro = (($costoSinDescuento - $this->precio) / $costoSinDescuento) * 100;
        return round($ahorro, 1);
    }

    /**
     * Actualiza timestamps antes de insertar
     */
    protected function saveInsert(array $values = []): bool
    {
        $this->creation_date = date('Y-m-d H:i:s');
        $this->last_update = date('Y-m-d H:i:s');
        return parent::saveInsert();
    }

    /**
     * Actualiza timestamp antes de actualizar
     */
    protected function saveUpdate(array $values = []): bool
    {
        $this->last_update = date('Y-m-d H:i:s');
        return parent::saveUpdate();
    }

    /**
     * Valida los datos antes de guardar
     */
    public function test(): bool
    {
        // Validar servicio padre
        if (empty($this->idservicio)) {
            Tools::log()->error('service-required');
            return false;
        }

        // Validar período
        $this->periodo = strtolower(trim($this->periodo));
        if (!array_key_exists($this->periodo, self::PERIODOS)) {
            Tools::log()->error('invalid-billing-period');
            return false;
        }

        // Auto-asignar meses según período si no está definido
        if (empty($this->meses)) {
            $this->meses = self::PERIODOS[$this->periodo]['meses'];
        }

        // Validar precio
        if ($this->precio < 0) {
            Tools::log()->error('price-cannot-be-negative');
            return false;
        }

        return parent::test();
    }

    /**
     * Devuelve URL para ver/editar el registro
     */
    public function url(string $type = 'auto', string $list = 'ListServicio?activetab=List'): string
    {
        return parent::url($type, $list);
    }
}
