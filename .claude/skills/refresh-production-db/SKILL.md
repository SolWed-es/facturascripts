---
name: refresh-production-db
description: Descarga dump de BD de produccion y lo importa en PostgreSQL local
disable-model-invocation: true
---

# Refresh Production DB

Descarga la base de datos de produccion (erpsolwed.es) e importa en local.

## Requisitos previos

- PostgreSQL 16 local corriendo: `brew services start postgresql@16`
- SSH acceso a solwed.es configurado
- Usuario `fs_user` con search_path `facturascripts, public`

## Pasos

1. Verificar que PostgreSQL local esta corriendo:
   ```bash
   export PATH="/opt/homebrew/opt/postgresql@16/bin:$PATH"
   pg_isready
   ```

2. Dump desde produccion via SSH + Docker:
   ```bash
   ssh solwed.es "docker exec postgres pg_dump -U fs_user -d facturascripts --no-owner --no-privileges --clean --if-exists" > /tmp/fs_production.sql
   ```

3. Importar en local:
   ```bash
   export PATH="/opt/homebrew/opt/postgresql@16/bin:$PATH"
   psql -d facturascripts -U fs_user -f /tmp/fs_production.sql
   ```

4. Resetear password del usuario admin para login local:
   ```bash
   NEW_HASH=$(php -r "echo password_hash('@Solwed8.', PASSWORD_DEFAULT);")
   export PATH="/opt/homebrew/opt/postgresql@16/bin:$PATH"
   psql -d facturascripts -U fs_user -c "SET search_path TO facturascripts; UPDATE users SET password='$NEW_HASH' WHERE nick='admin';"
   ```

5. Limpiar cache y IP bans:
   ```bash
   rm -rf MyFiles/Cache/* MyFiles/Cache/ip.list
   ```

6. Redeploy:
   ```bash
   rm -rf Dinamic
   curl -s http://localhost:8000/deploy
   ```

7. Verificar con Playwright:
   ```bash
   npx playwright test tests/e2e/theme.spec.ts
   ```

## Notas

- El dump es ~167MB — tarda unos segundos
- Produccion usa PostgreSQL en Docker (container `postgres`, user `fs_user`, BD `facturascripts`, schema `facturascripts`)
- El search_path esta configurado: `ALTER USER fs_user SET search_path TO facturascripts, public;`
