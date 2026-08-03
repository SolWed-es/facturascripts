<?php

namespace FacturaScripts\Plugins\PagoRecibosRedsys\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController as ParentController;
use FacturaScripts\Core\Model\Settings;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\LineaFacturaCliente;

class ListSeleccionPresupuestos extends ParentController{
    
    public $presupuestos;
    public $clientes;
    public $pagado;
    public $nopagado;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'Seleccionar Presupuestos';
        $data['icon'] = 'fas fa-file';
        return $data;
    }

    public function formatoDinero($numero) {
        $numero_formateado = number_format($numero, 2, ',', '.');
        $numero_formateado .= ' €';
    
        return $numero_formateado;
    }

    protected function createViews() {
        $db=new DataBase();
        $presupuestos = $db->select('SELECT * FROM presupuestoscli WHERE editable=1');
        $this->presupuestos=$presupuestos;

        $clientes = $db->select('SELECT * FROM clientes;');
        $this->clientes = $clientes;

        $nopagado=[];

        foreach ($presupuestos as $presupuesto) {
            $nopagado[$presupuesto['codigo']] = $presupuesto['totaleuros'];
        }
        
        foreach ($nopagado as $codigo => &$importe) {
            $importe = $this->formatoDinero($importe);
        }
        
        $this->pagado=0;
        $this->nopagado=$nopagado;

        $this->setTemplate('ListadoPresupuestos');
    }
    
}