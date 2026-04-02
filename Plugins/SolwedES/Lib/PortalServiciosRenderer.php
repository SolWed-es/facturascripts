<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use FacturaScripts\Plugins\SolwedES\Model\Servicio;

/**
 * Renderizador de servicios para el portal de clientes
 */
class PortalServiciosRenderer
{
    /**
     * Renderiza los servicios agrupados por categoría
     *
     * @return array
     */
    public static function render(): array
    {
        return Servicio::getServiciosAgrupadosPorCategoria();
    }

    /**
     * Prepara los datos de un servicio para la vista incluyendo características parseadas
     *
     * @param Servicio $servicio
     * @return array
     */
    private static function prepareServicioData(Servicio $servicio): array
    {
        $caracteristicas = $servicio->getCaracteristicas();
        $producto = $servicio->getProducto();

        return [
            'id' => $servicio->id,
            'nombre' => $servicio->nombre,
            'descripcion' => $servicio->descripcion,
            'categoria' => $servicio->categoria,
            'precio' => $servicio->precio,
            'periodo_facturacion' => $servicio->periodo_facturacion,
            'icono' => $servicio->icono ?: 'fa-solid fa-box',
            'color' => $servicio->color ?: '#6c757d',
            'imagen' => $servicio->imagen,
            'activo' => $servicio->activo,
            'comprable' => $servicio->comprable,
            'orden' => $servicio->orden,
            'idproducto' => $servicio->idproducto,
            'meses_recurrencia' => $servicio->meses_recurrencia,
            'genera_suscripcion' => $servicio->genera_suscripcion,
            'stripe_price_id' => $servicio->stripe_price_id,
            'caracteristicas' => $caracteristicas,
            'features' => $caracteristicas['features'] ?? [],
            'metadata' => $caracteristicas['metadata'] ?? [],
            'destacado' => $caracteristicas['metadata']['destacado'] ?? false,
            'badge' => $caracteristicas['metadata']['badge'] ?? null,
            'producto' => $producto ? [
                'referencia' => $producto->referencia,
                'descripcion' => $producto->descripcion
            ] : null
        ];
    }

    /**
     * Renderiza los servicios agrupados con datos preparados para la vista
     *
     * @return array
     */
    public static function renderPrepared(): array
    {
        $serviciosAgrupados = self::render();
        $resultado = [];

        foreach ($serviciosAgrupados as $categoria => $servicios) {
            $resultado[$categoria] = [];
            foreach ($servicios as $servicio) {
                $resultado[$categoria][] = self::prepareServicioData($servicio);
            }
        }

        return $resultado;
    }
}
