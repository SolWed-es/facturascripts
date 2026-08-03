# Rescate: 4 plugins de terceros parcheados a mano en producción

**Estado: cuarentena / rescate. NO integrado en el build de la imagen. Pendiente de
decisión de Iván sobre su sitio definitivo.**

> ⚠️ **Por qué esta PR aterriza aquí y no en `SolWed-es/w-erp`**: ese repo, descrito
> como "repo canónico" del ERP interno, está **archivado (solo lectura) desde
> 2026-07-08** — no admite PRs. Este fork del core (`SolWed-es/facturascripts`,
> no archivado) es el refugio disponible más cercano con el mismo fichero /
> misma familia de código. Esto en sí mismo es un hallazgo del rescate: nadie
> puede haber estado mergeando nada en el repo "canónico" desde esa fecha, lo
> cual encaja con que el parche de bigint (28-jul) y estos 4 plugins (antes
> del 23-jul) se aplicaran a mano en el contenedor en vez de por PR — puede que
> no hubiera dónde mandarlo. Pendiente de que Iván decida: desarchivar
> `w-erp`, o adoptar oficialmente este fork (u otro repo) como fuente
> canónica del core.

## Qué es esto

El 3-ago-2026 se detectó, mediante `docker diff` sobre el contenedor `w-erp` en
producción (`erp-solwed:vanilla-2026.3`, contenedor creado 2026-07-23), que estos
4 plugins de terceros ("Forja") estaban parcheados **directamente en la capa de
escritura del contenedor**, sin ninguna copia en ningún repositorio:

- `DescargarFacturasZIP`
- `PagoRecibosRedsys`
- `TPVneo`
- `FechaVentas`

Ninguno de los 4 está en `SolWed-es/SolwedPlugins-container` — la PR #10 de ese
repo ("chore: dejar solo plugins Solwed, Fase 3") los sacó deliberadamente de
ahí junto con el resto de plugins de terceros/Forja. Por eso **no se han vuelto
a meter en ese repo** con este rescate: sería revertir esa decisión sin que nadie
la haya tomado. Tampoco están en el repo `w-erp` propiamente dicho — `/Plugins/`
está en el `.gitignore` de este repo (nunca se ha versionado ahí), así que esta
carpeta (`rescate-plugins-terceros/`, fuera de `/Plugins/`) es una ubicación
**nueva y explícitamente de cuarentena**, no la integración real con el build.

Ninguno de los ficheros aquí dentro se ha modificado respecto a lo que corre
hoy en producción. Es una copia exacta (`docker cp` desde el contenedor +
verificación `php -l`), byte a byte.

## El parche: namespace `Core\Base\AjaxForms` → `Core\Lib\AjaxForms`

En algún momento el core de FacturaScripts 2026.x movió las clases
`AjaxForms\*` (`PurchasesLineHTML`, `SalesHeaderHTML`, `SalesLineHTML`) de
`Core\Base\AjaxForms` a `Core\Lib\AjaxForms`. Estos 4 plugins de terceros no se
actualizaron con ese movimiento y rompían con fatal error de namespace
inexistente. Alguien (antes del 23-jul-2026, fecha de creación del contenedor
actual) editó a mano el `Init.php` de cada uno en el contenedor para apuntar al
namespace nuevo.

Se ha verificado con `diff -ru` recursivo, plugin por plugin, contra una copia
limpia del **mismo** plugin extraída de un contenedor efímero de la imagen
`erp-solwed:vanilla-2026.3` (sin arrancar, solo para `docker cp`, luego
eliminado): **el único cambio en los 4 plugins es esa línea de `use` en
`Init.php`.** Nada más difiere — ni lógica, ni configuración, ni versión de
`facturascripts.ini`.

```diff
--- Init.php (imagen erp-solwed:vanilla-2026.3)
+++ Init.php (contenedor w-erp en vivo, 3-ago-2026)
-use FacturaScripts\Core\Base\AjaxForms\PurchasesLineHTML;   // DescargarFacturasZIP, PagoRecibosRedsys
+use FacturaScripts\Core\Lib\AjaxForms\PurchasesLineHTML;

-use FacturaScripts\Core\Base\AjaxForms\SalesHeaderHTML;     // TPVneo
+use FacturaScripts\Core\Lib\AjaxForms\SalesHeaderHTML;

-use FacturaScripts\Core\Base\AjaxForms\SalesLineHTML;       // FechaVentas
+use FacturaScripts\Core\Lib\AjaxForms\SalesLineHTML;
```

Riesgo si se pierde: si el contenedor `w-erp` se recrea desde la imagen
vanilla sin este fix, los 4 plugins vuelven a fallar con fatal error de
namespace en cuanto se carguen, silenciosamente, hasta que alguien intente
usarlos.

## Lo que NO se ha decidido (para Iván)

Este rescate solo pone los bytes a salvo con su diff documentado. Falta decidir
el sitio definitivo, con al menos estas opciones sobre la mesa:

1. **Repo nuevo dedicado** (p.ej. `SolWed-es/w-erp-plugins-terceros` o similar) —
   un lugar propio para "plugins de la Forja que Solwed mantiene parcheados",
   separado tanto del core (`w-erp`) como de los plugins propios
   (`SolwedPlugins-container`).
2. **`SolwedPlugins-container`, pero en un namespace/carpeta explícitamente
   marcada como "vendored de terceros, no propio"** — requiere acuerdo
   explícito porque contradice a primera vista la Fase 3 (separación
   forja/Solwed) si no se distingue con claridad de los plugins propios.
3. **Aquí mismo, en `w-erp`**, pero conectado de verdad al proceso de build
   (hoy `rescate-plugins-terceros/` NO se copia a la imagen; `Dockerfile.erp`
   solo hace `COPY . .` del repo, y con `/Plugins/` en `.gitignore` estos
   ficheros nunca han llegado ahí vía git) — exigiría además decidir cómo se
   instalan/actualizan de cara al futuro (¿override manual como ahora vía
   AdminPlugins/FSMaker, o un paso de build que los copie a `/Plugins/` en la
   imagen?).

Independientemente de cuál se elija, el parche de namespace en sí probablemente
quedará obsoleto si algún día estos plugins reciben una actualización oficial
de sus autores que ya use `Core\Lib\AjaxForms`.
