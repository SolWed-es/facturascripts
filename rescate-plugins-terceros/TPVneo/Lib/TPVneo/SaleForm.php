<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Core\Base\Calculator;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Agentes;
use FacturaScripts\Core\DataSrc\Divisas;
use FacturaScripts\Core\DataSrc\FormasPago;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Model\Base\SalesDocumentLine;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Translator;
use FacturaScripts\Dinamic\Lib\Accounting\InvoiceToAccounting;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\PrePago;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use FacturaScripts\Dinamic\Model\TpvCaja;
use FacturaScripts\Dinamic\Model\TpvPago;
use FacturaScripts\Dinamic\Model\TpvTerminal;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class SaleForm
{
    use TpvTrait;

    /** @var SalesDocument */
    protected static $doc;

    /** @var SalesDocumentLine[] */
    protected static $lines = [];

    protected static $lastDocSave;

    public static function amount(TpvTerminal $tpv): string
    {
        return Tools::money(self::$doc->total ?? 0, $tpv->coddivisa);
    }

    public static function apply(array $formData, User $user, TpvTerminal $tpv, ?string $codagente): void
    {
        self::clearCart($tpv);
        $user->codagente = $codagente;
        self::$doc->setAuthor($user);

        $cliente = new Cliente();
        $codcliente = empty($formData['codcliente']) ? $tpv->codcliente : $formData['codcliente'];
        $cliente->loadFromCode($codcliente);
        self::$doc->setSubject($cliente);

        // líneas del carrito
        $linesCart = $formData['linesCart'] ?? 100;
        for ($num = 1; $num <= $linesCart; $num++) {
            if (false === isset($formData['descripcion_' . $num])) {
                continue;
            }

            if ($formData['action'] === 'rm-line' && $formData['action-code'] == $num) {
                continue;
            }

            $newLine = empty($formData['referencia_' . $num])
                ? self::$doc->getNewLine()
                : self::$doc->getNewProductLine($formData['referencia_' . $num]);

            $newLine->orden = (int)$formData['orden_' . $num];
            $newLine->cantidad = (float)$formData['cantidad_' . $num];
            $newLine->descripcion = $formData['descripcion_' . $num];
            $newLine->idlinea = $formData['idlinea_' . $num];
            $newLine->codimpuesto = $formData['codimpuesto_' . $num];

            if ($tpv->adddiscount) {
                $newLine->dtopor = (float)$formData['dtopor_' . $num];
            }

            // si permite cambiar el precio y el nuevo precio no está vacío,
            // o si es una línea nueva y el precio no está vacío
            // calculamos el precio sin iva
            if ($tpv->changeprice && $formData['new_precio_' . $num] != ''
                || empty($formData['referencia_' . $num]) && $formData['new_precio_' . $num] != '') {
                $newLine->pvpunitario = round((100 * floatval($formData['new_precio_' . $num])) / (100 + floatval($newLine->iva)), 5);
            } else {
                $newLine->pvpunitario = (float)$formData['pvpunitario_' . $num];
            }

            self::$lines[] = $newLine;
        }

        // new line
        switch ($formData['action']) {
            case 'add-barcode':
                $variant = new Variante();
                $where = [new DataBaseWhere('codbarras', $formData['action-code'])];
                if (false === $variant->loadFromCode('', $where)) {
                    break;
                }

                // si ya existe el producto en el carrito
                // y el tpv tiene la opción activada de agrupar líneas
                // se suma 1 a la cantidad
                foreach (self::$lines as $line) {
                    if ($variant->referencia == $line->referencia && $tpv->grouplines) {
                        $line->cantidad += 1;
                        break 2;
                    }
                }

                // si no existe el producto en el carrito
                // o no se ha activado la opción de agrupar líneas
                // se añade una nueva línea
                $newLine = self::$doc->getNewProductLine($variant->referencia);
                $newLine->orden = count(self::$lines) + 1;
                self::$lines[] = $newLine;
                break;

            case 'add-product':
                // si ya existe el producto en el carrito
                // y el tpv tiene la opción activada de agrupar líneas
                // se suma 1 a la cantidad
                foreach (self::$lines as $line) {
                    if ($line->referencia == $formData['action-code'] && $tpv->grouplines) {
                        $line->cantidad += 1;
                        break 2;
                    }
                }

                // si no existe el producto en el carrito
                // o no se ha activado la opción de agrupar líneas
                // se añade una nueva línea
                $newLine = self::$doc->getNewProductLine($formData['action-code']);
                $newLine->orden = count(self::$lines) + 1;
                self::$lines[] = $newLine;
                break;

            case 'new-line':
                $line = self::$doc->getNewLine();
                $line->orden = count(self::$lines) + 1;
                $line->descripcion = $formData['new-desc'];
                $line->codimpuesto = $formData['new-tax'];
                self::$lines[] = $line;
                break;
        }

        self::$doc->dtopor1 = (float)$formData['dtopor_global'] ?? 0.0;
        Calculator::calculate(self::$doc, self::$lines, false);
    }

    public static function clearCart(TpvTerminal $tpv): void
    {
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        self::$doc = new $modelClass();
        self::$doc->total = 0;
        self::$lines = [];
    }

    public static function getDoc(): SalesDocument
    {
        return self::$doc;
    }

    public static function getLastDocSave()
    {
        return self::$lastDocSave;
    }

    public static function getLines(): array
    {
        return self::$lines;
    }

    public static function loadDocPrint($idDoc, TpvTerminal $tpv, bool $pay = false): string
    {
        $html = '';
        $cliente = new Cliente();
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        $doc = new $modelClass();

        if (false === $doc->loadFromCode($idDoc)
            || false === $cliente->loadFromCode($doc->codcliente)) {
            return $html;
        }

        $html .= '<div class="row text-center">';
        if ($pay) {
            $html .= '<div class="col-6">'
                . '<span class="h4">' . $doc->codigo . '</span>'
                . '<div class="small">' . Tools::lang()->trans('document') . '</div>'
                . '</div>'
                . '<div class="col-6">'
                . '<span class="h4">' . Tools::money($doc->tpv_cambio, $doc->coddivisa) . '</span>'
                . '<div class="small">' . Tools::lang()->trans('money-change') . '</div>'
                . '</div>';
        } else {
            $html .= '<div class="col-12">'
                . '<span class="h4">' . $doc->codigo . '</span>'
                . '<div class="small">' . Tools::lang()->trans('document') . '</div>'
                . '</div>';
        }
        $html .= '</div>';

        // añadimos el botón de enviar por email
        $mail = new NewMail();
        if ($mail->canSendMail()) {
            $html .= '<hr/>'
                . '<select name="mailbox" class="form-control form-control-lg">';

            foreach ($mail->getAvailableMailboxes() as $email) {
                $html .= '<option value="' . $email . '">' . $email . '</option>';
            }

            $html .= '</select>'
                . '<div class="input-group input-group-lg pt-3 pb-3">'
                . '<input id="emailInput" class="form-control" aria-describedby="ticket-send" placeholder="'
                . Tools::lang()->trans('email') .' ' . strtolower(Tools::lang()->trans('customer')) . '" value="' . $cliente->email . '" />'
                . '<div class="input-group-append">'
                . '<button class="btn btn-secondary btn-spin-action" type="button" id="doc-send" onclick="sendDoc('
                . $doc->primaryColumnValue() . ')">'
                . '<i class="fas fa-envelope mr-1 fa-fw"></i>'
                . Tools::lang()->trans('send-' . strtolower($doc->modelClassName()))
                . '</button>'
                . '</div>'
                . '</div>';
        }

        // añadimos el botón de imprimir eñ ticket
        if ($tpv->idprinter) {
            $options = '';
            foreach (SaleTicket::loadFormats($tpv->doctype) as $format) {
                $options .= '<option value="' . $format['nameFile'] . '"' . ($tpv->ticketformat == $format['nameFile'] ? ' selected' : '') . '>'
                    . Tools::lang()->trans(strtolower($format['label'])) . '</option>';
            }

            $html .= '<hr/>'
                . '<div class="form-row pt-3">'
                . '<div class="col-6">'
                . '<div class="form-group">'
                . '<select id="ticketformat" class="form-control form-control-lg" aria-describedby="ticket-print">'
                . $options
                . '</select>'
                . '</div>'
                . '</div>'
                . '<div class="col-6">'
                . '<div class="form-group">'
                . '<input type="number" class="form-control form-control-lg text-center" name="ticket_number" value="' . $tpv->ticket_number . '" min="1" step="1" >'
                . '</div>'
                . '</div>'
                . '<div class="col-6">'
                . '<div class="form-group">'
                . '<button class="btn btn-block btn-lg btn-primary btn-spin-action" type="button" id="ticket-print" onclick="printTicket('
                . $doc->primaryColumnValue() . ')" autofocus>'
                . '<i class="fas fa-receipt mr-1 fa-fw"></i>'
                . Tools::lang()->trans('print-ticket') . '</button>'
                . '</div>'
                . '</div>'
                . '<div class="col-6">'
                . '<div class="form-group">'
                . '<button class="btn btn-block btn-lg btn-outline-primary btn-spin-action" type="button" onclick="return openDrawer()">'
                . '<i class="fas fa-inbox mr-1 fa-fw"></i>'
                . Tools::lang()->trans('open-drawer') . '</button>'
                . '</div>'
                . '</div>'
                . '</div>';
        }

        // añadimos el botón de imprimir el pdf
        $html .= '<hr class="pb-3"/><a class="btn btn-primary btn-block btn-lg btn-spin-action" target="_blank" href="' . FS_ROUTE . '/Edit'
            . $doc->modelClassName() . '?code=' . $idDoc . '&action=export&option=PDF' . '">'
            . '<i class="far fa-file-pdf mr-1 fa-fw"></i>'
            . Tools::lang()->trans('print-' . strtolower($doc->modelClassName()))
            . '</a>';

        return $html;
    }

    public static function map(TpvTerminal $tpv): array
    {
        $num = 0;
        $map = [];
        foreach (self::$lines as $line) {
            $num++;

            // total
            $map['linetotal_' . $num] = Tools::money($line->pvptotal * (100 + $line->iva + $line->recargo - $line->irpf) / 100, $tpv->coddivisa);
        }

        return $map;
    }

    public static function recalculate(): void
    {
        Calculator::calculate(self::$doc, self::$lines, false);
    }

    public static function render(TpvTerminal $tpv): string
    {
        $i18n = Tools::lang();
        return '<form method="post" name="saleForm">'
            . '<input type="hidden" name="action">'
            . '<input type="hidden" name="action-code">'
            . '<input type="hidden" name="codcliente">'
            . '<input type="hidden" name="codpark">'
            . '<input type="hidden" name="new-desc">'
            . '<input type="hidden" name="new-tax">'
            . '<div id="linesCart" class="bg-white">'
            . self::renderLines($i18n, $tpv)
            . '</div>'
            . '</form>';
    }

    public static function renderModalTickets(TpvCaja $caja, string $codigo = ''): string
    {
        $html = '';
        $tpv = $caja->getTerminal();
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $tpv->doctype;
        $docModel = new $modelClass();
        $where = [];
        if (empty($codigo)) {
            $where[] = new DataBaseWhere('idcaja', $caja->idcaja);
        } else {
            $where[] = new DataBaseWhere('codigo', $codigo, 'LIKE');
            $where[] = new DataBaseWhere('idtpv', $tpv->idtpv);
        }

        foreach ($docModel->all($where, ['fecha' => 'DESC', 'hora' => 'DESC']) as $doc) {
            $cssTr = $doc->total > 0 ? 'table-success' : 'table-danger';

            $html .= '<tr class="' . $cssTr . '">'
                . '<td class="align-middle">' . Agentes::get($doc->codagente)->nombre . '</td>'
                . '<td class="align-middle text-nowrap">' . $doc->codigo . '</td>'
                . '<td class="align-middle text-nowrap">' . $doc->numero2 . '</td>'
                . '<td class="align-middle">' . $doc->nombrecliente . '</td>'
                . '<td class="align-middle">' . FormasPago::get($doc->codpago)->descripcion . '</td>'
                . '<td class="align-middle">' . $doc->observaciones . '</td>'
                . '<td class="align-middle text-right text-nowrap">' . Tools::money($doc->total, $tpv->coddivisa) . '</td>'
                . '<td class="align-middle text-right">' . $doc->fecha . ' ' . $doc->hora . '</td>'
                . '<td class="align-middle text-center">'
                . '<div class="btn-group" role="group">'
                . '<button type="button" onclick="return modalPrintTicket(\'' . $doc->primaryColumnValue() . '\')" title="' . Tools::lang()->trans('print')
                . '" class="btnPrintTicket btn btn-primary btn-spin-action"><i class="fas fa-print fa-fw"></i></button>';

            if ($doc->total > 0) {
                $html .= '<button type="button" onclick="return modalReturn(\'' . $doc->primaryColumnValue() . '\', this)" title="' . Tools::lang()->trans('returns')
                    . '" class="btnReturnTicket btn btn-warning btn-spin-action"><i class="fas fa-exchange-alt fa-fw"></i></button>';
            }

            $html .= '</div>'
                . '</td>'
                . '</tr>';
        }

        if (empty($html)) {
            $html .= '<tr class="table-warning">'
                . '<td colspan="9">' . Tools::lang()->trans('no-data') . '</td>'
                . '</tr>';
        }

        return $html;
    }

    protected static function resetDocTotal(): void
    {
        self::$doc->total = 0.0;
        self::$doc->neto = 0.0;
        self::$doc->totalbeneficio = 0.0;
        self::$doc->totalcoste = 0.0;
        self::$doc->totaleuros = 0.0;
        self::$doc->totalirpf = 0.0;
        self::$doc->totaliva = 0.0;
        self::$doc->totalrecargo = 0.0;
        self::$doc->totalsuplidos = 0.0;
    }

    public static function saveDoc(array $formData, User $user, TpvCaja $caja, ?string $codagente): bool
    {
        $tpv = $caja->getTerminal();
        static::apply($formData, $user, $tpv, $codagente);

        // restablecemos los totales antes de guardar
        self::resetDocTotal();

        // establecemos la forma de pago
        if ($formData['action'] == 'save-cart' && $formData['formasPagos'] >= 1) {
            foreach (FormasPago::all() as $pago) {
                if (!isset($formData[$pago->codpago])) {
                    continue;
                }

                self::$doc->codpago = $pago->codpago;
                break;
            }
        } else {
            self::$doc->codpago = $tpv->codpago;
        }
        if (!empty($formData['codpago'])) {
            self::$doc->codpago = $formData['codpago'];
        }

        self::$doc->idtpv = $tpv->idtpv;
        self::$doc->idcaja = $caja->idcaja;
        self::$doc->codserie = $formData['codserie'] ?? $tpv->codserie;
        self::$doc->coddivisa = $tpv->coddivisa;
        self::$doc->codalmacen = $tpv->codalmacen;
        self::$doc->observaciones = $formData['observations'] ?? '';
        self::$doc->numero2 = $formData['numero2'] ?? '';
        self::$doc->tpv_cambio = 0.0;
        self::$doc->tpv_efectivo = 0.0;
        self::$doc->tpv_venta = true;

        $dataBase = new DataBase();
        $dataBase->beginTransaction();

        if (self::$doc->save() === false) {
            $dataBase->rollback();
            return false;
        }

        // comprobamos las líneas
        foreach (self::$lines as $line) {
            // si la línea no tiene precio y no se permite vender sin precio, cancelamos la operación
            if ($tpv->not_line_price_empty && empty($line->pvpunitario)) {
                Tools::log()->warning('selling-lines-zero-price-not-allowed');
                $dataBase->rollback();
                return false;
            }

            // añadimos las líneas al documento
            $newLine = self::$doc->getNewLine($line->toArray());
            if ($newLine->save() === false) {
                $dataBase->rollback();
                return false;
            }
        }

        // calculamos los totales
        $lines = self::$doc->getLines();
        Calculator::calculate(self::$doc, $lines, false);

        // guardamos el dinero recibido en metálico y el cambio
        if (isset($formData[$tpv->codpago]) && $formData[$tpv->codpago] > 0) {
            self::$doc->tpv_efectivo = $formData[$tpv->codpago];
            self::$doc->tpv_cambio = max(self::$doc->tpv_efectivo - self::$doc->total, 0);
        }

        // guardamos documento
        if (false === self::$doc->save()) {
            Tools::log()->warning('error-saving-document');
            $dataBase->rollback();
            return false;
        }

        // si hay varias formas de pago, guardamos los recibos
        if ($formData['action'] == 'save-cart' && $formData['formasPagos'] >= 1) {

            if ($tpv->doctype === 'FacturaCliente') {

                // eliminamos los recibos anteriores
                foreach (self::$doc->getReceipts() as $recibo) {
                    $recibo->delete();
                }

                // obtenemos las formas de pago asociadas al TPV
                $tpvPago = new TpvPago();
                $where = [new DataBaseWhere('idtpv', $tpv->idtpv)];
                $tpvPagos = $tpvPago->all($where, [], 0, 0);

                // leemos todas las formas de pago
                foreach (FormasPago::all() as $pago) {
                    // si la forma de pago no existe en el array enviado de formas de pago usadas, continuamos
                    if (!isset($formData[$pago->codpago])) {
                        continue;
                    }

                    // creamos el recibo
                    $newRecibo = new ReciboCliente();
                    $newRecibo->codigofactura = self::$doc->codigo;
                    $newRecibo->idfactura = self::$doc->primaryColumnValue();
                    $newRecibo->codcliente = self::$doc->codcliente;
                    $newRecibo->nick = self::$doc->nick;
                    $newRecibo->codpago = $pago->codpago;

                    // si la forma de pago es la del TPV, y el total recibido es mayor o igual que el total de la factura,
                    // el importe del recibo es el total de la factura
                    if ($pago->codpago === $tpv->codpago && $formData[$pago->codpago] >= self::$doc->total) {
                        $newRecibo->importe = self::$doc->total;
                    } else {
                        $newRecibo->importe = $formData[$pago->codpago];
                    }

                    // si no tiene formas de pago asociadas al TPV, marcamos el recibo como pagado
                    if (count($tpvPagos) === 0) {
                        $newRecibo->pagado = true;
                    } else {
                        // si tiene formas de pago asociadas al TPV,
                        // comprobamos si la forma de pago es la del recibo y si la forma de pago está marcada como pagada
                        foreach ($tpvPagos as $tpvPago) {
                            if ($tpvPago->codpago === $newRecibo->codpago && $tpvPago->paid) {
                                $newRecibo->pagado = true;
                                break;
                            }
                        }
                    }

                    $newRecibo->save();
                }

            } elseif (Plugins::isEnabled('PrePagos')) {

                // es un albarán, guardamos los pagos como anticipos
                // leemos todas las formas de pago
                foreach (FormasPago::all() as $pago) {
                    // si la forma de pago no existe en el array enviado de formas de pago usadas, continuamos
                    if (!isset($formData[$pago->codpago])) {
                        continue;
                    }

                    // buscamos si había un anticipo aparcado
                    $totalAnticipo = 0;
                    if (isset($formData['codpark']) && $formData['codpark'] != '') {
                        $anticipoPrevio = new PrePago();
                        $where = [
                            new DataBaseWhere('modelname', 'PresupuestoCliente'),
                            new DataBaseWhere('modelid', $formData['codpark']),
                            new DataBaseWhere('codpago', $pago->codpago),
                        ];
                        if ($anticipoPrevio->loadFromCode('', $where)) {
                            $totalAnticipo = $anticipoPrevio->amount;

                            // asignamos el anticipo al albarán
                            $anticipoPrevio->modelname = self::$doc->modelClassName();
                            $anticipoPrevio->modelid = self::$doc->primaryColumnValue();
                            $anticipoPrevio->save();
                        }
                    }

                    $anticipo = new PrePago();
                    $anticipo->amount = $formData[$pago->codpago] - $totalAnticipo;
                    $anticipo->codcliente = self::$doc->codcliente;
                    $anticipo->codpago = $pago->codpago;
                    $anticipo->modelname = self::$doc->modelClassName();
                    $anticipo->modelid = self::$doc->primaryColumnValue();
                    if (false === empty($anticipo->amount)) {
                        $anticipo->save();
                    }
                }
            }
        }

        // recargamos el documento
        self::$doc->loadFromCode(self::$doc->primaryColumnValue());

        // ponemos la factura en emitida
        if ($formData['action'] == 'save-cart' && $tpv->doctype === 'FacturaCliente' && self::$doc->getStatus()->editable) {
            // cambiamos el estado de la factura si su estado actual es editable
            foreach (self::$doc->getAvailableStatus() as $stat) {
                if (false === $stat->editable) {
                    self::$doc->idestado = $stat->idestado;
                    self::$doc->save();
                    break;
                }
            }
        }

        // buscamos el presupuesto relacionado y lo eliminamos
        if ($formData['action'] == 'save-cart' && isset($formData['codpark']) && $formData['codpark'] != '') {
            $pr = new PresupuestoCliente();
            if ($pr->loadFromCode($formData['codpark'])) {
                $pr->delete();
            }
        }

        $dataBase->commit();
        self::$doc->code = self::$doc->primaryColumnValue();
        self::$lastDocSave = self::$doc;
        return true;
    }

    public static function setDoc($doc): void
    {
        self::$doc = $doc;
    }

    public static function setLines($lines): void
    {
        self::$lines = $lines;
    }

    public static function totalCart(): string
    {
        return self::$doc->total ?? 0;
    }

    protected static function getSymbol(TpvTerminal $tpv): string
    {
        // obtenemos la divisa del TPV
        $divisa = Divisas::get($tpv->coddivisa);

        // si existe la divisa, devolvemos el símbolo
        if ($divisa->exists()) {
            return $divisa->simbolo;
        }

        // si no existe obtenemos el símbolo de la divisa por defecto
        return Divisas::get(Tools::settings('default', 'coddivisa'))->simbolo;
    }

    protected static function renderLines(Translator $i18n, TpvTerminal $tpv): string
    {
        $html = '';
        $num = 1;
        foreach (self::$lines as $line) {
            $price = floatval($line->pvpunitario);

            if ($line->dtopor > 0) {
                $price = $price - ($price * ($line->dtopor / 100));
            }

            $pvpunitario = floatval($line->pvpunitario) * (100 + floatval($line->iva)) / 100;
            $total = (floatval($line->cantidad) * $price) * (100 + floatval($line->iva)) / 100;
            $descripcion = strlen($line->descripcion) > 120 ? substr($line->descripcion, 0, 120) . '...' : $line->descripcion;

            $html .= '<div class="p-2 border-bottom line line' . $num . '" referencia="' . $line->referencia . '">'
                . '<div class="mb-2 d-flex justify-content-between align-items-center">'
                . '<div class="w-100">'
                . '<input type="hidden" class="idlinea" name="idlinea_' . $num . '" value="' . $line->idlinea . '">'
                . '<input type="hidden" class="referencia" name="referencia_' . $num . '" value="' . $line->referencia . '">'
                . '<input type="hidden" class="descripcion" name="descripcion_' . $num . '" value="' . $line->descripcion . '">'
                . '<input type="hidden" class="pvpunitario" name="pvpunitario_' . $num . '" value="' . $line->pvpunitario . '">'
                . '<input type="hidden" class="codimpuesto" name="codimpuesto_' . $num . '" value="' . $line->codimpuesto . '">'
                . '<input type="hidden" class="orden" name="orden_' . $num . '" value="' . $line->orden . '">'
                . '<div>';

            if (false === empty($line->referencia)) {
                $variant = new Variante();
                $where = [new DataBaseWhere('referencia', $line->referencia)];
                if ($variant->loadFromCode('', $where)) {
                    $html .= self::getVariantImage($variant, 'photo-cart mr-2 float-left');
                }
            }

            $html .= '<button type="button" class="deleteProduct btn btn-outline-danger btn-spin-action float-right" onclick="return rmLine(\'' . $num . '\')">'
                . '<i class="fas fa-trash-alt"></i></button>';

            if (false === empty($line->referencia)) {
                $html .= '<span class="font-weight-bold mr-2">' . $line->referencia . '</span>';
            }

            $html .= '<span class="desc mr-2" onclick="changeDescriptionPrompt(\'' . $num . '\')">' . $descripcion . '</span>'
                . '</div>'
                . '</div>'
                . '</div>'
                . '<div class="form-row d-flex align-items-center">'
                . '<div class="col-auto">'
                . '<div class="form-row d-flex align-items-center">';

            $newCantidad = $line->cantidad - 1;
            $html .= '<div class="col"><button type="button" class="minusQty btn btn-outline-danger btn-spin-action" '
                . 'onclick="return lineQuantity(\'' . $num . '\',\'' . $newCantidad . '\')">'
                . '<i class="fas fa-minus"></i></button></div>';

            $newCantidad = $line->cantidad + 1;
            $html .= '<div id="line_qty_' . $num . '" class="col btn btn-link text-center qty" onclick="return changeQtyPrompt(\'' . $num . '\')">'
                . '<input type="hidden" name="cantidad_' . $num . '" value="' . $line->cantidad . '">'
                . $line->cantidad . '</div>'
                . '<div class="col"><button type="button" class="plusQty btn btn-outline-success btn-spin-action" '
                . 'onclick="return lineQuantity(\'' . $num . '\',\'' . $newCantidad . '\')">'
                . '<i class="fas fa-plus"></i></button></div>'
                . '</div>'
                . '</div>'
                . '<div class="col text-nowrap text-right">';

            if ($tpv->changeprice || empty($line->referencia)) {
                $html .= '<span id="line_price_' . $num . '" class="btn btn-link price" onclick="return changePricePrompt(\'' . $num . '\')">'
                    . Tools::money($pvpunitario, $tpv->coddivisa)
                    . '<input type="hidden" name="new_precio_' . $num . '" value="">'
                    . '</span>';
            } else {
                $html .= '<span id="line_price_' . $num . '" class="price">'
                    . Tools::money($pvpunitario, $tpv->coddivisa) . '</span>';
            }

            $html .= '</div>';

            if ($tpv->adddiscount) {
                $html .= '<div id="line_dto_' . $num . '" class="col dto btn btn-link text-nowrap text-right" onclick="return changeDtoPrompt(\'' . $num . '\')">'
                    . Tools::number($line->dtopor) . ' %'
                    . '<input type="hidden" name="dtopor_' . $num . '" value="' . $line->dtopor . '">'
                    . '</div>';
            }

            $html .= '<div id="line_total_' . $num . '" class="col total text-nowrap text-right">'
                . Tools::money($total, $tpv->coddivisa)
                . '</div>'
                . '</div>'
                . '</div>';

            $num++;
        }

        return $html;
    }
}