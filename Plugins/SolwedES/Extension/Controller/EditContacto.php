<?php
/**
 * Plugin SolwedES - Extension EditContacto
 * Adds Contratos and Dominios tabs to the contact edit page
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * Extension for EditContacto controller
 * Adds tabs for viewing customer's contracts and domains
 */
class EditContacto
{
    /**
     * Creates additional views for Contratos and Dominios tabs
     */
    protected function createViews(): Closure
    {
        return function () {
            // Add Contratos tab - show customer's service contracts
            $this->addListView(
                'ListContratServicio-contact',
                'ContratServicio',
                'contracts',
                'fa-solid fa-file-contract'
            );
            $this->views['ListContratServicio-contact']->addOrderBy(['fecha_inicio'], 'start-date', 2);
            $this->views['ListContratServicio-contact']->addOrderBy(['estado'], 'status');
            $this->views['ListContratServicio-contact']->addSearchFields(['referencia_externa', 'notas']);
            $this->views['ListContratServicio-contact']->disableColumn('contact');

            // Add Dominios tab - show customer's domains
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
            // Load data for Contratos tab
            if ($viewName === 'ListContratServicio-contact') {
                $idcontacto = $this->getViewModelValue('EditContacto', 'idcontacto');
                $where = [new DataBaseWhere('idcontacto', $idcontacto)];
                $view->loadData('', $where);
            }

            // Load data for Dominios tab
            if ($viewName === 'ListDominio-contact') {
                $idcontacto = $this->getViewModelValue('EditContacto', 'idcontacto');
                $where = [new DataBaseWhere('idcontacto', $idcontacto)];
                $view->loadData('', $where);
            }
        };
    }
}
