<?php
/**
 * Plugin SolwedES - Gestión de servicios SOLWED
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Controlador para editar un servicio SOLWED
 */
class EditServicio extends EditController
{
    /**
     * Devuelve el nombre de la clase del modelo
     *
     * @return string
     */
    public function getModelClassName(): string
    {
        return 'Servicio';
    }

    /**
     * Devuelve los datos de la página
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'service';
        $data['icon'] = 'fa-solid fa-briefcase';
        return $data;
    }

    /**
     * Crea las vistas del controlador
     */
    protected function createViews(): void
    {
        parent::createViews();

        // Añadir pestaña de precios como vista hija
        $this->createPreciosView();
    }

    /**
     * Crea la vista de precios del servicio
     */
    protected function createPreciosView(): void
    {
        $viewName = 'ListServicioPrecio';
        $this->addListView($viewName, 'ServicioPrecio', 'prices', 'fa-solid fa-tags');

        // Configurar la vista
        $this->views[$viewName]->addOrderBy(['orden', 'meses'], 'order', 1);
        $this->views[$viewName]->addOrderBy(['precio'], 'price');
        $this->views[$viewName]->addOrderBy(['periodo'], 'period');

        // Filtros
        $this->views[$viewName]->addFilterCheckbox('activo', 'active', 'activo');

        // Deshabilitar botones que no aplican
        $this->setSettings($viewName, 'btnPrint', false);
    }

    /**
     * Carga los datos de las vistas
     *
     * @param string $viewName
     * @param object $view
     */
    protected function loadData($viewName, $view): void
    {
        switch ($viewName) {
            case 'ListServicioPrecio':
                // Filtrar por el servicio actual
                $idservicio = $this->getViewModelValue('EditServicio', 'id');
                $where = [new DataBaseWhere('idservicio', $idservicio)];
                $view->loadData('', $where);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }

    /**
     * Acciones antes de insertar un nuevo precio
     *
     * @param string $viewName
     * @return bool
     */
    protected function insertAction(): bool
    {
        // Si estamos insertando un precio, añadir el idservicio
        if ($this->active === 'ListServicioPrecio') {
            $idservicio = $this->getViewModelValue('EditServicio', 'id');
            if (!empty($idservicio)) {
                $this->request->request->set('idservicio', $idservicio);
            }
        }

        return parent::insertAction();
    }
}
