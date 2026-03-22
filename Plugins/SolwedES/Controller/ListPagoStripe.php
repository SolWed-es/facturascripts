<?php

/**
 * Plugin SolwedES - Listado de Pagos Stripe
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * Controlador para listar todos los pagos procesados por Stripe
 */
class ListPagoStripe extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'Pagos Stripe';
        $data['icon'] = 'fa-solid fa-credit-card';
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewPagos();
    }

    /**
     * Crea la vista principal de pagos
     */
    protected function createViewPagos(string $viewName = 'ListPagoStripe'): void
    {
        $this->addView($viewName, 'PagoStripe', 'Pagos Stripe', 'fa-solid fa-credit-card');

        // Ordenar por fecha de pago descendente
        $this->addOrderBy($viewName, ['fecha_pago'], 'Fecha pago', 2);
        $this->addOrderBy($viewName, ['creation_date'], 'Fecha creación');
        $this->addOrderBy($viewName, ['importe'], 'Importe');
        $this->addOrderBy($viewName, ['id'], 'ID');

        // Filtros
        $this->addFilterSelect($viewName, 'estado', 'Estado', 'estado', [
            ['code' => '', 'description' => '--- Todos ---'],
            ['code' => 'pending', 'description' => 'Pendiente'],
            ['code' => 'succeeded', 'description' => 'Exitoso'],
            ['code' => 'failed', 'description' => 'Fallido'],
            ['code' => 'refunded', 'description' => 'Reembolsado'],
            ['code' => 'partial_refund', 'description' => 'Reembolso parcial'],
            ['code' => 'canceled', 'description' => 'Cancelado']
        ]);

        $this->addFilterSelect($viewName, 'tipo', 'Tipo', 'tipo', [
            ['code' => '', 'description' => '--- Todos ---'],
            ['code' => 'subscription', 'description' => 'Suscripción'],
            ['code' => 'one_time', 'description' => 'Pago único']
        ]);

        $this->addFilterPeriod($viewName, 'fecha_pago', 'Fecha pago', 'fecha_pago');

        // Búsqueda
        $this->addSearchFields($viewName, ['concepto', 'stripe_payment_intent', 'stripe_customer_id']);
    }
}
