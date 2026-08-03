<?php
/**
 * Copyright (C) 2022-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Controller\SendTicket;
use FacturaScripts\Dinamic\Model\Agente;
use FacturaScripts\Dinamic\Model\Ticket;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Dinamic\Model\User;

/**
 *
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class SaleTicket
{
    public static function loadFormats(string $doctype): array
    {
        $formats = [];
        foreach (SendTicket::getFormats($doctype) as $format) {
            $arr = explode('\\', $format['className']);
            $formats[] = [
                'nameFile' => end($arr),
                'label' => $format['label'],
            ];
        }
        return $formats;
    }

    public static function openDrawer(TpvTerminal $tpv, User $user, Agente $agente): Ticket
    {
        $printer = $tpv->getPrinter();
        $ticket = new Ticket();
        $ticket->idprinter = $printer->id;
        $ticket->nick = $user->nick;
        $ticket->codagente = $agente->codagente;
        $ticket->title = Tools::lang()->trans('open-drawer');
        $ticket->body = $printer->getCommandStr('open');
        $ticket->save();
        return $ticket;
    }
}