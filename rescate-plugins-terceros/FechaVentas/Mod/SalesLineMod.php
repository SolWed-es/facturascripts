<?php
/**
 * Copyright (C) 2022-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\FechaVentas\Mod;

use FacturaScripts\Core\Base\Contract\SalesLineModInterface;
use FacturaScripts\Core\Base\Translator;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Model\Base\SalesDocumentLine;

class SalesLineMod implements SalesLineModInterface
{
    public function apply(SalesDocument &$model, array &$lines, array $formData)
    {
    }

    public function applyToLine(array $formData, SalesDocumentLine &$line, string $id)
    {
        $line->fecha = $formData['fecha_' . $id] ?? null;
    }

    public function assets(): void
    {
    }

    public function getFastLine(SalesDocument $model, array $formData): ?SalesDocumentLine
    {
        return null;
    }

    public function map(array $lines, SalesDocument $model): array
    {
        return [];
    }

    public function newModalFields(): array
    {
        return [];
    }

    public function newFields(): array
    {
        return ['fecha'];
    }

    public function newTitles(): array
    {
        return ['fecha'];
    }

    public function renderField(Translator $i18n, string $idlinea, SalesDocumentLine $line, SalesDocument $model, string $field): ?string
    {
        if ($field === 'fecha') {
            return $this->fecha($i18n, $idlinea, $line, $model);
        }
        return null;
    }

    public function renderTitle(Translator $i18n, SalesDocument $model, string $field): ?string
    {
        if ($field === 'fecha') {
            return $this->fechaTitle($i18n);
        }
        return null;
    }

    protected function fecha($i18n, $idlinea, $line, $model): string
    {
        $attributes = $model->editable ?
            'name="fecha_' . $idlinea . '"' :
            'disabled=""';

        $fecha = is_null($line->fecha) ? date('Y-m-d') : date('Y-m-d', strtotime($line->fecha));
        return '<div class="col-sm col-lg-1 order-3">'
            . '<div class="d-lg-none mt-3 small">' . $i18n->trans('date') . '</div>'
            . '<input type="date" ' . $attributes . ' value="' . $fecha . '" class="form-control form-control-sm border-0"/>'
            . '</div>';
    }

    protected function fechaTitle($i18n): string
    {
        return '<div class="col-lg-1 order-3">' . $i18n->trans('date') . '</div>';
    }
}
