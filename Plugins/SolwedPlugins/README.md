# SolwedPlugins - Gestor de Plugins para FacturaScripts

[![Licencia: LGPL](https://img.shields.io/badge/Licencia-LGPL%20v3-blue.svg)](https://www.gnu.org/licenses/lgpl-3.0)
[![FacturaScripts](https://img.shields.io/badge/FacturaScripts-2024.0%2B-green.svg)](https://facturascripts.com)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net/)

Un poderoso sistema de gestión de plugins para FacturaScripts que te permite instalar, actualizar y gestionar plugins desde un repositorio de GitHub con validación automática de compatibilidad.

## Características

- 🚀 **Instalación con Un Clic:** Instala plugins directamente desde GitHub
- 🔄 **Actualizaciones Automáticas:** Comprueba e instala actualizaciones de plugins
- ✅ **Validación de Compatibilidad:** Verificación automática de versiones de PHP y FacturaScripts
- 🎯 **Compatible hacia Atrás:** Soporte para plugins desde FacturaScripts 2024 en adelante
- 🌐 **Multiidioma:** Soporte completo en español e inglés
- 📱 **Interfaz Responsiva:** Interfaz de administración integrada con Bootstrap
- 🔒 **Seguro:** Control de acceso basado en permisos

## Requisitos

- **PHP:** 8.0 o superior
- **FacturaScripts:** 2024.0 o superior
- **Base de Datos:** MySQL 5.7+ o PostgreSQL 9.6+

## Instalación

1. Descarga o clona el plugin en el directorio `Plugins/SolwedPlugins`:
```bash
git clone https://github.com/yourusername/SolwedPlugins.git Plugins/SolwedPlugins
```

2. Inicia sesión en el panel de administración de FacturaScripts

3. Ve a **Administrador > Plugins**

4. Activa el plugin **SolwedPlugins**

5. ¡El plugin estará listo para usar!

## Uso

### Acceder al Portal Solwed

1. Ve a **Administrador > Plugins**
2. Haz clic en la pestaña **Portal Solwed**
3. Explora los plugins disponibles desde GitHub

**Nota:** La página `SolwedPluginPortal` independiente ha sido deprecada e integrada en Administrador > Plugins para una interfaz unificada.

### Instalar un Plugin

1. Encuentra el plugin que deseas instalar
2. Haz clic en el botón **Instalar**
3. Confirma la instalación
4. El plugin se activará automáticamente

### Actualizar un Plugin

1. Ve a la sección **Actualizaciones Disponibles**
2. Haz clic en el botón **Actualizar** del plugin deseado
3. La actualización se instalará automáticamente

### Gestionar Plugins Instalados

1. Ve a la sección **Instalados**
2. Consulta la información de versión y estado de compatibilidad
3. Actualiza o desinstala plugins según sea necesario

## Configuración

### Estructura de Archivos

```
SolwedPlugins/
├── Controller/
│   ├── AdminPlugins.php                 # Extensión de AdminPlugins con características Solwed
│   └── SolwedPluginPortal.php           # Deprecado (redirige a AdminPlugins)
├── Lib/
│   ├── PluginValidator.php              # Utilidad de validación
│   ├── PluginCompatibilityOverride.php  # Sobrescritura de compatibilidad
│   └── SolwedGitHubPlugins.php          # Integración con GitHub
├── View/
│   ├── AdminPlugins.html.twig           # Vista extendida de AdminPlugins con pestaña Portal Solwed
│   └── SolwedPluginPortal.html.twig     # Deprecado (muestra noticia de deprecación)
├── Translation/
│   ├── es_ES.json                       # Traducciones al español
│   └── en_EN.json                       # Traducciones al inglés
├── webroot/
│   ├── css/solwed-portal.css            # Estilos del portal
│   └── js/solwed-portal.js              # Scripts del portal
├── facturascripts.ini                   # Configuración del plugin
├── Init.php                             # Inicialización del plugin
└── README.md                            # Este archivo
```

### Sobrescritura de Compatibilidad

El plugin sobrescribe automáticamente la verificación de versiones de FacturaScripts para permitir que los plugins construidos para FacturaScripts 2024.0 y posterior funcionen sin problemas.

**Comportamiento de la Sobrescritura:**
- ✅ Acepta plugins con `min_version >= 2024`
- ✅ Valida el requisito de versión de PHP
- ✅ Valida el requisito de versión mínima de FacturaScripts
- ✅ Maneja silenciosamente las excepciones de compatibilidad

Esto se logra mediante sobrescritura basada en reflexión sin modificar los archivos del Core.

## Cómo Funciona

### 1. Descubrimiento de Plugins
El sistema se conecta a un repositorio de GitHub para obtener la lista de plugins disponibles en formato JSON.

### 2. Verificación de Compatibilidad
Antes de la instalación, el plugin valida:
- Compatibilidad de versión de PHP
- Compatibilidad de versión de FacturaScripts
- Dependencias del plugin

### 3. Instalación Segura
- Descarga el plugin a una ubicación temporal
- Valida la integridad del ZIP
- Instala a través del sistema nativo de plugins de FacturaScripts
- Limpia los archivos temporales

### 4. Gestión de Actualizaciones
- Detecta actualizaciones disponibles
- Compara números de versión
- Instala actualizaciones sin problemas

## Referencia de API

### Clase PluginValidator

Utilidad de validación centralizada para verificar compatibilidad de plugins.

#### Métodos

**`validatePhpVersion(string $minPhp, string $pluginName = ''): bool`**
- Valida el requisito de versión de PHP
- Registra advertencias si no es compatible

**`validateFacturaScriptVersion(float $minVersion, float $currentVersion, string $pluginName = ''): bool`**
- Valida el requisito de versión de FacturaScripts
- Registra advertencias si no es compatible

**`validatePlugin(array $plugin, float $currentFsVersion): array`**
- Validación completa de plugin
- Devuelve: `['compatible' => bool, 'message' => string]`

**`checkInstallationStatus(string $pluginName, string $availableVersion, array $installedPlugins): array`**
- Verifica el estado de instalación
- Devuelve: `['installed' => bool, 'update_available' => bool]`

**`createInstalledPluginsMap(array $installedPlugins): array`**
- Crea un mapa indexado para búsquedas O(1)

### Clase PluginCompatibilityOverride

Maneja la sobrescritura automática de compatibilidad para plugins desde 2024 en adelante.

#### Métodos

**`applyOverride($plugin): bool`**
- Aplica la sobrescritura de compatibilidad a una instancia de plugin
- Usa Reflexión de PHP para acceso seguro a propiedades

## Traducciones

El plugin incluye traducciones completas para:
- **Español (es_ES):** Interfaz completa en español
- **Inglés (en_EN):** Interfaz completa en inglés

### Añadir Nuevas Traducciones

1. Copia `Translation/en_EN.json`
2. Renómbralo al código de idioma (ej. `fr_FR.json`)
3. Traduce todos los valores
4. Guarda en el directorio `Translation/`

Claves de traducción disponibles:
- `solwed-plugins`: Nombre del plugin principal
- `install`, `update`, `uninstall`: Botones de acción
- `installed`, `incompatible`: Estado del plugin
- `confirm-install-plugin`: Mensajes de confirmación
- Y muchas más...

## Desarrollo

### Optimizaciones de Código

El plugin ha sido optimizado y refactorizado con:

- **Eliminación de Código Duplicado:** Toda la lógica de validación centralizada en `PluginValidator`
- **Código Más Limpio:** Se eliminaron imports no utilizados y código muerto
- **Mejor Manejo de Errores:** Patrones try-finally para limpieza de recursos
- **Rendimiento Mejorado:** Búsqueda de plugins optimizada y caché de repositorio GitHub

### Arquitectura

```
Flujo de Solicitud:
┌─ privateCore()
├─ installPluginAction() / updatePluginAction()
├─ installPlugin()
│  ├─ PluginValidator::validatePlugin()
│  └─ downloadAndInstallPlugin()
│     ├─ Descarga de GitHub
│     ├─ Plugins::add()
│     └─ Plugins::enable()
└─ markInstalledPlugins()
   ├─ PluginValidator::validatePlugin()
   └─ PluginValidator::checkInstallationStatus()
```

## Solución de Problemas

### El Plugin No Se Instala
- **Solución:** Comprueba la compatibilidad de versión de PHP (8.0+)
- **Solución:** Verifica que la versión de FacturaScripts sea 2024.0+
- **Solución:** Comprueba los permisos de escritura en el directorio `/Plugins`

### Error de Compatibilidad
- **Solución:** El plugin maneja automáticamente la compatibilidad de versiones
- **Solución:** Si los problemas persisten, verifica la estructura JSON de GitHub

### Las Descargas Agotan el Tiempo
- **Solución:** El tiempo de espera predeterminado es de 45 segundos
- **Solución:** Comprueba la estabilidad de la conexión a Internet

## Seguridad

El plugin implementa:
- ✅ Control de acceso basado en permisos
- ✅ Validación de token de formulario
- ✅ Manejo seguro de archivos con directorios temporales
- ✅ Validación y desinfección de entrada
- ✅ Sobrescritura segura basada en reflexión

## Rendimiento

- **Caché:** Lista de plugins en caché (extensible)
- **Búsquedas Optimizadas:** Verificaciones de estado de instalación O(1)
- **Limpieza de Recursos:** Limpieza garantizada de archivos temporales con try-finally
- **Sobrecarga Mínima:** Verificaciones de permiso eficientes
- **Almacenamiento en Caché de GitHub:** Con expiración configurable (1 hora por defecto)

## Licencia

Este plugin es parte de FacturaScripts y está bajo licencia GNU Lesser General Public License v3 (LGPL-3.0).

Consulta el archivo [LICENSE](LICENSE) para detalles.

## Contribución

¡Las contribuciones son bienvenidas! Por favor:

1. Haz un fork del repositorio
2. Crea una rama de características
3. Realiza tus cambios
4. Envía un pull request

## Soporte

Para problemas, preguntas o solicitudes de características:
- Consulta la [Documentación](https://facturascripts.com)
- Visita el [Foro Comunitario](https://facturascripts.com)
- Abre un [Issue](https://github.com/yourusername/SolwedPlugins/issues)

## Registro de Cambios

### Versión 1.1 (26-10-2025)

**Características:**
- Portal Solwed totalmente integrado en Administrador > Plugins
- Interfaz unificada para gestionar todos los plugins
- Funcionalidad de actualización para refrescar la lista de plugins desde GitHub

**Mejoras:**
- Consolidación de SolwedPluginPortal en AdminPlugins
- Punto único de acceso para toda la gestión de plugins
- Mejor experiencia de usuario con pestañas integradas
- Compatible hacia atrás con noticia de deprecación

**Correcciones:**
- Se corrigió la carga de plugins en contexto AdminPlugins
- Se corrigió la acción de actualización con validación adecuada

### Versión 1.0 (26-10-2025)

**Características:**
- Versión inicial
- Instalación de plugins desde GitHub
- Detección automática de actualizaciones
- Integración con panel de administración
- Sistema de sobrescritura de compatibilidad
- Soporte multiidioma

**Mejoras:**
- Lógica de validación refactorizada
- Verificación centralizada de compatibilidad de plugins
- Sin duplicación de código
- Manejo mejorado de errores
- Documentación mejorada del código

**Correcciones:**
- Se corrigió la verificación de compatibilidad para FS 2024+
- Se corrigió la integración del panel de administración
- Se corrigió la integridad de las traducciones

## Créditos

Desarrollado para FacturaScripts por Solwed.

---

**Hecho con ❤️ para FacturaScripts**

Última actualización: 26 de Octubre de 2025
