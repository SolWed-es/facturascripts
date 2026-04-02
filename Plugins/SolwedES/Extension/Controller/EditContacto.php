<?php
/**
 * Plugin SolwedES - Extension EditContacto
 * Adds Suscripciones and Dominios tabs to the contact edit page
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * Extension for EditContacto controller
 * Adds tabs for viewing customer's subscriptions and domains
 */
class EditContacto
{
    /**
     * Creates additional views for Suscripciones and Dominios tabs
     */
    protected function createViews(): Closure
    {
        return function () {
            // Add Suscripciones tab
            $this->addListView(
                'ListSuscripcion-contact',
                'Suscripcion',
                'subscriptions',
                'fa-solid fa-file-contract'
            );
            $this->views['ListSuscripcion-contact']->addOrderBy(['fecha_inicio'], 'start-date', 2);
            $this->views['ListSuscripcion-contact']->addOrderBy(['estado'], 'status');
            $this->views['ListSuscripcion-contact']->addSearchFields(['referencia_externa', 'notas', 'dominio']);
            $this->views['ListSuscripcion-contact']->disableColumn('contact');

            // Add Dominios tab
            $this->addListView(
                'ListDominio-contact',
                'Dominio',
                'domains',
                'fa-solid fa-globe'
            );
            $this->views['ListDominio-contact']->addOrderBy(['nombre'], 'name');
            $this->views['ListDominio-contact']->addOrderBy(['fecha_expiracion'], 'expiration');
            $this->views['ListDominio-contact']->addSearchFields(['nombre', 'tld']);
            $this->views['ListDominio-contact']->disableColumn('contact');
        };
    }

    /**
     * Loads data for the views
     */
    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName === 'ListSuscripcion-contact') {
                $idcontacto = $this->getViewModelValue('EditContacto', 'idcontacto');
                $where = [new DataBaseWhere('idcontacto', $idcontacto)];
                $view->loadData('', $where);
            }

            if ($viewName === 'ListDominio-contact') {
                $idcontacto = $this->getViewModelValue('EditContacto', 'idcontacto');
                $where = [new DataBaseWhere('idcontacto', $idcontacto)];
                $view->loadData('', $where);
            }
        };
    }
}
