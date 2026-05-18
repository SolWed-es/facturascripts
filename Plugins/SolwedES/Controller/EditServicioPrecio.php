<?php
/**
 * Plugin SolwedES - Edición de precios de servicios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Controlador para editar un precio de servicio
 */
class EditServicioPrecio extends EditController
{
    /**
     * Devuelve el nombre de la clase del modelo
     *
     * @return string
     */
    public function getModelClassName(): string
    {
        return 'ServicioPrecio';
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
        $data['title'] = 'edit-price';
        $data['icon'] = 'fa-solid fa-tag';
        $data['showonmenu'] = false; // No mostrar en menú, solo accesible desde EditServicio
        return $data;
    }
}
