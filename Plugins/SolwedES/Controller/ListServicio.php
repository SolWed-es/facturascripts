<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * Controlador para listar servicios SOLWED
 */
class ListServicio extends ListController
{
    /**
     * Devuelve los datos de la página
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'warehouse';
        $data['title'] = 'services';
        $data['icon'] = 'fa-solid fa-briefcase';
        return $data;
    }

    /**
     * Carga las vistas
     */
    protected function createViews()
    {
        $this->createViewServicio();
        $this->createViewSuscripciones();
    }

    /**
     * Crea la vista de servicios
     *
     * @param string $viewName
     */
    protected function createViewServicio(string $viewName = 'ListServicio')
    {
        $this->addView($viewName, 'Servicio', 'services', 'fa-solid fa-briefcase');
        $this->addOrderBy($viewName, ['orden', 'nombre'], 'order', 1);
        $this->addOrderBy($viewName, ['nombre'], 'name');
        $this->addOrderBy($viewName, ['categoria'], 'category');
        $this->addOrderBy($viewName, ['creation_date'], 'creation-date');

        $this->addSearchFields($viewName, ['nombre', 'descripcion', 'categoria']);

        // Filtros
        $this->addFilterCheckbox($viewName, 'activo', 'active', 'activo');
        $this->addFilterCheckbox($viewName, 'comprable', 'purchasable', 'comprable');

        $categories = $this->codeModel->all('solwedes_servicios', 'categoria', 'categoria');
        $this->addFilterSelect($viewName, 'categoria', 'category', 'categoria', $categories);
    }

    /**
     * Crea la vista de contratos de servicios
     *
     * @param string $viewName
     */
    protected function createViewSuscripciones(string $viewName = 'ListSuscripcion')
    {
        $this->addView($viewName, 'Suscripcion', 'subscriptions', 'fa-solid fa-file-contract');

        $this->addOrderBy($viewName, ['fecha_vencimiento'], 'expiration', 1);
        $this->addOrderBy($viewName, ['fecha_inicio'], 'start-date');
        $this->addOrderBy($viewName, ['importe'], 'amount');
        $this->addOrderBy($viewName, ['creation_date'], 'creation-date');
        $this->addOrderBy($viewName, ['last_update'], 'last-update');

        $this->addSearchFields($viewName, ['referencia_externa', 'notas']);

        // Filtros
        $estados = [
            ['code' => 'activo', 'description' => 'Activo'],
            ['code' => 'suspendido', 'description' => 'Suspendido'],
            ['code' => 'cancelado', 'description' => 'Cancelado'],
            ['code' => 'vencido', 'description' => 'Vencido'],
            ['code' => 'pendiente', 'description' => 'Pendiente'],
        ];
        $this->addFilterSelect($viewName, 'estado', 'status', 'estado', $estados);

        $metodos = [
            ['code' => 'stripe', 'description' => 'Stripe'],
            ['code' => 'transferencia', 'description' => 'Transferencia'],
            ['code' => 'domiciliacion', 'description' => 'Domiciliación'],
            ['code' => 'manual', 'description' => 'Manual'],
        ];
        $this->addFilterSelect($viewName, 'metodo_pago', 'payment-method', 'metodo_pago', $metodos);

        $this->addFilterAutocomplete($viewName, 'idcontacto', 'contact', 'idcontacto', 'contactos', 'idcontacto', 'nombre');
        $this->addFilterAutocomplete($viewName, 'idservicio', 'service', 'idservicio', 'solwedes_servicios', 'id', 'nombre');

        $this->addFilterPeriod($viewName, 'fecha_vencimiento', 'expiration', 'fecha_vencimiento');
    }
}
