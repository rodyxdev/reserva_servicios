#!/bin/sh
# Instala los hooks de git versionados en scripts/git-hooks/ dentro de
# .git/hooks/, que git no versiona. Hay que correrlo una vez por clon.
#
#   sh scripts/setup-hooks.sh

set -e

raiz=$(git rev-parse --show-toplevel)
destino=$(git rev-parse --git-path hooks)

# Si alguien configuró core.hooksPath, git ignora .git/hooks y el hook no correría.
if [ -n "$(git config --get core.hooksPath || true)" ]; then
  echo "Aviso: core.hooksPath está configurado ($(git config --get core.hooksPath))."
  echo "Git no leerá .git/hooks mientras exista. Quítalo con: git config --unset core.hooksPath"
fi

mkdir -p "$destino"
for hook in "$raiz"/scripts/git-hooks/*; do
  nombre=$(basename "$hook")
  cp "$hook" "$destino/$nombre"
  chmod +x "$destino/$nombre"
  echo "Instalado: $destino/$nombre"
done
