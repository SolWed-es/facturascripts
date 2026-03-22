<?php
/**
 * This file is part of FirmaAlbaran plugin for FacturaScripts
 * Copyright (C) 2025 Solwed <dev@solwed.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

namespace FacturaScripts\Plugins\FirmaAlbaran;

use FacturaScripts\Core\Template\InitClass;

/**
 * Plugin FirmaAlbaran - Firma digital de albaranes de cliente.
 *
 * Extiende la tabla albaranescli con dos campos:
 *   - firma:    TEXT       — imagen de la firma en base64 (PNG)
 *   - firmante: VARCHAR(100) — nombre del firmante
 *
 * Una vez instalado, el endpoint nativo del API v3 funciona sin código adicional:
 *   PUT /api/3/albaranclientes/{id}  body: { "firma": "data:image/png;base64,...", "firmante": "Juan García" }
 *
 * @author Solwed <dev@solwed.es>
 */
final class Init extends InitClass
{
    public function init(): void
    {
    }

    public function update(): void
    {
    }

    public function uninstall(): void
    {
    }
}
