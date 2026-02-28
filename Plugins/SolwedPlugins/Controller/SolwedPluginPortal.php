<?php

namespace FacturaScripts\Plugins\SolwedPlugins\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;

/**
 * @deprecated Esta página ha sido integrada en Admin > Plugins > Portal Solwed
 * Se mantiene solo como redirección por compatibilidad hacia atrás
 */
class SolwedPluginPortal extends Controller
{
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        // Redirigir a AdminPlugins con la tab Portal Solwed
        Tools::log()->notice('SolwedPluginPortal deprecado: redirigiendo a AdminPlugins');
        $this->redirect('AdminPlugins#solwedPortal');
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['showonmenu'] = false;
        return $data;
    }
}
