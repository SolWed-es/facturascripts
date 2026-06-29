<?php

/**
 * Plugin SolwedES - Listado de Suscripciones de Compra
 *
 * @author    Solwed Desarrollo
 * @copyright 2026 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * Controlador para listar suscripciones de compra (gastos recurrentes a proveedores)
 */
class ListSuscripcionCompra extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'purchases';
        $data['title'] = 'purchase-subscriptions';
        $data['icon'] = 'fa-solid fa-arrows-rotate';

        return $data;
    }

    protected function createViews()
    {
        $this->createViewSuscripciones();
    }

    protected function createViewSuscripciones(string $viewName = 'ListSuscripcionCompra')
    {
        $this->addView($viewName, 'SuscripcionCompra', 'purchase-subscriptions', 'fa-solid fa-arrows-rotate');

        $this->addSearchFields($viewName, ['concepto', 'referencia_externa', 'notas']);

        $this->addOrderBy($viewName, ['fecha_proximo_pago'], 'next-payment', 1);
        $this->addOrderBy($viewName, ['fecha_inicio'], 'start-date');
        $this->addOrderBy($viewName, ['importe'], 'amount');
        $this->addOrderBy($viewName, ['id'], 'id');

        $estados = [
            '' => '------',
            'activa' => 'activa',
            'pausada' => 'pausada',
            'cancelada' => 'cancelada',
        ];
        $this->addFilterSelect($viewName, 'estado', 'status', 'estado', $estados);

        $metodos = [
            '' => '------',
            'domiciliacion' => 'domiciliacion',
            'transferencia' => 'transferencia',
            'tarjeta' => 'tarjeta',
            'manual' => 'manual',
        ];
        $this->addFilterSelect($viewName, 'metodo_pago', 'payment-method', 'metodo_pago', $metodos);

        $this->addFilterAutocomplete(
            $viewName,
            'codproveedor',
            'supplier',
            'codproveedor',
            'proveedores',
            'codproveedor',
            'nombre'
        );

        $this->addFilterPeriod($viewName, 'fecha_proximo_pago', 'next-payment', 'fecha_proximo_pago');

        $this->addFilterCheckbox($viewName, 'auto_renovar', 'auto-renewal', 'auto_renovar');
    }
}
