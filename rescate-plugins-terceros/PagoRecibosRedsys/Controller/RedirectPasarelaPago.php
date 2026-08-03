<?php

namespace FacturaScripts\Plugins\PagoRecibosRedsys\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController as ParentController;
use FacturaScripts\Core\Model\Settings;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\LineaFacturaCliente;

class RedirectPasarelaPago extends ParentController{
    
    public $datos;

    public $facturas;

    public $pagado;

    public $nopagado;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'Pasarela TPV';
        $data['icon'] = 'fas fa-file';
        return $data;
    }

    public function publicCore(&$response)
    {
        parent::publicCore($response);
        $this->createViews();
    }

    protected function createViews() {
            $id = $_GET['id'];
            $dataBase = new DataBase();
            $recibos = $dataBase->select('SELECT * FROM recibospagoscli WHERE codigofactura = "'.$id.'";');
            $ids = [];
            foreach ($recibos as $recibo) {
                array_push($ids, $recibo['idrecibo']);
            }
            $this->redirect('ListPasarelaPago?ids='.implode(",", $ids).'&facturas='.$id);
    }
    
}