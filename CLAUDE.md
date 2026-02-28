# FacturaScripts - SolWed

## Estructura de repositorios

Todo el ecosistema FacturaScripts de SolWed está centralizado en **SolWed-es/facturascripts**.

### Ramas principales

| Rama | Propósito |
|------|-----------|
| `master` | **INTOCABLE** — sync con upstream NeoRazorX/facturascripts. Solo se actualiza haciendo merge del upstream. |
| `solwed/production` | Rama de producción. Contiene assets de diseño de erpsolwed en `MyFiles/erpsolwed/`. |

### Ramas de plugins

Cada plugin parte de `master` y añade únicamente su carpeta `Plugins/NombrePlugin/`.
El `.gitignore` de cada rama excluye todos los plugins excepto el propio.

| Rama | Plugin | Descripción |
|------|--------|-------------|
| `plugin/SolwedTheme` | `Plugins/SolwedTheme/` | Tema visual personalizado de SolWed (CSS, JS, Twig templates) |
| `plugin/SolwedPlugins` | `Plugins/SolwedPlugins/` | Gestor de plugins con instalador desde GitHub |
| `plugin/Blog` | `Plugins/Blog/` | Plugin de blog con modelos, API y soporte AI |
| `plugin/ImportarFacturasEmail` | `Plugins/ImportarFacturasEmail/` | Importación automática de facturas por email (IMAP + cron) |

### Assets de diseño (solwed/production)

Los assets del proyecto `erpsolwed` (archivado) están en `MyFiles/erpsolwed/`:

```
MyFiles/erpsolwed/
├── logos/
│   ├── logo-claro.png
│   ├── logo-oscuro.png
│   ├── servicio.png
│   └── favicon.svg
└── images/
    ├── mockup-erp.png
    ├── og-image.png
    ├── faq-illustration.png
    ├── targeting.webp
    ├── FS.gif
    └── features/
        ├── contabilidad.png
        ├── facturacion.png
        ├── informes.png
        ├── inventario.png
        ├── tpv.png
        ├── verifactu.png
        ├── analisis.webp
        └── logistica.webp
```

## Otros repositorios relacionados (SolWed-es)

| Repo | Visibilidad | Descripción |
|------|-------------|-------------|
| `SolwedTheme` | Público | Espejo/versión standalone del tema CSS |
| `SyncWoocommerceProducts` | Público | Plugin sync productos con WooCommerce |
| `IeWhatsapp` | Público | Plugin WhatsApp para FacturaScripts |

## Repositorios archivados/obsoletos

| Repo | Descripción |
|------|-------------|
| `ivan95mq/erpsolwed` | Web Astro del ERP (archivado). Assets migrados a `solwed/production`. |
| `SolWed-es/facturascripts-solwed` | Fork previo con plugins integrados. Migrado a ramas plugin/* en este repo. |

## Flujo de trabajo

1. **Actualizar core**: merge desde `upstream/master` → `master`
2. **Desarrollar plugin**: trabajar en la rama `plugin/NombrePlugin`
3. **Producción**: mergear plugins necesarios en `solwed/production`

## Git config para commits SolWed

```bash
git config user.name "Iván Moreno Quiros"
git config user.email "dev@solwed.es"
```
