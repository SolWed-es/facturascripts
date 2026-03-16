---
name: deploy-production
description: Crea tag, release en GitHub y prepara deploy a produccion desde solwed/production
disable-model-invocation: true
---

# Deploy Production

Flujo completo de release para SolWed FacturaScripts.

## Pasos

1. Verificar que estamos en la rama `solwed/production`
2. Ejecutar tests: `composer test` y `npx playwright test tests/e2e/theme.spec.ts`
3. Si los tests pasan, pedir version al usuario (formato: `2025.XX`)
4. Crear tag `vVERSION` (ej: `v2025.94`)
5. Push de la rama y el tag: `git push origin solwed/production --tags`
6. Crear release en GitHub con `gh release create vVERSION --target solwed/production --title "SOLWED.ES vVERSION" --generate-notes`
7. Crear el ZIP del core para el asset del release:
   ```bash
   git archive --format=zip --prefix=facturascripts/ HEAD -o /tmp/facturascripts-vVERSION.zip
   gh release upload vVERSION /tmp/facturascripts-vVERSION.zip
   ```
8. Confirmar que el release es visible en GitHub

## Importante

- NUNCA hacer release desde `master` — solo desde `solwed/production`
- Verificar que no haya cambios sin commitear antes de empezar
- El ZIP del asset es necesario para que el Updater pueda descargar la actualizacion
- Mind detectara el release automaticamente en `mind.solwed.es/api/fs/builds`
