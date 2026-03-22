<?php

/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Producto;

/**
 * Modelo para gestión de servicios SOLWED
 *
 * Los precios se gestionan en la tabla relacionada ServicioPrecio.
 * Un servicio puede tener múltiples precios (mensual, anual, etc.)
 */
class Servicio extends ModelClass
{
    use ModelTrait;

    /** @var int Identificador único */
    public $id;

    /** @var string Nombre del servicio */
    public $nombre;

    /** @var string Descripción detallada */
    public $descripcion;

    /** @var string Categoría del servicio */
    public $categoria;

    /** @var string Clase CSS del icono (FontAwesome) */
    public $icono;

    /** @var string Color de la tarjeta (hex) */
    public $color;

    /** @var string Ruta a la imagen del servicio */
    public $imagen;

    /** @var bool Si el servicio está activo/visible */
    public $activo;

    /** @var bool Si el servicio es comprable desde el portal */
    public $comprable;

    /** @var int Orden de visualización */
    public $orden;

    /** @var int ID del producto de FacturaScripts vinculado */
    public $idproducto;

    /** @var string JSON con características del servicio */
    public $caracteristicas;

    /** @var string Fecha de creación */
    public $creation_date;

    /** @var string Última actualización */
    public $last_update;

    /** @var string ID del producto en Stripe */
    public $stripe_product_id;

    /** @var bool Si genera suscripción en Stripe al contratar */
    public $genera_suscripcion;

    // =========================================================================
    // CAMPOS OBSOLETOS - Mantenidos por compatibilidad con BD
    // Usar ServicioPrecio para gestionar precios
    // =========================================================================

    /**
     * @var float Precio del servicio
     * @deprecated Usar ServicioPrecio->precio
     */
    public $precio;

    /**
     * @var string Período de facturación (Mensual, Anual, etc.)
     * @deprecated Usar ServicioPrecio->periodo
     */
    public $periodo_facturacion;

    /**
     * @var int Meses de recurrencia (0 = no recurrente, 1 = mensual, 12 = anual)
     * @deprecated Usar ServicioPrecio->meses
     */
    public $meses_recurrencia;

    /**
     * @var string ID del precio en Stripe
     * @deprecated Usar ServicioPrecio->stripe_price_id
     */
    public $stripe_price_id;

    /**
     * Limpia los datos del modelo
     */
    public function clear(): void
    {
        parent::clear();
        $this->activo = true;
        $this->comprable = true;
        $this->orden = 0;
        $this->genera_suscripcion = false;
        $this->creation_date = date('Y-m-d H:i:s');
        $this->last_update = date('Y-m-d H:i:s');
        // Campos obsoletos - valores por defecto
        $this->precio = 0.0;
        $this->meses_recurrencia = 0;
    }

    /**
     * Devuelve el nombre de la columna que es clave primaria del modelo
     */
    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Devuelve el nombre de la tabla que usa este modelo
     */
    public static function tableName(): string
    {
        return 'solwedes_servicios';
    }

    /**
     * Devuelve las características del servicio parseadas desde JSON
     */
    public function getCaracteristicas(): array
    {
        if (empty($this->caracteristicas)) {
            return [];
        }

        $decoded = json_decode($this->caracteristicas, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Establece las características del servicio como JSON
     */
    public function setCaracteristicas(array $caracteristicas): void
    {
        $this->caracteristicas = json_encode($caracteristicas, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Devuelve el producto de FacturaScripts vinculado
     */
    public function getProducto(): ?Producto
    {
        if (empty($this->idproducto)) {
            return null;
        }

        $producto = new Producto();
        if ($producto->load($this->idproducto)) {
            return $producto;
        }

        return null;
    }

    /**
     * Devuelve todos los servicios activos ordenados
     *
     * @return self[]
     */
    public static function getServiciosActivos(): array
    {
        $servicio = new self();
        return $servicio->all(
            [Where::column('activo', true)],
            ['orden' => 'ASC', 'nombre' => 'ASC']
        );
    }

    /**
     * Devuelve los servicios agrupados por categoría
     */
    public static function getServiciosAgrupadosPorCategoria(): array
    {
        $servicios = self::getServiciosActivos();
        $agrupados = [];

        foreach ($servicios as $servicio) {
            $categoria = $servicio->categoria ?: 'Sin categoría';
            if (!isset($agrupados[$categoria])) {
                $agrupados[$categoria] = [];
            }
            $agrupados[$categoria][] = $servicio;
        }

        return $agrupados;
    }

    /**
     * Devuelve un servicio por su producto vinculado
     */
    public static function getByProducto(int $idproducto): ?self
    {
        $servicio = new self();
        $where = [Where::column('idproducto', $idproducto)];
        $results = $servicio->all($where, [], 0, 1);

        if (!empty($results)) {
            return $results[0];
        }
        return null;
    }

    // =========================================================================
    // MÉTODOS DE PRECIOS - Usan ServicioPrecio
    // =========================================================================

    /**
     * Devuelve todos los precios del servicio
     *
     * @param bool $soloActivos Si solo devolver precios activos
     * @return array Array de ServicioPrecio
     */
    public function getPrecios(bool $soloActivos = true): array
    {
        if (empty($this->id)) {
            return [];
        }

        $servicioPrecio = new ServicioPrecio();
        $where = [Where::column('idservicio', $this->id)];
        if ($soloActivos) {
            $where[] = Where::column('activo', true);
        }
        return $servicioPrecio->all($where, ['orden' => 'ASC', 'meses' => 'ASC'], 50);
    }


    // =========================================================================
    // MÉTODOS DE PERSISTENCIA
    // =========================================================================

    /**
     * Actualiza la marca de tiempo antes de insertar
     */
    protected function saveInsert(array $values = []): bool
    {
        $this->creation_date = date('Y-m-d H:i:s');
        $this->last_update = date('Y-m-d H:i:s');
        return parent::saveInsert();
    }

    /**
     * Actualiza la marca de tiempo antes de actualizar
     */
    protected function saveUpdate(array $values = []): bool
    {
        $this->last_update = date('Y-m-d H:i:s');
        return parent::saveUpdate();
    }

    /**
     * Valida los datos del servicio antes de guardar
     */
    public function test(): bool
    {
        // Validar nombre
        if (empty($this->nombre)) {
            Tools::log()->error('service-name-required');
            return false;
        }

        // Validar JSON de características
        if (!empty($this->caracteristicas)) {
            $decoded = json_decode($this->caracteristicas, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Tools::log()->error('invalid-json-features');
                return false;
            }
        }

        return parent::test();
    }
}
