<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Core\Model\AlbaranCliente;
use FacturaScripts\Core\Model\FacturaCliente;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Lib\ExportManager;

/**
 *
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class SaleEmail
{
    protected static $namePdf = 'email_ticket.pdf';

    /**
     * @param AlbaranCliente|FacturaCliente $doc
     * @param string $email
     */
    public static function send($doc, string $email, string $mailbox = ''): bool
    {
        if (static::generatePdf($doc) === false) {
            return false;
        }

        $i18n = Tools::lang();
        $customer = $doc->getSubject();
        $nameFile = $i18n->trans($doc->modelClassName() . '-min') . ' ' . $doc->codigo . '.pdf';

        $mail = NewMail::create()
            ->to($email, $customer->nombre)
            ->subject($i18n->trans($doc->modelClassName() . '-min') . ' ' . $doc->codigo)
            ->body($i18n->trans('hello') . ".\n\n"
                . $i18n->trans('attachment-your', ['%doc%' => strtolower($i18n->trans($doc->modelClassName() . '-min')), '%codigo%' => $doc->codigo]) . ".\n\n"
                . $i18n->trans('thanks-greetings') . ".")
            ->addAttachment(self::$namePdf, $nameFile);

        if (false === empty($mailbox)) {
            $mail->setMailbox($mailbox);
        }

        if ($mail->send()) {
            $doc->femail = date('d-m-Y');
            $doc->save();
        }

        unlink(self::$namePdf);
        return true;
    }

    protected static function generatePdf($doc): bool
    {
        $pdf = new ExportManager();
        self::$namePdf = urlencode(strtolower($doc->codigo)) . '_' . self::$namePdf;
        $pdf->newDoc('PDF', self::$namePdf);
        $pdf->addBusinessDocPage($doc);
        return file_put_contents(self::$namePdf, $pdf->getDoc());
    }
}