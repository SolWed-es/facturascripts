<?php
/**
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Mod;

use FacturaScripts\Core\Base\Contract\SalesModInterface;
use FacturaScripts\Core\Base\Translator;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Dinamic\Model\TpvCaja;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class SalesHeaderHTMLMod implements SalesModInterface
{
    public function apply(SalesDocument &$model, array $formData, User $user)
    {
        $box = new TpvCaja();
        $box->loadFromCode($formData['idcaja'] ?? null);

        $model->idcaja = $box->idcaja;
        $model->idtpv = $box->idtpv;

        if (in_array($model->modelClassName(), ['AlbaranCliente', 'FacturaCliente'])) {
            $model->tpv_cambio = floatval($formData['tpv_cambio'] ?? $model->tpv_cambio);
            $model->tpv_efectivo = floatval($formData['tpv_efectivo'] ?? $model->tpv_efectivo);
        }
    }

    public function applyBefore(SalesDocument &$model, array $formData, User $user): void
    {
    }

    public function assets(): void
    {
        AssetManager::add('js', FS_ROUTE . '/Dinamic/Assets/JS/AutocompleteTPVneo.js');
    }

    public function newBtnFields(): array
    {
        return [];
    }

    public function newFields(): array
    {
        return [];
    }

    public function newModalFields(): array
    {
        return ['tpv'];
    }

    public function renderField(Translator $i18n, SalesDocument $model, string $field): ?string
    {
        if ($field === 'tpv') {
            return self::tpv($i18n, $model);
        }

        return null;
    }

    private static function tpv(Translator $i18n, SalesDocument $model): string
    {
        // si no es uno de los documentos aceptados,
        // o no el documento no existe, terminamos
        if (false === $model->exists()
            || false === in_array($model->modelClassName(), ['PresupuestoCliente', 'AlbaranCliente', 'FacturaCliente'])) {
            return '';
        }

        $value = '';
        $box = new TpvCaja();
        if ($model->idcaja && $box->loadFromCode($model->idcaja)) {
            $value = $box->idcaja . ' | ' . $box->fechaini;

            if ($box->fechafin) {
                $value .= ' - ' . $box->fechafin;
            }
        }

        $html = '<div class="col-sm-6">'
            . '<a href="EditTpvCaja?code=' . $model->idcaja . '">'
            . $i18n->trans('pos-terminal') . ' - ' . $i18n->trans('box')
            . '</a>'
            . '<div class="input-group">'
            . '<div class="input-group-prepend">';

        if ($model->editable && $model->idcaja) {
            $html .= '<button type="button" id="deleteTPVneoBox" class="btn btn-warning">'
                . '<i class="fas fa-times" aria-hidden="true"></i>'
                . '</button>';
        } else {
            $html .= '<span id="searchTPVneoBox" class="input-group-text">'
                . '<i class="fas fa-search fa-fw"></i>'
                . '</span>';
        }

        $disabled = $model->editable ? '' : 'disabled';
        $html .= '</div>'
            . '<input type="hidden" name="idcaja" value="' . $model->idcaja . '">'
            . '<input type="text" id="findTPVneoBoxInput" class="form-control" value="' . $value . '" ' . $disabled . '/>'
            . '</div>'
            . '</div>';

        if (in_array($model->modelClassName(), ['AlbaranCliente', 'FacturaCliente'])) {
            $html = '<div class="col-12">'
                . '<div class="form-row">'
                . $html;

            $html .= '<div class="col-sm-3">'
                . $i18n->trans('pos-terminal') . ' - ' . $i18n->trans('cash')
                . '<div class="input-group">'
                . '<input type="number" class="form-control" name="tpv_efectivo" value="' . $model->tpv_efectivo . '" ' . $disabled . '>'
                . '</div>'
                . '</div>'
                . '<div class="col-sm-3">'
                . $i18n->trans('pos-terminal') . ' - ' . $i18n->trans('money-change')
                . '<div class="input-group">'
                . '<input type="number" class="form-control" name="tpv_cambio" value="' . $model->tpv_cambio . '" ' . $disabled . '>'
                . '</div>'
                . '</div>'
                . '</div>'
                . '</div>';
        }

        return $html;
    }

    private static function tpvCash(Translator $i18n, SalesDocument $model): string
    {
        // si no es uno de los documentos aceptados,
        // o no el documento no existe, terminamos
        if (false === $model->exists()
            || false === in_array($model->modelClassName(), ['AlbaranCliente', 'FacturaCliente'])) {
            return '';
        }

        $disabled = $model->editable ? '' : 'disabled';
        return '<div class="col-sm-6">'
            . $i18n->trans('box')
            . '<div class="input-group">'
            . '<input type="number" name="tpv_efectivo" value="' . $model->tpv_efectivo . '" ' . $disabled . '>'
            . '</div>';
    }
}
