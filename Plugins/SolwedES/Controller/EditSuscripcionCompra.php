<?php

/**
 * Plugin SolwedES - Edicion de Suscripcion de Compra
 *
 * @author    Solwed Desarrollo
 * @copyright 2026 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Controlador para editar una suscripcion de compra
 */
class EditSuscripcionCompra extends EditController
{
    public function getModelClassName(): string
    {
        return 'SuscripcionCompra';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'purchases';
        $data['title'] = 'purchase-subscription';
        $data['icon'] = 'fa-solid fa-arrows-rotate';
        return $data;
    }
}
