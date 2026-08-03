<?php
namespace FacturaScripts\Plugins\DescargarFacturasZIP\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController as ParentController;
use FacturaScripts\Core\Base\DataBase;

class DescargarPDF extends ParentController {

    public $facturas;

    public function getPageData(): array {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = '';
        $data['icon'] = '';
        return $data;
    }

    protected function createViews() {
        $db=new DataBase();
        $ids=explode(',', $_GET['ids']);

        $facturas = $db->select('SELECT * FROM facturascli WHERE idfactura IN ('.$_GET['ids'].');');
        $this->facturas=$facturas;

        $this->setTemplate('DescargarPDF');
    }
}
