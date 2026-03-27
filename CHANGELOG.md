# Changelog

Historial de cambios de FacturaSolwed. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

## [2026] - 2026-03-27

### Added
- Dashboard: grafico de ventas ultimos 12 meses con Chart.js
- Dashboard: header refactorizado con bloque `bodyHeaderOptions`
- Traducciones: clave `sales-last-12-months` (es_ES, en_EN)

### Changed
- Version bumpeada a 2026 — **FacturaSolwed v2026**

### Dev
- package.json: anadidos @pdfme/*, esbuild como devDependencies

## [2025.93] - 2026-03-23

### Branding
- Renombrado a **FacturaSolwed** en toda la UI (instalador, about, meta tags, traducciones)
- Release ZIP ahora se genera como `facturasolwed.zip`

### Diseno
- Tipografias alineadas con solwed-app: **Chakra Petch** (headings) + **Jura** (body)
- Eliminadas fuentes Inter y Orbitron (ya no se usan)
- CSS reescrito como **mobile-first**: base = movil, `min-width` media queries para tablet/desktop
- Sidebar off-canvas con sombra y backdrop en movil
- Header sticky con iconos de estado activo en amarillo SOLWED (`.solwed-header-icon`)
- Tabs con scroll horizontal y fade gradient en movil
- Tablas compactas con primera columna sticky en scroll horizontal
- Toolbar de botones scrollable en movil
- Breadcrumb truncado con ellipsis en movil
- Tamano de fuentes incrementado +4px en toda la interfaz
- Dark mode: contraste corregido en botones (hex para compatibilidad con bootstrap-compat)
- Dark mode: `--foreground` cambiado a `#fff` (blanco puro)
- Dark mode: badges, iconos sidebar (opacity 0.85), Chrome autofill override
- Boton `btn-warning` (amarillo) con texto oscuro forzado via `!important`
- Login card: logo sin restriccion de tamano

### Plugins
- Fuente de plugins simplificada: solo **demo.erpsolwed.es** + **GitHub container**
- Eliminada Forja como fuente de plugins en AdminPlugins (se mantiene solo para builds del core)
- FirmaAlbaran migrado a SolWed-es/SolwedPlugins-container
- Plugins/SolwedES desrastreado del repo core (sigue en disco, ignorado por git)

### Conexion con Mind
- Todas las conexiones externas apuntan exclusivamente a `mind.solwed.es`
- Zero conexiones a facturascripts.com o forja
- Fix: `telemetryManager` anadido a Updater.php (evita error Twig)
- Eliminado registro de Service Worker (causaba cache stale en Brave/Chrome)

### Limpieza
- Eliminados assets de `MyFiles/erpsolwed/` (9.2 MB, sin referencias)
- Eliminadas imagenes sin uso: LOGO3.png, Solwed.png, isotipo-dark.png, loader.gif
- Eliminado `plugin-list.json` obsoleto (el core lee de SolwedPlugins-container)
- Eliminado `replace_index_to_restore.php`, `scripts/import-demo-plugins.py`, `scripts/tag-plugins.sh`
- Eliminado `EditProducto.js` (sin referencias), `Backup.html.twig` (sin controlador)
- Eliminados tests e2e de Playwright y `playwright.config.ts`
- Desrastreado `Dinamic/` (238 archivos autogenerados)
- Desrastreado `.mcp.json` (config local)
- CSS: eliminadas 80+ lineas de codigo muerto (selectores duplicados, clases sin uso)
- JS: eliminado `searchOnSection()` muerto en MegaSearch, `console.log` debug en Custom, handler duplicado en theme.js
- Dashboard: eliminada macro `sectionNews` muerta
- fonts.css: de 386 a 35 lineas (solo Chakra Petch + Jura variable)
- bootstrap-compat.css: actualizado a Chakra Petch + Jura

### Fixes
- Traduccion faltante: `plugins-disabled-incompatible` en es_ES y en_EN
- Code style: `SolwedDemoPlugins.php` corregido (phpcbf)
- Meta tag deprecated `apple-mobile-web-app-capable` reemplazado por `mobile-web-app-capable`
- FontAwesome CSS redundante eliminado de MenuTemplate (ya carga el JS)
- `lucide.createIcons()` inline redundante eliminado (ya lo llama theme.js)
- Google Fonts CDN eliminado de MicroTemplate, reemplazado por fonts.css local

### Infraestructura
- Release workflow: anadido `npm install --production` para incluir node_modules en el ZIP
- Release workflow: exclusiones ampliadas (tests, config dev, docs)
- Docker entrypoint: anadido `npm install` automatico
- `.gitignore`: simplificado, eliminadas 6 excepciones de plugins legacy

## [2025.811] - 2026-03-16

### Anteriores
- Sistema de actualizaciones via SolWed GitHub + Mind proxy
- Tienda de plugins apuntando a SolwedPlugins-container
- Conexion con mind.solwed.es (MindClient, SolwedMindConnect)
- Dashboard sin seccion de noticias
- Tema SolWed con dark mode via data-theme
- Cache-busting con `ymdHi` en assets
- Fuentes locales (sin CDN externo)
- Lucide icons local
