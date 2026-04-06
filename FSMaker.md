# FSMaker — CLI para desarrollo de plugins FacturaScripts

> **Repo**: https://github.com/FacturaScripts/fsmaker
> **Packagist**: https://packagist.org/packages/facturascripts/fsmaker
> **Version instalada**: 2.2.0
> **Autor**: NeoRazorX / Carlos Garcia (FacturaScripts)
> **Licencia**: MIT

## Instalacion

```bash
# Dentro del container de dev
docker compose exec app bash

# Instalar globalmente
composer global require facturascripts/fsmaker

# Crear symlink para acceso directo
ln -sf /root/.composer/vendor/bin/fsmaker /usr/local/bin/fsmaker

# Verificar
fsmaker --version
# fsmaker 2.2.0
```

Para actualizar:
```bash
composer global update facturascripts/fsmaker
```

**Requisitos**: PHP 8.1+ y Composer.

---

## Comandos disponibles

### Scaffolding de plugins

| Comando | Descripcion |
|---------|-------------|
| `fsmaker plugin` | Crea la estructura completa de un nuevo plugin (carpetas, `facturascripts.ini`, etc.) |
| `fsmaker init` | Crea un archivo `Init.php` para el plugin |
| `fsmaker model` | Crea un modelo + tabla XML. Opcionalmente genera EditController y ListController |
| `fsmaker controller` | Crea un controlador (basico, ListController o EditController) |
| `fsmaker view` | Crea una vista Twig en la carpeta `View/` |
| `fsmaker extension` | Crea una extension de tabla, modelo, controlador, XMLView o vista |
| `fsmaker mod` | Crea un mod para modelos (Calculator, HTML Header, Line, Footer) |
| `fsmaker api` | Genera automaticamente endpoints API REST para los modelos del plugin |

### Workers y tareas programadas

| Comando | Descripcion |
|---------|-------------|
| `fsmaker worker` | Crea un nuevo Worker (tarea en background) y lo registra en `Init.php` |
| `fsmaker cron` | Crea un archivo `Cron.php` para el plugin |
| `fsmaker cronjob` | Crea un nuevo CronJob individual en `CronJob/` y lo registra en `Cron.php` |

### Migraciones y datos

| Comando | Descripcion |
|---------|-------------|
| `fsmaker migration` | Crea una nueva migracion de base de datos |
| `fsmaker translations` | Actualiza/descarga los archivos de traduccion del plugin |

### Testing

| Comando | Descripcion |
|---------|-------------|
| `fsmaker test` | Crea un nuevo test PHPUnit para el plugin |
| `fsmaker run-tests [path]` | Ejecuta los tests de FacturaScripts. `path` = ruta a la instalacion FS |

### Actualizacion de codigo

| Comando | Descripcion |
|---------|-------------|
| `fsmaker upgrade` | Migra codigo PHP/XML/Twig/INI: `ToolBox` -> `Tools`, namespaces, iconos `fas` -> `fa-solid`, tipos de retorno, etc. |
| `fsmaker upgrade-bs5` | Migra Bootstrap 4 a Bootstrap 5: `btn-block` -> `w-100`, `ml-*` -> `ms-*`, `data-toggle` -> `data-bs-toggle`, etc. |

### Generacion de ficheros auxiliares

| Comando | Descripcion |
|---------|-------------|
| `fsmaker github-action` | Crea archivo de GitHub Actions para CI/CD testing |
| `fsmaker gitignore` | Crea `.gitignore` optimizado para plugins FacturaScripts |
| `fsmaker zip` | Genera un ZIP del plugin listo para distribucion |

---

## Uso

Todos los comandos son interactivos. Se ejecutan desde la raiz del plugin:

```bash
cd /var/www/html/Plugins/MiPlugin/
fsmaker model
# -> Nombre del modelo? Ticket
# -> Tabla? mi_tickets
# -> Campos? (interactivo: nombre, tipo, nullable...)
# -> Crear EditController? Si
# -> Crear ListController? Si
# Genera: Model/Ticket.php, Table/mi_tickets.xml, Controller/EditTicket.php,
#         Controller/ListTicket.php, XMLView/EditTicket.xml, XMLView/ListTicket.xml
```

### Crear un worker (tarea en background)

```bash
fsmaker worker
# -> Nombre del worker? SyncRedisWorker
# Genera: Worker/SyncRedisWorker.php
# Registra automaticamente en Init.php
```

Los workers se ejecutan desde el sistema de colas de FacturaScripts y son ideales para tareas pesadas que no deben bloquear el request HTTP (sincronizaciones, envio de emails masivo, etc.).

### Crear un cronjob

```bash
fsmaker cronjob
# -> Nombre del cronjob? CleanExpiredTokens
# Genera: CronJob/CleanExpiredTokens.php
# Registra automaticamente en Cron.php
```

### Crear un modelo completo

```bash
fsmaker model
# Interactivo: nombre, tabla, columnas (tipo, nullable, default), indices
# Opcionalmente genera:
#   - EditController + XMLView
#   - ListController + XMLView
#   - Test PHPUnit basico
```

### Generar API REST

```bash
fsmaker api
# Escanea los modelos del plugin y genera endpoints REST automaticos
# GET/POST/PUT/DELETE por cada modelo
```

### Migrar codigo legacy

```bash
# Actualizar PHP/XML/Twig
fsmaker upgrade
# Cambios automaticos:
#   - $this->toolBox()->... -> Tools::...
#   - fas fa-icon -> fa-solid fa-icon
#   - Anade tipos de retorno PHP 8
#   - Actualiza namespaces

# Migrar Bootstrap 4 -> 5
fsmaker upgrade-bs5
# Cambios automaticos en XMLView:
#   - btn-block -> w-100
#   - ml-* -> ms-*, mr-* -> me-*
#   - pl-* -> ps-*, pr-* -> pe-*
#   - data-toggle -> data-bs-toggle
#   - data-dismiss -> data-bs-dismiss
#   - badge-* -> bg-*
#   - float-left/right -> float-start/end
```

---

## Estructura de plugin generado

```
MiPlugin/
├── facturascripts.ini          # Metadatos del plugin (nombre, version, requires)
├── Init.php                    # Inicializacion (rutas, extensiones, workers)
├── Cron.php                    # Tareas programadas
├── Controller/                 # Controladores (Edit*, List*, Api*, custom)
├── CronJob/                    # Cronjobs individuales
├── Model/                      # Modelos (ModelClass)
├── Worker/                     # Workers para cola de tareas
├── View/                       # Vistas Twig (.html.twig)
├── XMLView/                    # Definiciones de vistas XML (Edit*, List*, Settings*)
├── Table/                      # Definiciones de tablas XML
├── Translation/                # Traducciones (es_ES.json, en_EN.json)
├── Extension/                  # Extensiones a otros plugins/core
│   ├── Controller/
│   ├── Model/
│   ├── Table/
│   ├── XMLView/
│   └── View/
├── Assets/                     # Recursos estaticos
│   ├── CSS/
│   ├── JS/
│   └── Images/
├── Data/                       # Datos de importacion
│   ├── Codpais/ESP/
│   └── Lang/ES/
├── Test/main/                  # Tests PHPUnit
└── .github/workflows/          # CI/CD (generado con github-action)
```

---

## Arquitectura interna (v2.0+)

FSMaker v2.0 usa **Symfony Console** como base:

| Clase | Funcion |
|-------|---------|
| `Console/Application.php` | Registro de todos los comandos |
| `Console/BaseCommand.php` | Clase base con helpers comunes (buscar plugin, crear archivos) |
| `FileGenerator.php` | Genera archivos desde templates internos |
| `FileUpdater.php` | Actualiza archivos existentes (upgrade, upgrade-bs5) |
| `InitEditor.php` | Modifica `Init.php` para registrar workers, extensiones |
| `ApiGenerator.php` | Genera endpoints API REST automaticos |
| `RunTests.php` | Ejecutor de PHPUnit |
| `ZipGenerator.php` | Empaquetador ZIP |
| `UpdateTranslations.php` | Descarga traducciones de la comunidad |
| `Column.php` | Definicion de columnas para generacion de modelos |
| `Utils.php` | Utilidades compartidas |

---

## Tips para SolwedES

```bash
# Generar un nuevo worker para sync
cd /var/www/html/Plugins/SolwedES/
fsmaker worker
# -> RedisSyncWorker

# Generar un nuevo cronjob
fsmaker cronjob
# -> CleanExpiredOAuthTokens

# Generar test para un modelo nuevo
fsmaker test
# -> NombreModelTest

# Actualizar codigo tras upgrade de FS
fsmaker upgrade

# Generar ZIP para release
fsmaker zip
```
