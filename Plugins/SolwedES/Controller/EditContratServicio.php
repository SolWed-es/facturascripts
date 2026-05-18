<?php

/**
 * Plugin SolwedES - Edición de Contratos de Servicios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Controlador para editar un contrato de servicio
 */
class EditContratServicio extends EditController
{
    public function getModelClassName(): string
    {
        return 'ContratServicio';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'service-contract';
        $data['icon'] = 'fa-solid fa-file-contract';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->createViewDominios();
        $this->createViewPagos();
    }

    /**
     * Crea la pestaña de dominios vinculados
     */
    protected function createViewDominios(string $viewName = 'ListDominio')
    {
        $this->addListView($viewName, 'Dominio', 'domains', 'fa-solid fa-globe');
        $this->views[$viewName]->addOrderBy(['nombre'], 'name');
        $this->views[$viewName]->addOrderBy(['fecha_expiracion'], 'expiration');
        $this->views[$viewName]->addSearchFields(['nombre', 'tld']);
        $this->views[$viewName]->disableColumn('idcontrato');
    }

    /**
     * Crea la pestaña de pagos relacionados
     */
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
                $contrato = $this->getModel();
                $where = [new DataBaseWhere('idcontrato', $contrato->id)];
                $view->loadData('', $where);
                break;

            case 'ListPagoStripe':
                $contrato = $this->getModel();
                $where = [];

                // Filtrar pagos por referencia_externa o por idcontacto+idservicio
                if (!empty($contrato->referencia_externa)) {
                    // Buscar pagos donde el metadata contiene la referencia
                    $where[] = new DataBaseWhere('idcontacto', $contrato->idcontacto);
                    if (!empty($contrato->idservicio)) {
                        $where[] = new DataBaseWhere('idservicio', $contrato->idservicio);
                    }
                } else {
                    $where[] = new DataBaseWhere('idcontacto', $contrato->idcontacto);
                    if (!empty($contrato->idservicio)) {
                        $where[] = new DataBaseWhere('idservicio', $contrato->idservicio);
                    }
                }

                $view->loadData('', $where);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }
}
