<?php

/**
 * Plugin SolwedES - Edición de Pago Stripe
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Controlador para ver/editar un pago Stripe individual
 */
class EditPagoStripe extends EditController
{
    public function getModelClassName(): string
    {
        return 'PagoStripe';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'Pago Stripe';
        $data['icon'] = 'fa-solid fa-credit-card';
        $data['showonmenu'] = false;
        return $data;
    }
}
