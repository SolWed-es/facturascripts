---
name: sync-upstream
description: Sincroniza master con NeoRazorX upstream y rebasa solwed/production
disable-model-invocation: true
---

# Sync Upstream

Sincroniza el fork con el repositorio original de NeoRazorX/facturascripts.

## Pasos

1. Verificar que no hay cambios sin commitear
2. Sincronizar master:
   ```bash
   git checkout master
   git fetch upstream
   git merge upstream/master
   git push origin master
   ```
3. Rebasar solwed/production sobre master:
   ```bash
   git checkout solwed/production
   git rebase master
   ```
4. Si hay conflictos, resolverlos manualmente priorizando los cambios de SolWed
5. Ejecutar tests: `composer test` y `npx playwright test tests/e2e/theme.spec.ts`
6. Si todo pasa, push: `git push origin solwed/production`

## Importante

- `master` es espejo de upstream — NUNCA hacer cambios propios ahi
- Todos los cambios de SolWed van en `solwed/production`
- Si upstream toco archivos que tambien modificamos (especialmente en Core/View/ y Core/Assets/CSS/), revisar los conflictos con cuidado
