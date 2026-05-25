<?php
/**
 * Plugin SolwedES - Listado de Dominios
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * Controlador para listar dominios de clientes SOLWED
 */
class ListDominio extends ListController
{
    /**
     * Devuelve los datos de la página
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'domains';
        $data['icon'] = 'fa-solid fa-globe';
        return $data;
    }

    /**
     * Carga las vistas
     */
    protected function createViews()
    {
        $this->createViewDominio();
    }

    /**
     * Crea la vista de dominios
     *
     * @param string $viewName
     */
    protected function createViewDominio(string $viewName = 'ListDominio')
    {
        $this->addView($viewName, 'Dominio', 'domains', 'fa-solid fa-globe');

        // Ordenación
        $this->addOrderBy($viewName, ['nombre', 'tld'], 'name', 1);
        $this->addOrderBy($viewName, ['fecha_expiracion'], 'expiration');
        $this->addOrderBy($viewName, ['fecha_registro'], 'registration-date');
        $this->addOrderBy($viewName, ['creation_date'], 'creation-date');

        // Búsqueda
        $this->addSearchFields($viewName, ['nombre', 'tld', 'observaciones']);

        // Filtros
        $this->addFilterCheckbox($viewName, 'gestionado_solwed', 'managed-by-solwed', 'gestionado_solwed');
        $this->addFilterCheckbox($viewName, 'bloqueado', 'locked', 'bloqueado');
        $this->addFilterCheckbox($viewName, 'privacidad_whois', 'whois-privacy', 'privacidad_whois');

        // Filtro de estado
        $estados = [
            ['code' => '', 'description' => '------'],
            ['code' => 'active', 'description' => 'Activo'],
            ['code' => 'expired', 'description' => 'Expirado'],
            ['code' => 'pending_transfer', 'description' => 'Transferencia pendiente'],
            ['code' => 'redemption', 'description' => 'Período de redención'],
            ['code' => 'inactive', 'description' => 'Inactivo'],
        ];
        $this->addFilterSelect($viewName, 'estado', 'status', 'estado', $estados);

        // Filtro de TLD
        $tlds = $this->codeModel->all('solwedes_dominios', 'tld', 'tld');
        $this->addFilterSelect($viewName, 'tld', 'tld', 'tld', $tlds);

        // Filtro por suscripcion
        $this->addFilterAutocomplete($viewName, 'idsuscripcion', 'subscription', 'idsuscripcion', 'solwedes_suscripciones', 'id', 'id');

        // Filtro por contacto
        $this->addFilterAutocomplete($viewName, 'idcontacto', 'contact', 'idcontacto', 'contactos', 'idcontacto', 'nombre');
    }
}
