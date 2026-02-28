---
name: plugin-cs
description: Ejecuta PHP_CodeSniffer y phpcbf sobre un plugin específico de FacturaScripts
---

Ejecuta análisis de estilo de código PSR-1/PSR-2 sobre el plugin indicado como argumento.

## Pasos

1. Verificar que existe `Plugins/{args}/`
2. Correr `vendor/bin/phpcs --standard=PSR2 Plugins/{args}/` y mostrar los errores
3. Si hay errores auto-corregibles, correr `vendor/bin/phpcbf --standard=PSR2 Plugins/{args}/`
4. Volver a correr phpcs para mostrar los errores restantes que requieren corrección manual
5. Si no quedan errores: confirmar que el plugin pasa el estándar PSR-1/PSR-2

## Notas

- El `phpcs.xml` del proyecto solo cubre `Core/` y `Test/`. Para plugins aplicar PSR2 directamente.
- Ignorar las reglas desactivadas en `phpcs.xml` (MultipleArguments, CloseBracketLine, etc.) — son intencionales.
- Si `vendor/` no existe, indicar que hay que correr `composer install` primero.
