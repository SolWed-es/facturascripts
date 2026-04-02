<?php

/**
 * Plugin SolwedES - Edicion de Suscripciones
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Controlador para editar una suscripcion
 */
class EditSuscripcion extends EditController
{
    public function getModelClassName(): string
    {
        return 'Suscripcion';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'subscription';
        $data['icon'] = 'fa-solid fa-file-contract';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->createViewDominios();
        $this->createViewPagos();
    }

    protected function createViewDominios(string $viewName = 'ListDominio')
    {
        $this->addListView($viewName, 'Dominio', 'domains', 'fa-solid fa-globe');
        $this->views[$viewName]->addOrderBy(['nombre'], 'name');
        $this->views[$viewName]->addOrderBy(['fecha_expiracion'], 'expiration');
        $this->views[$viewName]->addSearchFields(['nombre', 'tld']);
        $this->views[$viewName]->disableColumn('idsuscripcion');
    }

    protected function createViewPagos(string $viewName = 'ListPagoStripe')
    {
        $this->addListView($viewName, 'PagoStripe', 'payments', 'fa-solid fa-credit-card');
        $this->views[$viewName]->addOrderBy(['fecha_pago'], 'date', 2);
        $this->views[$viewName]->addSearchFields(['stripe_payment_intent', 'concepto']);
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'ListDominio':
                $suscripcion = $this->getModel();
                $where = [new DataBaseWhere('idsuscripcion', $suscripcion->id)];
                $view->loadData('', $where);
                break;

            case 'ListPagoStripe':
                $suscripcion = $this->getModel();
                $where = [];
                $where[] = new DataBaseWhere('idcontacto', $suscripcion->idcontacto);
                if (!empty($suscripcion->idservicio)) {
                    $where[] = new DataBaseWhere('idservicio', $suscripcion->idservicio);
                }
                $view->loadData('', $where);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }
}
