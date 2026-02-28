---
name: new-plugin
description: Genera el scaffold completo de un nuevo plugin FacturaScripts en Plugins/{nombre}/
---

Crea la estructura base de un nuevo plugin FacturaScripts usando el nombre pasado como argumento.

## Estructura a generar

```
Plugins/{nombre}/
├── facturascripts.ini
├── Init.php
├── Controller/
├── Lib/
├── Model/
├── Table/
├── Translation/
│   └── es_ES.json
└── XMLView/
```

## Contenido de los archivos base

**facturascripts.ini** — usa Blog como referencia para el formato, ajustando nombre y descripción.

**Init.php** — clase que extiende `FacturaScripts\Core\Base\InitClass` con métodos `init()` y `uninstall()` vacíos.

**Translation/es_ES.json** — objeto JSON vacío `{}` de momento.

## Notas importantes

- `/Plugins/` está en `.gitignore` del core → usar `git add -f Plugins/{nombre}/` para trackear los archivos
- Añadir `!/Plugins/{nombre}/` al `.gitignore` de la rama actual si no está ya
- Seguir el patrón exacto de `Plugins/Blog/` como referencia de estructura y estilo PHP
