#!/usr/bin/env python3
"""
import-demo-plugins.py
======================
Descarga los plugins de demo.erpsolwed.es, los limpia (ini + cabeceras PHP)
y crea una rama plugin/{Nombre} por cada uno en el repo local.

Uso:
    python3 scripts/import-demo-plugins.py [--dry-run] [--plugin NombrePlugin]

Opciones:
    --dry-run      Muestra lo que haría sin tocar git
    --plugin NAME  Procesa solo ese plugin
"""

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request
import zipfile
from pathlib import Path

# ── Configuración ─────────────────────────────────────────────────────────────

REPO_ROOT    = Path(__file__).resolve().parent.parent
DEMO_URL     = 'https://demo.erpsolwed.es/SolwedPluginExport'
GITHUB_REPO  = 'SolWed-es/facturascripts'
SOLWED_COPY  = 'Copyright (C) 2025 SolWed <dev@solwed.es>'
PLUGIN_LIST  = REPO_ROOT / 'plugin-list.json'

# Plugins propios — ya tienen rama y releases en GitHub, se saltan
OWN_PLUGINS = {'SolwedTheme', 'SolwedPlugins', 'Blog', 'ImportarFacturasEmail'}

# ── Git helpers ───────────────────────────────────────────────────────────────

def git(*args, check=True):
    result = subprocess.run(
        ['git'] + list(args),
        cwd=REPO_ROOT, capture_output=True, text=True
    )
    if check and result.returncode != 0:
        raise RuntimeError(f'git {" ".join(args)}\n{result.stderr.strip()}')
    return result.stdout.strip()

def current_branch():
    return git('rev-parse', '--abbrev-ref', 'HEAD')

def branch_exists(name):
    return git('rev-parse', '--verify', name, check=False) != ''

# ── Descarga y extracción ─────────────────────────────────────────────────────

def fetch_plugin_list():
    req = urllib.request.Request(
        f'{DEMO_URL}?action=list',
        headers={'User-Agent': 'FacturaScripts-SolWed/1.0'}
    )
    with urllib.request.urlopen(req, timeout=15) as r:
        return json.loads(r.read())['plugins']

def download_zip(name):
    url = f'{DEMO_URL}?action=download&plugin={name}'
    req = urllib.request.Request(url, headers={'User-Agent': 'FacturaScripts-SolWed/1.0'})
    tmp = tempfile.mktemp(suffix='.zip')
    with urllib.request.urlopen(req, timeout=30) as r:
        Path(tmp).write_bytes(r.read())
    return tmp

def extract_zip(zip_path):
    tmp_dir = tempfile.mkdtemp()
    with zipfile.ZipFile(zip_path) as z:
        z.extractall(tmp_dir)
    subdirs = [d for d in Path(tmp_dir).iterdir() if d.is_dir()]
    if not subdirs:
        raise RuntimeError(f'ZIP vacío o sin directorio raíz: {zip_path}')
    return subdirs[0]  # p.ej. /tmp/xyz/CRM/

# ── Limpieza ──────────────────────────────────────────────────────────────────

def clean_ini(ini_path, plugin_name):
    """Añade campo github y versión de mantenedor al ini."""
    content = ini_path.read_text(encoding='utf-8')
    # Eliminar github field previo si existe
    content = re.sub(r'\ngithub\s*=.*', '', content)
    content = content.rstrip()
    content += f"\ngithub = '{GITHUB_REPO}:{plugin_name}'\n"
    ini_path.write_text(content, encoding='utf-8')

def clean_php_headers(plugin_dir):
    """Añade línea de copyright SolWed tras la primera línea Copyright existente."""
    for php_file in sorted(plugin_dir.rglob('*.php')):
        try:
            content = php_file.read_text(encoding='utf-8', errors='replace')
            if 'Copyright (C)' in content and SOLWED_COPY not in content:
                content = re.sub(
                    r'( \* Copyright \(C\) .+\n)',
                    r'\1 * ' + SOLWED_COPY + ' — Maintained by SolWed\n',
                    content, count=1
                )
                php_file.write_text(content, encoding='utf-8')
        except Exception as e:
            print(f'    ⚠ No se pudo actualizar {php_file.name}: {e}')

# ── Creación de rama ──────────────────────────────────────────────────────────

def create_branch(plugin_name, plugin_dir, dry_run):
    branch    = f'plugin/{plugin_name}'
    dest      = REPO_ROOT / 'Plugins' / plugin_name
    gitignore = REPO_ROOT / '.gitignore'

    if dry_run:
        print(f'    [dry-run] git checkout -b {branch} origin/master')
        print(f'    [dry-run] copiar {plugin_dir} → Plugins/{plugin_name}/')
        print(f'    [dry-run] git add -f Plugins/{plugin_name}/')
        print(f'    [dry-run] git commit')
        return

    # Crear o reutilizar rama
    if branch_exists(branch):
        print(f'    Rama {branch} ya existe — actualizando')
        git('checkout', branch)
    else:
        git('checkout', '-b', branch, 'origin/master')

    # .gitignore: permitir este plugin
    gi_content = gitignore.read_text()
    ignore_line = f'!/Plugins/{plugin_name}/'
    if ignore_line not in gi_content:
        gitignore.write_text(gi_content.rstrip() + f'\n{ignore_line}\n')

    # Copiar archivos del plugin
    if dest.exists():
        shutil.rmtree(dest)
    shutil.copytree(plugin_dir, dest)

    # Commit (solo si hay cambios staged)
    git('add', '-f', f'Plugins/{plugin_name}')
    git('add', '.gitignore')
    has_changes = subprocess.run(
        ['git', 'diff', '--staged', '--quiet'],
        cwd=REPO_ROOT
    ).returncode != 0  # returncode 1 = hay cambios staged
    if has_changes:
        result = subprocess.run(
            ['git', 'commit', '-m', f'feat: add {plugin_name} (SolWed fork)'],
            cwd=REPO_ROOT, capture_output=True, text=True
        )
        if result.returncode != 0:
            raise RuntimeError(result.stderr or result.stdout)

# ── Actualizar plugin-list.json ───────────────────────────────────────────────

def update_plugin_list(processed_names, dry_run):
    data = json.loads(PLUGIN_LIST.read_text(encoding='utf-8'))

    for plugin in data['plugins']:
        if plugin['name'] in processed_names:
            plugin['source'] = 'github'
            plugin.pop('download_url', None)  # ahora usa GitHub Releases

    if dry_run:
        print(f'\n    [dry-run] Actualizaría plugin-list.json para {len(processed_names)} plugins')
        return

    PLUGIN_LIST.write_text(
        json.dumps(data, indent=2, ensure_ascii=False) + '\n',
        encoding='utf-8'
    )
    print(f'\n✓ plugin-list.json actualizado ({len(processed_names)} plugins → source: github)')

# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--plugin', help='Procesar solo este plugin')
    args = parser.parse_args()

    print('Obteniendo lista de plugins del demo...')
    all_plugins = fetch_plugin_list()
    plugins = [p for p in all_plugins if p['name'] not in OWN_PLUGINS]

    if args.plugin:
        plugins = [p for p in plugins if p['name'] == args.plugin]
        if not plugins:
            print(f'Plugin "{args.plugin}" no encontrado en el demo.')
            sys.exit(1)

    print(f'Plugins a procesar: {len(plugins)}')
    if args.dry_run:
        print('(modo dry-run — no se modificará git)\n')

    original_branch = current_branch()
    processed = []
    stashed = False

    if not args.dry_run:
        # Guardar cambios locales (solo archivos rastreados) para no perderlos al cambiar de rama.
        # No usamos --include-untracked porque puede fallar con archivos de otros propietarios.
        result = subprocess.run(
            ['git', 'stash', 'push', '-m', 'import-demo-plugins auto-stash'],
            cwd=REPO_ROOT, capture_output=True, text=True
        )
        stashed = result.returncode == 0 and 'No local changes to save' not in result.stdout + result.stderr
        if stashed:
            print('Cambios locales guardados en stash\n')

    try:
        for plugin in plugins:
            name    = plugin['name']
            version = plugin['version']
            print(f'\n── {name} v{version} ──')

            # 1. Descargar
            print('  Descargando...')
            zip_path = download_zip(name)

            # 2. Extraer
            plugin_dir = extract_zip(zip_path)
            os.unlink(zip_path)

            # 3. Limpiar ini
            ini = plugin_dir / 'facturascripts.ini'
            if ini.exists():
                clean_ini(ini, name)
                print('  ✓ facturascripts.ini actualizado')
            else:
                print('  ⚠ facturascripts.ini no encontrado')

            # 4. Limpiar cabeceras PHP
            clean_php_headers(plugin_dir)
            print('  ✓ Cabeceras PHP actualizadas')

            # 5. Crear rama
            print(f'  Creando rama plugin/{name}...')
            create_branch(name, plugin_dir, args.dry_run)
            print(f'  ✓ Rama plugin/{name} lista')

            # 6. Limpiar temp
            shutil.rmtree(plugin_dir.parent)

            processed.append(name)

    except KeyboardInterrupt:
        print('\n\nInterrumpido por el usuario.')
    finally:
        # Volver a la rama original
        if not args.dry_run:
            git('checkout', original_branch)
            print(f'\nVuelta a la rama original: {original_branch}')
            if stashed:
                git('stash', 'pop', check=False)
                print('Cambios locales restaurados (git stash pop)')

    # 7. Actualizar plugin-list.json
    if processed:
        update_plugin_list(set(processed), args.dry_run)

    # 8. Instrucciones finales
    print(f"""
══════════════════════════════════════════════════════
✓ {len(processed)} plugins procesados
══════════════════════════════════════════════════════

Próximos pasos:

1. Revisa los branches:
   git branch | grep plugin/

2. Push de todas las ramas:
   git push origin --all

3. Crea los tags para publicar los releases:
   (ejecuta scripts/tag-plugins.sh)

4. Haz push del plugin-list.json actualizado:
   git checkout solwed/production
   git add plugin-list.json
   git commit -m "feat: migrate demo plugins to GitHub releases"
   git push origin solwed/production
""")

if __name__ == '__main__':
    main()
