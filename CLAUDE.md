# FacturaScripts - SolWed

## Arquitectura de repositorios

El ecosistema SolWed se distribuye en **3 repositorios**:

| Repo | Propósito |
|------|-----------|
| `SolWed-es/facturascripts` | **CORE solo** — fork de NeoRazorX con modificaciones SolWed |
| `SolWed-es/SolwedPlugins` | Plugin gestor/tienda — se mantiene como está |
| `SolWed-es/SolwedPlugins-container` | **Todos los plugins** — código fuente, ZIPs y catálogo |

### Regla fundamental

- `SolWed-es/facturascripts` contiene **únicamente el core**. NO debe tener ramas ni releases de plugins.
- Todos los plugins (propios e importados) viven en `SolWed-es/SolwedPlugins-container`.

---

## Repo 1: SolWed-es/facturascripts (CORE)

### Ramas

| Rama | Propósito |
|------|-----------|
| `master` | **INTOCABLE** — sync con upstream NeoRazorX/facturascripts |
| `solwed/production` | Producción. Core modificado + assets erpsolwed. **Rama por defecto.** |

### Modificaciones al Core (solwed/production)

#### Sistema de actualizaciones (reemplaza Forja/Telemetría)

| Archivo | Cambio |
|---------|--------|
| `Core/Internal/SolwedGitHub.php` | **NUEVO** — cliente GitHub API para updates del core |
| `Core/Controller/Updater.php` | Reemplaza Forja/Telemetría por `SolwedGitHub` |
| `Core/View/Updater.html.twig` | Sin telemetría ni registro |

#### Tienda de plugins (apunta a SolwedPlugins-container)

| Archivo | Cambio |
|---------|--------|
| `Core/Internal/SolwedGitHubPlugins.php` | **NUEVO** — fetcha `plugin-list.json` de SolwedPlugins-container |
| `Core/Controller/AdminPlugins.php` | Añade acción `github-install` + `markGithubPlugins()` |
| `Core/View/AdminPlugins.html.twig` | Muestra botón "Instalar" directo cuando `plugin.in_github` |

`SolwedGitHubPlugins.php` lee el catálogo desde:
```
https://raw.githubusercontent.com/SolWed-es/SolwedPlugins-container/main/plugin-list.json
```

#### Conexión con mind.solwed.es

| Archivo | Cambio |
|---------|--------|
| `Core/Internal/MindClient.php` | **NUEVO** — fire-and-forget emitter: `MindClient::emit($event, $data)` |
| `Core/Controller/SolwedMindConnect.php` | **NUEVO** — webhook público que recibe registro desde mind |
| `Core/Kernel.php` | Ruta `/SolwedMindConnect` registrada |

Eventos emitidos: `factura.created`, `cliente.created`, `pago.recibido`, `plugin.enabled/disabled/updated/removed`

#### Dashboard

- Sección de noticias eliminada (`loadNews()`, `sectionNews`) — no hay llamadas externas en el dashboard.

### Reglas Dinamic/

- **NO** copiar `Core/*.php` a `Dinamic/` con el mismo namespace → clase duplicada
- `Dinamic/Controller/Updater.php` debe ser proxy: `class Updater extends \FacturaScripts\Core\Controller\Updater {}`
- `Core/Internal/*` **nunca** se proxifica en `Dinamic/Internal/`

### Traducciones

Siempre que se use `trans('clave')` en una view o `Tools::log()->error/warning('clave')` en un controller, añadir la clave a:
- `Core/Translation/es_ES.json`
- `Core/Translation/en_EN.json`

Las claves van en **orden alfabético** dentro del JSON.

### Release del Core

```bash
git tag v2025.93 && git push origin v2025.93
```

El workflow `.github/workflows/release.yml` build el ZIP desde `solwed/production` y publica la release en GitHub.

---

## Repo 2: SolWed-es/SolwedPlugins

Plugin gestor/tienda. Se mantiene como está. No requiere cambios de arquitectura.

---

## Repo 3: SolWed-es/SolwedPlugins-container (PLUGINS)

### Estructura

```
SolwedPlugins-container/
├── plugins/          ← código fuente de cada plugin
│   ├── CRM/
│   ├── IeCRMCalendar/
│   ├── SolwedTheme/
│   └── ...
├── zip/              ← ZIPs generados automáticamente
├── plugin-list.json  ← catálogo actualizado automáticamente
└── .github/workflows/update-plugin-list.yml
```

### Flujo de publicación de plugins

1. Desarrollar/modificar el plugin en `plugins/NombrePlugin/`
2. Hacer push a `main`
3. El workflow `update-plugin-list.yml` genera automáticamente:
   - Los ZIPs en `zip/`
   - Actualiza `plugin-list.json`
4. La tienda del Core descarga desde `zip/NombrePlugin.zip`

**No se usan GitHub Releases para plugins** — los ZIPs se sirven directamente desde el repo.

### Estado actual (mar 2026)

Plugins presentes en SolwedPlugins-container: 13 (DescargarFacturasZIP, DescuentoProducto, DocumentosProyectos, Dominios, HumanResourcesSolwed, IeCRMFormularioElementor, IeWhatsapp, MerakiPlugin, PleskServers, Rdgarantia, SolwedTheme, SolwedTiendaWeb, Vehiculos)

**Pendiente de migrar** desde facturascripts al container: CRM, IeCRMCalendar, Blog, ImportarFacturasEmail, SolwedPlugins, y los demás plugins importados.

---

## Estado actual del repo facturascripts (deuda técnica)

El repo `facturascripts` tiene actualmente ramas `plugin/*` y releases de plugins que son **legacy** y no deberían estar ahí:

- ~35 ramas `plugin/*` — a eliminar progresivamente
- ~31 releases de plugins — legacy, se mantendrán pero no se actualizarán
- `plugin-list.json` en `solwed/production` — obsoleto (el Core ya lee de SolwedPlugins-container)

**NO crear nuevas ramas plugin/* ni nuevas releases de plugins en este repo.**

---

## Flujo de trabajo correcto

### Desarrollar o modificar un plugin

1. Clonar/editar en `SolwedPlugins-container/plugins/NombrePlugin/`
2. Push a `main` → el workflow genera el ZIP y actualiza `plugin-list.json`

### Actualizar el core o plugins del repo

1. Trabajar en `solwed/production`
2. `git push origin solwed/production`
3. Deploy: `cd /opt/solwed/stack && ./deploy.sh deploy erp`

### Crear release del core (ZIP para distribución)

1. `git tag v2025.XX && git push origin v2025.XX`
2. El workflow `release.yml` genera el ZIP automáticamente

### Sincronizar con upstream

1. `git fetch upstream`
2. Merge `upstream/master` → `master`
3. Merge/rebase `master` → `solwed/production`

---

## Assets de diseño (solwed/production)

Los assets del proyecto `erpsolwed` (archivado) están en `MyFiles/erpsolwed/`:

```
MyFiles/erpsolwed/
├── logos/   (logo-claro.png, logo-oscuro.png, servicio.png, favicon.svg)
└── images/  (mockup-erp.png, og-image.png, ...)
    └── features/  (contabilidad, facturacion, informes, inventario, tpv, verifactu, analisis, logistica)
```

---

## Docker

### Arquitectura

```
┌─────────────────────────────────────────────┐
│  Imagen (ghcr.io/solwed-es/erp-solwed)      │
│  Core/ + Plugins/ + vendor/ (inmutable)     │
└──────────────┬──────────────────────────────┘
               │  docker compose up
               ▼
┌─────────────────────────────────────────────┐
│  Container (erp-solwed)                      │
│  Dinamic/ regenerado en cada arranque        │
└──────────────┬──────────────────────────────┘
               │  mount
               ▼
┌─────────────────────────────────────────────┐
│  Volumen (erp-myfiles)                       │
│  uploads, plugins.json, cache, routes.json   │
└─────────────────────────────────────────────┘
```

| Qué | Dónde | Motivo |
|-----|-------|--------|
| Core + Plugins + vendor | **Imagen** | Se actualiza con cada deploy |
| MyFiles (uploads, cache, plugins.json) | **Volumen** | Datos persistentes |
| Dinamic/ | **Container** (efímero) | Regenerado por entrypoint |
| config.php | **Bind mount** | Config específica por entorno |

### Desarrollo local

```bash
docker compose up -d
```

- App: http://localhost:8080 (código montado desde host, cambios en vivo)
- PostgreSQL: `localhost:5433`
- Redis: `localhost:6379`

El `docker-compose.yml` del repo monta `.:/var/www/html` para que cualquier cambio en Core/, Plugins/, views, etc. se refleje al instante sin rebuild.

### Producción (erp.solwed.es)

Producción corre en Docker con nginx como reverse proxy → `127.0.0.1:8081`.

Deploy:
```bash
cd /opt/solwed/stack
./deploy.sh deploy erp    # build → push GHCR → pull en prod → restart
```

El mismo comando sirve para cambios en Core o Plugins. No hay que copiar archivos manualmente.

Compose de producción en: `/opt/docker-solwed/docker-compose.yml`

### Credenciales PostgreSQL

| Entorno | Host | Puerto | DB | User | Pass |
|---------|------|--------|----|------|------|
| Dev | `db` / `localhost` | `5433` | `facturascripts` | `postgres` | `postgres` |
| Prod | `postgres` (container) | `5432` | `facturascripts` | `fs_user` | (ver config.prod.php) |

### Archivos Docker

| Archivo | Propósito |
|---------|-----------|
| `Dockerfile` | Imagen dev: php:8.2-apache + ext-redis + extensiones + entrypoint |
| `docker-compose.yml` | Dev: app + db + redis, código montado como volumen |
| `.docker/entrypoint.sh` | composer install + regenera Dinamic/ + permisos |
| `.docker/apache.conf` | VirtualHost con AllowOverride All |
| `.docker/php.ini` | 99M upload, 256M memory, 10000 input_vars |
| `.docker/config.php` | Plantilla config para dev |
| `stack/dockerfiles/Dockerfile.erp` | Imagen prod: COPY código + composer + npm |

### Permisos (problema frecuente en dev)

```bash
# git falla ("Permission denied") → arreglar en host:
sudo chown -R ivan:ivan Core/ Plugins/

# App PHP falla → restart regenera permisos via entrypoint:
docker compose restart
```

---

## FSMaker — CLI para scaffolding de plugins

Herramienta oficial de FacturaScripts para generar código de plugins.

**Repo**: https://github.com/FacturaScripts/fsmaker
**Version instalada**: 2.2.0 (en container dev)

### Instalación (ya hecha en dev)

```bash
docker compose exec app bash
composer global require facturascripts/fsmaker
ln -sf /root/.composer/vendor/bin/fsmaker /usr/local/bin/fsmaker
```

### Comandos principales

| Comando | Genera |
|---------|--------|
| `fsmaker plugin` | Estructura completa de nuevo plugin |
| `fsmaker model` | Modelo + tabla XML + opcionalmente Edit/ListController + XMLView |
| `fsmaker controller` | Controller (básico, List o Edit) |
| `fsmaker worker` | Worker para cola de background + lo registra en Init.php |
| `fsmaker cron` | Archivo Cron.php |
| `fsmaker cronjob` | CronJob individual + lo registra en Cron.php |
| `fsmaker api` | Endpoints API REST automáticos para modelos |
| `fsmaker extension` | Extensión de tabla/modelo/controller/XMLView/vista |
| `fsmaker mod` | Mod para modelos (Calculator, HTML Header, Line, Footer) |
| `fsmaker migration` | Migración de base de datos |
| `fsmaker test` | Test PHPUnit |
| `fsmaker view` | Vista Twig |
| `fsmaker upgrade` | Migra código legacy (ToolBox→Tools, fas→fa-solid, tipos retorno PHP 8) |
| `fsmaker upgrade-bs5` | Migra Bootstrap 4→5 en XMLViews |
| `fsmaker github-action` | GitHub Actions CI/CD |
| `fsmaker gitignore` | .gitignore optimizado |
| `fsmaker translations` | Descarga/actualiza traducciones |
| `fsmaker zip` | ZIP del plugin para distribución |
| `fsmaker run-tests [path]` | Ejecuta PHPUnit |

### Uso con SolwedES

Ejecutar siempre desde la raíz del plugin:

```bash
docker compose exec app bash
cd /var/www/html/Plugins/SolwedES/

# Crear nuevo worker
fsmaker worker
# → RedisSyncWorker (se registra automáticamente en Init.php)

# Crear nuevo modelo con controllers
fsmaker model
# → Nombre, tabla, campos (interactivo) → genera Model/, Table/, Controller/, XMLView/

# Crear cronjob
fsmaker cronjob
# → CleanExpiredTokens (se registra en Cron.php)

# Generar API REST para modelos
fsmaker api

# Generar test
fsmaker test

# Migrar código tras upgrade de FS
fsmaker upgrade
fsmaker upgrade-bs5

# Generar ZIP para release
fsmaker zip
```

### Estructura que genera fsmaker

```
Plugin/
├── facturascripts.ini
├── Init.php                    # Workers registrados aquí
├── Cron.php                    # CronJobs registrados aquí
├── Controller/                 # Edit*, List*, Api*, custom
├── CronJob/                    # Tareas programadas individuales
├── Worker/                     # Workers de background
├── Model/
├── View/                       # Twig templates
├── XMLView/                    # Definiciones XML de vistas
├── Table/                      # Definiciones XML de tablas
├── Extension/                  # Extensiones a core/otros plugins
├── Assets/CSS/ JS/ Images/
├── Data/Codpais/ Lang/
├── Test/main/
└── Translation/
```

---

## Plugin SolwedES — Arquitectura

### Servicios externos via Bridge + Redis

SolwedES **no llama directamente a APIs externas**. Toda comunicación con servicios externos sigue este patrón:

```
┌──────────────────────────────────────────────┐
│  SolwedES (PHP)                               │
│  ┌──────────────┐  ┌───────────────────────┐ │
│  │ RedisReader   │  │ BridgeClient          │ │
│  │ (lecturas)    │  │ (escrituras HTTP)     │ │
│  └──────┬───────┘  └──────────┬────────────┘ │
└─────────┼──────────────────────┼──────────────┘
          │                      │
          ▼                      ▼
  ┌───────────────┐    ┌──────────────────────┐
  │ Redis :6379    │◄──│ solwed-bridge :3009   │
  │ (compartido)   │   │ SyncWorkers → Redis   │
  └───────────────┘    │ Providers → APIs ext.  │
                       └──────────────────────┘
```

**LECTURA → Redis** (sub-ms). **ESCRITURA → BridgeClient** (bridge ejecuta en API externa e invalida cache).

### Mapa de claves Redis

**Datos de APIs externas** (bridge sync workers → Redis, cada 30 min):

| Prefijo | Fuente | Claves |
|---|---|---|
| `dd:*` | DonDominio | `dd:domains`, `dd:domain:{name}` |
| `plesk:*` | Plesk | `plesk:sites`, `plesk:site:{domain}`, `plesk:mail:{domain}`, `plesk:php:{domain}`, `plesk:ssl:{domain}` |
| `kolab:*` | Kolab | `kolab:domains`, `kolab:users:{domain}`, `kolab:domain:{domain}` |
| `cf:*` | Cloudflare | `cf:zones`, `cf:zone:{domain}`, `cf:dns:{zoneId}`, `cf:ssl:{zoneId}` |

**Datos del ERP** (FacturaScriptsSync via Cron + Workers real-time):

| Prefijo | Fuente | Claves |
|---|---|---|
| `fs:suscripciones` | Suscripcion model | Todas las activas/pendientes |
| `fs:suscripcion:{id}` | Suscripcion model | Detalle individual |
| `fs:pagos:recientes` | PagoStripe model | Últimos 100 pagos |
| `fs:facturas:recientes` | FacturaCliente model | Últimas 100 facturas |
| `fs:clientes` | Cliente model | Todos los clientes |
| `fs:cliente:{codcliente}` | Cliente model | Detalle individual |
| `fs:dominios` | Dominio model | Todos los dominios FS |
| `fs:servicios` | Servicio model | Todos los servicios con precios |
| `fs:servicios:catalogo` | Servicio model | Agrupado por categoría |
| `fs:servicio:{id}` | Servicio model | Detalle individual con precios |
| `fs:stats` | Calculado | MRR, totales, timestamp |

### Workers (cola de background)

Registrados en `Init.php` con `WorkQueue::addWorker()`:

| Worker | Eventos | Función |
|---|---|---|
| `RedisSyncWorker` | `Model.Suscripcion.*`, `Model.PagoStripe.*`, `Model.Dominio.*`, `Model.Servicio.*`, `Model.Cliente.*`, `Model.FacturaCliente.*` | Sync real-time a Redis cuando cambia un modelo |
| `WordPressProvisionWorker` | `solwed.provision.wordpress` | Provisioning WP en background (30-60s) |
| `DomainSyncWorker` | `solwed.sync.domains` | Sync dominios Redis → modelo FS Dominio |
| `ServiceExpiringWorker` | (manual dispatch) | Notificación email de servicios por vencer |

### CronJobs

Definidos en `Cron.php`, lógica en `CronJob/`:

| CronJob | Frecuencia | Función |
|---|---|---|
| `CronJob/SyncDomains.php` | Cada 1h | Despacha DomainSyncWorker |
| `CronJob/CheckExpiringServices.php` | Diario 8:00 | Log servicios por vencer |
| `CronJob/SyncRedis.php` | Cada 30min | Full sync FS→Redis (safety net) |

### APIs

| Endpoint | Lee de | Función |
|---|---|---|
| `/ApiRedis?key=fs:*` | Redis | Endpoint genérico para cualquier clave Redis |
| `/ApiRedis?action=sync` | DB→Redis | Fuerza sync FS→Redis |
| `/ApiSuscripcion` | Redis → DB fallback | CRUD suscripciones |
| `/ApiServicio` | Redis → DB fallback | Catálogo de servicios |
| `/ApiStripe` | Bridge | Billing portal, payment intents, métodos de pago |
| `/ApiDominio` | Redis + Bridge | Dominios, checkout renovación |
| `/ApiOAuth` | DB (OAuthToken model) | OAuth flows (authorize, callback, tokens) |
| `/ApiHealth` | Directo | Health check infraestructura |
| `/ApiProvision` | Bridge | Provisioning de servicios |

Las APIs de lectura (GET) intentan Redis primero, fallback a DB. La respuesta incluye `"source": "redis"` o `"source": "db"`.

### Archivos clave del plugin

```
Plugins/SolwedES/
├── Init.php                         # Rutas, extensiones, WorkQueue::addWorker()
├── Cron.php                         # 3 jobs → delega a CronJob/
├── Lib/
│   ├── RedisReader.php              # Lee de Redis (ext-phpredis)
│   ├── RedisWriter.php              # Escribe a Redis con TTL
│   ├── BridgeClient.php             # HTTP client → solwed-bridge :3009
│   ├── FacturaScriptsSync.php       # Vuelca FS data → Redis (7 tablas)
│   ├── StripeHelper.php             # Stripe via BridgeClient (sin SDK)
│   ├── StripeSubscriptionManager.php # Lógica negocio suscripciones (via Bridge)
│   ├── StripeUtils.php              # Utilidades formato Stripe
│   ├── DonDominioHelper.php         # Dominios via Redis + BridgeClient (sin SDK)
│   ├── PleskApiClient.php           # Plesk via Redis + BridgeClient (sin SDK)
│   ├── WordPressProvisioner.php     # Orquesta provisioning WP
│   ├── EmailManager.php             # Emails con PDF (sistema interno FS)
│   ├── AlbaranManager.php           # Gestión albaranes
│   ├── ClienteServiciosManager.php  # Relación clientes-servicios
│   ├── ServiceAccessManager.php     # Control acceso a servicios
│   ├── ServiceUpgradeManager.php    # Upgrades/downgrades
│   ├── PortalServiciosRenderer.php  # Renderizado portal
│   ├── SolwedLogger.php             # Logging estructurado
│   └── ProvisioningResult.php       # Result object provisioning
├── Worker/
│   ├── RedisSyncWorker.php          # Real-time sync Model.* → Redis
│   ├── WordPressProvisionWorker.php # WP provisioning background
│   ├── DomainSyncWorker.php         # Domain Redis → FS model
│   └── ServiceExpiringWorker.php    # Email notificación vencimiento
├── CronJob/
│   ├── SyncDomains.php
│   ├── SyncRedis.php
│   └── CheckExpiringServices.php
├── Controller/                      # ~30 controllers (API, Edit, List, Portal)
├── Model/                           # 12 modelos
├── View/                            # Twig templates
├── XMLView/                         # 19 definiciones XML
├── Table/                           # 13 definiciones de tablas
├── Test/main/                       # 10 tests
└── Translation/es_ES.json
```

### Settings del plugin

Configurados en `Init.php::setupBridgeSettings()`:

| Setting | Default | Uso |
|---|---|---|
| `solwed.bridge_url` | `http://solwed-bridge:3009` | URL del bridge |
| `solwed.bridge_token` | (vacío) | Bearer token para auth al bridge |
| `solwed.redis_host` | `redis` | Host Redis |
| `solwed.redis_port` | `6379` | Puerto Redis |
| `solwed.portal_url` | `https://app.solwed.es` | URL portal cliente |

---

## Git config para commits SolWed

```bash
git config user.name "Iván Moreno Quiros"
git config user.email "dev@solwed.es"
```

---

## Logging & Observabilidad (Loki/Grafana)

Los logs de Apache/PHP del container ERP son recogidos por Promtail via Docker socket y enviados a Loki.

Filtrar en Grafana: `{container="erp-solwed"}`

Para logs estructurados desde PHP (SolwedES plugin), escribir JSON a stderr:
```php
error_log(json_encode([
    "timestamp" => date("c"),
    "level" => "info",
    "service" => "erp-solwed",
    "scope" => "api",
    "message" => "Contrato activado",
    "idcontacto" => 42,
]));
```

**Grafana:** `http://localhost:3002` (local), `grafana.solwed.es` (prod)
