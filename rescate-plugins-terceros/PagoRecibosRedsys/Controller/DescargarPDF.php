<?php

namespace FacturaScripts\Plugins\PagoRecibosRedsys\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController as ParentController;
use FacturaScripts\Core\Model\Settings;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Model\LineaFacturaCliente;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\ExportManager;

class DescargarPDF extends ParentController{
    
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
        $facturasSeleccionadas = $this->request->request->get('facturas');

        $exportManager = new ExportManager();

        /* foreach ($facturasSeleccionadas as $facturaId) { */
            $factura = $this->toolBox()->entityManager()->find('Factura', 'FAC2024A21');

            $pdfFileName = 'factura_' . strtolower($factura->codigo) . '_' . mt_rand() . '.pdf';

            $exportManager->newDoc('PDF', $pdfFileName);
            $exportManager->addBusinessDocPage($factura);

            file_put_contents($pdfFileName, $exportManager->getDoc());
        /* } */
    }
    
}