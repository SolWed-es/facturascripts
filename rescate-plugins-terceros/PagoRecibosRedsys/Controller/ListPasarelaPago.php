<?php

namespace FacturaScripts\Plugins\PagoRecibosRedsys\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController as ParentController;
use FacturaScripts\Core\Model\Settings;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\LineaFacturaCliente;

class ListPasarelaPago extends ParentController{
    
    public $datos;

    public $facturas;

    public $presupuestos;

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
    public function formatoDinero($numero) {
        $numero_formateado = number_format($numero, 2, ',', '.');
        $numero_formateado .= ' €';
    
        return $numero_formateado;
    }

    protected function createViews() {
        if (isset($_GET['ids'])){
            $dataBase = new DataBase();
            $ids = explode(',', $_GET['ids']);
            $formattedIds = '"' . implode('","', $ids) . '"';
            $amount = 0;
            $lineasFactura = array();

            $facturas = explode(',', $_GET['facturas']);
            $facturas = array_unique($facturas);
            $formattedFac = '"' . implode('","', $facturas) . '"';

            $idRecibo = filter_input(INPUT_GET, 'ids');
            $idRecibo = explode(',', $idRecibo);
            $recibo = new FacturaCliente();
            $recibo->loadFromCode($idRecibo[0]);
            
            $recibos = $dataBase->select('SELECT * FROM recibospagoscli WHERE idrecibo IN (' . $formattedIds . ');');
            $idFactura = $dataBase->select('SELECT idfactura FROM recibospagoscli WHERE idrecibo = "' . $ids[0] . '";');
            $codCliente = $dataBase->select('SELECT codcliente FROM facturascli WHERE idfactura = "' . $idFactura[0]["idfactura"] . '";');
            $nombreCli=$dataBase->select('SELECT razonsocial FROM clientes WHERE codcliente = "' . $codCliente[0]["codcliente"] . '";');

            $this->datos = new \stdClass;
            $this->datos->nombre = $nombreCli[0]['razonsocial'];
            $this->datos->fecha = date('d-m-Y');
            $this->datos->facturas = $facturas;

            $objLineas=[];

            foreach ($lineasFactura as $lineas) {
                foreach ($lineas as $linea) {
                    $lineaObj = new LineaFacturaCliente();
                    $lineaObj->loadFromCode($linea['idlinea']); 
                    $lineaObj->preciofinal=$this->formatoDinero($lineaObj->pvptotal+($lineaObj->pvptotal*$lineaObj->iva/100));
                    array_push($objLineas, $lineaObj);
                }
            }

            $facturas = $dataBase->select('SELECT * FROM facturascli WHERE codigo IN (' . $formattedFac . ');');
            $this->facturas=$facturas;

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
                $amount += $importe;
                $importe = $this->formatoDinero($importe);
            }

            $this->datos->total = $this->formatoDinero($amount);
            $this->datos->amount = $amount;
            $this->pagado=$pagado;
            $this->nopagado=$nopagado;

            $this->setTemplate('PasarelaPago');
        } else {
            $dataBase = new DataBase();
            $amount = 0;
            $lineasPresupuesto = array();

            $presupuestos = explode(',', $_GET['presupuestos']);
            $presupuestos = array_unique($presupuestos);
            $formattedPre = '"' . implode('","', $presupuestos) . '"';
            
            $recibos = $dataBase->select('SELECT * FROM presupuestoscli WHERE codigo IN (' . $formattedPre . ');');
            $idPresupuesto = $dataBase->select('SELECT idpresupuesto FROM presupuestoscli WHERE codigo = "' . $presupuestos[0] . '";');
            $codCliente = $dataBase->select('SELECT codcliente FROM presupuestoscli WHERE idpresupuesto = "' . $idPresupuesto[0]["idpresupuesto"] . '";');
            $nombreCli=$dataBase->select('SELECT razonsocial FROM clientes WHERE codcliente = "' . $codCliente[0]["codcliente"] . '";');

            $this->datos = new \stdClass;
            $this->datos->nombre = $nombreCli[0]['razonsocial'];
            $this->datos->fecha = date('d-m-Y');
            $this->datos->presupuestos = $presupuestos;

            $objLineas=[];

            foreach ($lineasPresupuesto as $lineas) {
                foreach ($lineas as $linea) {
                    $lineaObj = new LineaPresupuestoCliente();
                    $lineaObj->loadFromCode($linea['idlinea']); 
                    $lineaObj->preciofinal=$this->formatoDinero($lineaObj->pvptotal+($lineaObj->pvptotal*$lineaObj->iva/100));
                    array_push($objLineas, $lineaObj);
                }
            }

            $presupuestos = $dataBase->select('SELECT * FROM presupuestoscli WHERE codigo IN (' . $formattedPre . ');');
            $this->presupuestos=$presupuestos;

            $pagado=[];
            $nopagado=[];
            
            foreach ($presupuestos as $presupuesto) {
                $nopagado[$presupuesto['codigo']] = $presupuesto['totaleuros'];
            }

            foreach ($pagado as $codigo => &$importe) {
                $importe = $this->formatoDinero($importe);
            }
            
            foreach ($nopagado as $codigo => &$importe) {
                $amount += $importe;
                $importe = $this->formatoDinero($importe);
            }

            $this->datos->total = $this->formatoDinero($amount);
            $this->datos->amount = $amount;
            $this->pagado=$pagado;
            $this->nopagado=$nopagado;

            $this->setTemplate('PasarelaPagoPresupuestos');
        }
    }
    
}