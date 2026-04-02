<?php
/**
 * Plugin SolwedES - Listado de Suscripciones
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * Controlador para listar suscripciones
 */
class ListSuscripcion extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'subscriptions';
        $data['icon'] = 'fa-solid fa-file-contract';

        return $data;
    }

    protected function createViews()
    {
        $this->createViewSuscripciones();
    }

    protected function createViewSuscripciones(string $viewName = 'ListSuscripcion')
    {
        $this->addView($viewName, 'Suscripcion', 'subscriptions', 'fa-solid fa-file-contract');

        $this->addSearchFields($viewName, ['referencia_externa', 'notas', 'stripe_customer_id', 'dominio']);

        $this->addOrderBy($viewName, ['id'], 'id', 2);
        $this->addOrderBy($viewName, ['fecha_inicio'], 'start-date');
        $this->addOrderBy($viewName, ['fecha_vencimiento'], 'expiration');
        $this->addOrderBy($viewName, ['creation_date'], 'date');

        $estados = [
            '' => '------',
            'activo' => 'Activo',
            'pendiente' => 'Pendiente',
            'suspendido' => 'Suspendido',
            'cancelado' => 'Cancelado',
            'vencido' => 'Vencido'
        ];
        $this->addFilterSelect($viewName, 'estado', 'status', 'estado', $estados);

        $metodos = [
            '' => '------',
            'stripe' => 'Stripe',
            'transferencia' => 'Transferencia',
            'domiciliacion' => 'Domiciliacion',
            'manual' => 'Manual'
        ];
        $this->addFilterSelect($viewName, 'metodo_pago', 'payment-method', 'metodo_pago', $metodos);

        $this->addFilterAutocomplete($viewName, 'idcontacto', 'contact', 'idcontacto', 'contactos', 'idcontacto', 'nombre');

        $this->addFilterAutocomplete($viewName, 'idservicio', 'service', 'idservicio', 'solwedes_servicios', 'id', 'nombre');

        $this->addFilterPeriod($viewName, 'fecha_inicio', 'start-date', 'fecha_inicio');
        $this->addFilterPeriod($viewName, 'fecha_vencimiento', 'expiration', 'fecha_vencimiento');

        $this->addFilterCheckbox($viewName, 'auto_renovar', 'auto-renewal', 'auto_renovar');
    }
}
