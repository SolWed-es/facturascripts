<?php
/**
 * Plugin SolwedES - Controlador de listado de accesos a servicios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * Controlador para listar accesos a servicios
 */
class ListAccesoServicio extends ListController
{
    /**
     * Título de la página
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'service-accesses';
        $data['icon'] = 'fa-solid fa-right-to-bracket';

        return $data;
    }

    /**
     * Crea las vistas
     */
    protected function createViews()
    {
        $this->createViewAccesos();
    }

    /**
     * Crea la vista principal de accesos
     *
     * @param string $viewName
     */
    protected function createViewAccesos(string $viewName = 'ListAccesoServicio')
    {
        $this->addView($viewName, 'AccesoServicio', 'service-accesses', 'fa-solid fa-right-to-bracket');

        // Añadir botones
        $this->addButton($viewName, [
            'action' => 'EditAccesoServicio',
            'icon' => 'fa-solid fa-plus',
            'label' => 'new',
            'type' => 'link'
        ]);

        // Configurar búsqueda
        $this->addSearchFields($viewName, ['url_acceso', 'usuario', 'notas']);

        // Añadir orden
        $this->addOrderBy($viewName, ['id'], 'id', 2);
        $this->addOrderBy($viewName, ['idcontacto'], 'contact');
        $this->addOrderBy($viewName, ['idservicio'], 'service');
        $this->addOrderBy($viewName, ['last_access'], 'last-access');
        $this->addOrderBy($viewName, ['creation_date'], 'date');

        // Añadir filtros
        $this->addFilterSelect($viewName, 'tipo_acceso', 'type', 'tipo_acceso', [
            '' => '------',
            'wordpress' => 'WordPress',
            'facturascripts' => 'FacturaScripts',
            'plesk' => 'Plesk',
            'otro' => 'Otro'
        ]);

        $this->addFilterCheckbox($viewName, 'activo', 'active', 'activo');

        $this->addFilterAutocomplete($viewName, 'idcontacto', 'contact', 'idcontacto', 'contactos', 'idcontacto', 'nombre');

        $this->addFilterAutocomplete($viewName, 'idservicio', 'service', 'idservicio', 'solwedes_servicios', 'id', 'nombre');

        $this->addFilterNumber($viewName, 'intentos_fallidos', 'failed-attempts', 'intentos_fallidos', '>=');
    }
}
