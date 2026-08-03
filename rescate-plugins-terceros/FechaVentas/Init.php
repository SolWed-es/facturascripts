<?php
/**
 * Copyright (C) 2022-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\FechaVentas;

use FacturaScripts\Core\Lib\AjaxForms\SalesLineHTML;
use FacturaScripts\Core\Template\InitClass;

final class Init extends InitClass
{
    public function init(): void
    {
        // se ejecuta cada vez que carga FacturaScripts (si este plugin está activado).
        SalesLineHTML::addMod(new Mod\SalesLineMod());
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        // se ejecuta cada vez que se instala o actualiza el plugin.
    }
}
