<?php
/**
 * Copyright (C) 2022-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\Tickets;

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Tickets\Model\Ticket;
use FacturaScripts\Plugins\TPVneo\Model\TpvCaja;

class BoxClosure
{
    public static function print(TpvCaja $caja): bool
    {
        $terminal = $caja->getTerminal();
        $printer = $terminal->getPrinter();
        if (false === $printer->exists() || empty($caja->fechafin)) {
            return false;
        }

        $i18n = Tools::lang();

        $ticket = new Ticket();
        $ticket->idprinter = $printer->id;
        $ticket->nick = Session::get('user')->nick;
        $ticket->title = $i18n->trans('box-closure');

        $ticket->body = static::getBigText($i18n->trans('box-closure'), $printer->linelen) . "\n"
            . $i18n->trans('pos-terminal') . ': ' . $terminal->name . "\n"
            . $i18n->trans('date') . ': ' . $caja->fechafin . "\n"
            . $i18n->trans('user') . ': ' . $caja->nick . "\n"
            . $i18n->trans('start-money') . ': ' . Tools::money($caja->dineroini, $terminal->coddivisa) . "\n"
            . $i18n->trans('income') . ': ' . Tools::money($caja->ingresos, $terminal->coddivisa) . "\n"
            . $i18n->trans('end-money') . ': ' . Tools::money($caja->dinerofin, $terminal->coddivisa) . "\n"
            . $i18n->trans('difference') . ': ' . Tools::money($caja->diferencia, $terminal->coddivisa) . "\n"
            . $i18n->trans('total-sales') . ': ' . Tools::money($caja->totaltickets, $terminal->coddivisa) . "\n"
            . $i18n->trans('tickets') . ': ' . $caja->numtickets . "\n\n";

        foreach ($caja->getPaymentBreakdown() as $payment) {
            $ticket->body .= $payment['descripcion'] . ': ' . Tools::money($payment['total'], $terminal->coddivisa) . "\n";
        }

        $ticket->body .= "\n" . $i18n->trans('observations') . ":\n" . $caja->observaciones
            . "\n\n\n\n\n\n"
            . "\n\n\n\n\n\n"
            . $printer->getCommandStr('cut') . "\n";
        return $ticket->save();
    }

    protected static function getBigText(string $text, int $lineLength): string
    {
        $bigLine = '';
        $bigLineLength = 0;
        $bigLineMax = intval($lineLength / 2);
        $words = explode(' ', $text);
        foreach ($words as $word) {
            if ($bigLineLength === 0) {
                $bigLine .= $word;
                $bigLineLength += strlen($word);
                continue;
            }

            $bigLineLength += strlen($word) + 1;
            if ($bigLineLength <= $bigLineMax) {
                $bigLine .= ' ' . $word;
                continue;
            }

            $bigLine .= "\n" . $word;
            $bigLineLength = strlen($word);
        }

        return "\x1B" . "!" . "\x38" . $bigLine . "\n" . "\x1B" . "!" . "\x00";
    }
}