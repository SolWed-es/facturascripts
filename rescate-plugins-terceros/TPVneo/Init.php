<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo;

use FacturaScripts\Core\Lib\AjaxForms\SalesHeaderHTML;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Agente;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Plugins\TPVneo\Mod\SalesHeaderHTMLMod;
use FacturaScripts\Plugins\TPVneo\Model\TpvCaja;
use FacturaScripts\Plugins\TPVneo\Model\TpvTerminal;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
final class Init extends InitClass
{
    public function init(): void
    {
        // se ejecutará cada vez que carga FacturaScripts (si este plugin está activado).
        $this->loadExtension(new Extension\Controller\EditAgente());
        $this->loadExtension(new Extension\Controller\EditAlbaranCliente());
        $this->loadExtension(new Extension\Controller\EditDivisa());
        $this->loadExtension(new Extension\Controller\EditFacturaCliente());
        $this->loadExtension(new Extension\Controller\EditFormaPago());
        $this->loadExtension(new Extension\Controller\EditPresupuestoCliente());
        $this->loadExtension(new Extension\Controller\ListAlbaranCliente());
        $this->loadExtension(new Extension\Controller\ListFacturaCliente());
        $this->loadExtension(new Extension\Controller\ListPresupuestoCliente());
        $this->loadExtension(new Extension\Controller\ListTicketPrinter());
        $this->loadExtension(new Extension\Model\AlbaranCliente());
        $this->loadExtension(new Extension\Model\FacturaCliente());
        $this->loadExtension(new Extension\Model\Familia());
        $this->loadExtension(new Extension\Model\Producto());
        $this->loadExtension(new Extension\Model\PresupuestoCliente());
        $this->loadExtension(new Extension\Model\ReciboCliente());
        $this->loadExtension(new Extension\Model\PrePago());

        SalesHeaderHTML::addMod(new SalesHeaderHTMLMod());
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        // se ejecutará cada vez que se instala o actualiza el plugin.
        new TpvTerminal();
        new TpvCaja();
        new Producto();
        new AlbaranCliente();
        new FacturaCliente();
        new PresupuestoCliente();

        $this->createAgent();
        $this->updateTerminals();
        $this->checkTerminalPayMethods();
    }

    public static function checkTerminalPayMethods()
    {
        $db = new DataBase();
        foreach (['tpvsneo_pagos', 'tpvsneo'] as $table) {
            if (false === $db->tableExists($table)) {
                return;
            }
        }

        // comprobamos que ninguna de las formas de pago que tenga el terminal
        // pertenezca a una empresa diferente a la empresa del terminal
        $sql = 'SELECT tp.codpago, t.name'
            . ' FROM tpvsneo_pagos as tp'
            . ' INNER JOIN tpvsneo as t ON t.idtpv = tp.idtpv'
            . ' INNER JOIN formaspago as f ON f.codpago = tp.codpago'
            . ' WHERE f.idempresa != t.idempresa;';
        foreach ($db->select($sql) as $row) {
            Tools::log()->warning('payment-method-company-different', [
                '%payment-method%' => $row['codpago'],
                '%terminal%' => $row['name']
            ]);
        }
    }

    private function createAgent()
    {
        $agent = new Agente();
        if ($agent->count() > 0) {
            return;
        }

        // creamos un agente, si no hay ninguno
        $agent->nombre = 'TPV';
        $agent->save();
    }

    private function updateTerminals()
    {
        $tpvTerminal = new TpvTerminal();
        foreach ($tpvTerminal->all([], [], 0, 0) as $terminal) {
            // versión 2.5
            // si no tiene idempresa, le asignamos la del almacén
            $terminal->save();
        }
    }
}