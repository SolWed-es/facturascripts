#!/usr/bin/env bash
# tag-plugins.sh
# Crea y sube los tags de todos los plugins procesados por import-demo-plugins.py
# GitHub Actions construirá el ZIP y creará el release automáticamente.
#
# Uso: bash scripts/tag-plugins.sh [NombrePlugin]
#   Sin argumentos: tagea todos los plugins con rama plugin/* (excepto los propios)
#   Con argumento:  tagea solo ese plugin

set -euo pipefail

REPO_ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
OWN_PLUGINS="SolwedTheme SolwedPlugins Blog ImportarFacturasEmail"

cd "$REPO_ROOT"

tag_plugin() {
    local name="$1"
    local branch="plugin/$name"

    # Comprobar que la rama existe
    if ! git rev-parse --verify "$branch" &>/dev/null; then
        echo "⚠  Rama $branch no existe — saltando"
        return
    fi

    # Leer versión del facturascripts.ini
    local version
    version=$(git show "$branch:Plugins/$name/facturascripts.ini" 2>/dev/null \
        | grep '^version' | head -1 | sed "s/version[[:space:]]*=[[:space:]]*//;s/'//g;s/\"//g;s/[[:space:]]//g")

    if [[ -z "$version" ]]; then
        echo "⚠  No se pudo leer la versión de $name — saltando"
        return
    fi

    local tag="${name}-v${version}"

    if git rev-parse --verify "$tag" &>/dev/null; then
        echo "ℹ  Tag $tag ya existe — saltando"
        return
    fi

    echo "→ Creando tag $tag"
    git tag "$tag" "$branch"
}

# ── Seleccionar plugins ───────────────────────────────────────────────────────

if [[ "${1:-}" != "" ]]; then
    # Un solo plugin
    tag_plugin "$1"
else
    # Todos los plugin/* excepto los propios
    while IFS= read -r branch; do
        name="${branch#plugin/}"
        if echo "$OWN_PLUGINS" | grep -qw "$name"; then
            continue
        fi
        tag_plugin "$name"
    done < <(git branch | grep '^\s*plugin/' | sed 's/\s//g')
fi

# ── Push de tags ──────────────────────────────────────────────────────────────

echo ""
read -r -p "¿Subir todos los tags nuevos a origin? [s/N] " confirm
if [[ "$confirm" =~ ^[sS]$ ]]; then
    git push origin --tags
    echo "✓ Tags pusheados — GitHub Actions construirá los ZIPs"
else
    echo "Tags creados localmente. Para subir manualmente:"
    echo "  git push origin --tags"
fi
