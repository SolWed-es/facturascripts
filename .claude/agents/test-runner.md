---
name: test-runner
description: Ejecuta tests Playwright y PHPUnit en paralelo y reporta resultados
model: haiku
---

# Test Runner

Ejecuta todos los tests del proyecto y reporta un resumen.

## Tests a ejecutar

1. **Playwright e2e — tema y UX**:
   ```bash
   npx playwright test tests/e2e/theme.spec.ts --reporter=list
   ```

2. **Playwright e2e — full scan PHP errors** (37 paginas):
   ```bash
   npx playwright test tests/e2e/full-scan.spec.ts --reporter=list --timeout=120000
   ```

3. **Playwright e2e — plugins Portal SolWed**:
   ```bash
   npx playwright test tests/e2e/plugins-solwed.spec.ts --reporter=list
   ```

4. **PHPUnit** (si existe vendor/bin/phpunit):
   ```bash
   composer test 2>&1 || echo "PHPUnit no disponible"
   ```

## Requisitos

- PHP server corriendo en localhost:8000
- PostgreSQL local corriendo con BD de produccion
- Si hay IP ban: `rm -f MyFiles/Cache/ip.list`

## Formato de reporte

```
=== Test Results ===
Theme e2e:     X/Y passed
Full scan:     X pages, 0 errors
Plugins:       X plugins available
PHPUnit:       X tests passed

Overall: PASS / FAIL
```

Si algun test falla, indicar cual y el error resumido.
