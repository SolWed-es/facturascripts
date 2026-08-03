<?php

namespace FacturaScripts\Plugins\PagoRecibosRedsys\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController as ParentController;
use FacturaScripts\Core\Model\Settings;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\LineaFacturaCliente;

class ListSeleccionFacturas extends ParentController {
    
    public $facturas;
    public $recibos;
    public $clientes;
    public $pagado;
    public $nopagado;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'Seleccionar Facturas';
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
        if ($_GET['vencidas'] == 1) {
            $facturas = $db->select('SELECT * FROM facturascli WHERE vencida=1 AND pagada=0;');
            $this->facturas=$facturas;
        } else{
            $facturas = $db->select('SELECT * FROM facturascli WHERE pagada=0;');
            $this->facturas=$facturas;
        }

        if ($_GET['vencidas'] == 1) {
            $recibos = $db->select('SELECT * FROM recibospagoscli WHERE vencido=1');
            $this->recibos = $recibos;
        } else{
            $recibos = $db->select('SELECT * FROM recibospagoscli;'); 
            $this->recibos = $recibos;
        }

        $clientes = $db->select('SELECT * FROM clientes;'); 
        $this->clientes = $clientes; 

        $pagado=[];
        $nopagado=[];

        foreach ($recibos as $recibo) {
            if ($recibo['pagado'] == 1) {
                if (isset($pagado[$recibo['codigofactura']])) {
                    $pagado[$recibo['codigofactura']] += $recibo['importe'];
                } else {
                    $pagado[$recibo['codigofactura']] = $recibo['importe'];
                }
            } else {
                if (isset($nopagado[$recibo['codigofactura']])) {
                    $nopagado[$recibo['codigofactura']] += $recibo['importe'];
                } else {
                    $nopagado[$recibo['codigofactura']] = $recibo['importe'];
                }
            }
        }
        
        foreach ($pagado as $codigo => &$importe) {
            $importe = $this->formatoDinero($importe);
        }
        
        foreach ($nopagado as $codigo => &$importe) {
            $importe = $this->formatoDinero($importe);
        }
        

        $this->pagado=$pagado;
        $this->nopagado=$nopagado;

        $this->setTemplate('ListadoFacturas');
    }
    
}