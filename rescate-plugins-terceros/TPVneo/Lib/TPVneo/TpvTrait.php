<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\TPVneo\Lib\TPVneo;

use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Variante;

trait TpvTrait
{
    protected static function getProductImage(Producto $product, string $classCSS = 'photo img-fluid'): string
    {
        $img = '';
        $images = $product->getImages();
        if (empty($images)) {
            return $img;
        }

        $file = $images[0]->getFile();
        return '<img src="' . $file->url('download-permanent') . '" class="' . $classCSS
            . '" loading="lazy" title="' . $product->descripcion . '" alt="' . $product->referencia . '">';
    }

    protected static function getVariantImage(Variante $variant, string $classCSS = 'photo img-fluid'): string
    {
        $img = '';
        $images = $variant->getImages();
        if (empty($images)) {
            return $img;
        }

        $product = $variant->getProducto();
        $file = $images[0]->getFile();
        return '<img src="' . $file->url('download-permanent') . '" class="' . $classCSS
            . '" loading="lazy" title="' . $product->descripcion . '" alt="' . $variant->referencia . '">';
    }
}