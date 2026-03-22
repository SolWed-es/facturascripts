<?php
/**
 * Plugin SolwedES - Edición de Dominio
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Controlador para editar dominios de clientes SOLWED
 */
class EditDominio extends EditController
{
    /**
     * Devuelve el nombre del modelo
     *
     * @return string
     */
    public function getModelClassName(): string
    {
        return 'Dominio';
    }

    /**
     * Devuelve los datos de la página
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'domain';
        $data['icon'] = 'fa-solid fa-globe';
        return $data;
    }

    /**
     * Carga las vistas
     */
    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');
    }

    /**
     * Returns the URL for the list controller
     *
     * @return string
     */
    protected function getListURL(): string
    {
        return 'ListDominio';
    }
}
