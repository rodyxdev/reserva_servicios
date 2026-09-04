#!/usr/bin/env bash
#
# =====================================================================
#  DESPLIEGUE POR FTP (InfinityFree y hosting compartido similar)
# =====================================================================
#
#  Sincroniza el proyecto hacia un servidor FTP con lftp.
#
#  USO
#  ---
#      export DEPLOY_FTP_HOST="ftpupload.net"
#      export DEPLOY_FTP_USER="if0_XXXXXXXX"
#      export DEPLOY_FTP_PASS="tu_password_ftp"
#      export DEPLOY_FTP_PATH="/htdocs"
#
#      ./scripts/deploy.sh --dry-run     # ver que se subiria
#      ./scripts/deploy.sh               # subir de verdad
#
#  LAS CREDENCIALES NO ESTAN EN NINGUN ARCHIVO
#  -------------------------------------------
#  Se leen de variables de entorno, no del .env ni de un deploy.conf. Un
#  archivo con la contrasena del FTP acaba, tarde o temprano, en un commit.
#  Con variables de entorno el descuido tiene que ser deliberado.
#
#  Para no teclearlas cada vez, ponlas en un archivo FUERA del repositorio
#  (por ejemplo ~/.reservas-deploy.env, con chmod 600) y cargalo antes:
#
#      set -a; source ~/.reservas-deploy.env; set +a
#      ./scripts/deploy.sh
#
#  QUE SE SUBE Y QUE NO
#  --------------------
#  En InfinityFree el DocumentRoot es /htdocs y no se puede mover, asi que
#  la estructura del proyecto se reparte:
#
#      /htdocs/            <- contenido de public/ (index.php, assets, .htaccess)
#      /htdocs/../app/     <- src/, config/, vendor/  (fuera del alcance web)
#
#  Si tu hosting no permite escribir fuera de htdocs, pon DEPLOY_APP_PATH
#  en "/htdocs/app" y confia en el .htaccess de la raiz, que ya bloquea
#  esas carpetas por HTTP. Es peor: prefiere siempre sacarlas del docroot.
#
#  NO se sube: .env, .git, tests, database, docker, node_modules, ni los
#  archivos de storage/ (salvo la estructura de carpetas vacia).
# =====================================================================

set -euo pipefail

# ---------------------------------------------------------------------
#  Comprobaciones previas
# ---------------------------------------------------------------------
if ! command -v lftp >/dev/null 2>&1; then
    echo "ERROR: lftp no esta instalado." >&2
    echo "  Debian/Ubuntu : sudo apt install lftp" >&2
    echo "  macOS         : brew install lftp" >&2
    echo "  Windows       : usalo desde WSL o Git Bash con lftp instalado" >&2
    exit 1
fi

faltan=()
for var in DEPLOY_FTP_HOST DEPLOY_FTP_USER DEPLOY_FTP_PASS; do
    if [ -z "${!var:-}" ]; then
        faltan+=("$var")
    fi
done

if [ ${#faltan[@]} -gt 0 ]; then
    echo "ERROR: faltan variables de entorno: ${faltan[*]}" >&2
    echo "" >&2
    echo "  export DEPLOY_FTP_HOST=\"ftpupload.net\"" >&2
    echo "  export DEPLOY_FTP_USER=\"if0_XXXXXXXX\"" >&2
    echo "  export DEPLOY_FTP_PASS=\"...\"" >&2
    exit 1
fi

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

PUBLIC_PATH="${DEPLOY_FTP_PATH:-/htdocs}"
APP_PATH="${DEPLOY_APP_PATH:-${PUBLIC_PATH}/../app}"

DRY_RUN=""
VERBOSE="-v"

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN="--dry-run" ;;
        --quiet)   VERBOSE="" ;;
        --help|-h)
            sed -n '3,45p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Opcion desconocida: $arg" >&2
            exit 1
            ;;
    esac
done

# ---------------------------------------------------------------------
#  Avisos de seguridad antes de tocar nada
# ---------------------------------------------------------------------
if [ ! -d vendor ]; then
    echo "ERROR: no existe vendor/. Ejecuta primero:" >&2
    echo "  composer install --no-dev --optimize-autoloader" >&2
    exit 1
fi

# vendor/ con dependencias de desarrollo pesa mucho mas y sube phpunit al
# servidor sin necesidad. Se detecta y se avisa.
if [ -d vendor/phpunit ]; then
    echo "AVISO: vendor/ incluye dependencias de desarrollo (phpunit)."
    echo "       Para produccion conviene regenerarlo:"
    echo "         composer install --no-dev --optimize-autoloader"
    echo ""
    read -r -p "Continuar de todas formas? [s/N] " respuesta
    [[ "$respuesta" =~ ^[sS]$ ]] || exit 1
fi

echo "=================================================="
echo " Despliegue"
echo "=================================================="
echo "  Origen   : $RAIZ"
echo "  Servidor : ${DEPLOY_FTP_USER}@${DEPLOY_FTP_HOST}"
echo "  Publico  : $PUBLIC_PATH        <- contenido de public/"
echo "  App      : $APP_PATH   <- src/, config/, vendor/"
[ -n "$DRY_RUN" ] && echo "  MODO     : simulacion, no se sube nada"
echo ""

# ---------------------------------------------------------------------
#  Exclusiones
# ---------------------------------------------------------------------
#  El .env NUNCA se sube: en el servidor se crea a mano una sola vez, con
#  las credenciales de produccion. Subirlo desde local sobreescribiria la
#  configuracion del servidor con la de desarrollo, apuntando la app a una
#  base de datos que no existe alli.
EXCLUIR_COMUNES=(
    --exclude-glob '.git*'
    --exclude-glob '.env*'
    --exclude-glob '*.md'
    --exclude-glob '*.sql'
    --exclude-glob '.DS_Store'
    --exclude-glob 'Thumbs.db'
    --exclude-glob '*.log'
)

# ---------------------------------------------------------------------
#  Subida
# ---------------------------------------------------------------------
#  set ssl:verify-certificate no  -> muchos FTP compartidos usan
#  certificados autofirmados. Es FTP plano: la contrasena viaja en claro
#  de todas formas, por eso conviene una exclusiva del despliegue y no
#  reutilizar ninguna otra.
#
#  --only-newer compara fechas y sube solo lo cambiado: en una conexion
#  domestica, subir vendor/ entero cada vez son varios minutos.
#
#  --delete quita del servidor lo que ya no existe en local. Se aplica
#  SOLO a src/ y vendor/, nunca a htdocs: ahi puede haber archivos que
#  el hosting pone por su cuenta y borrarlos rompe el sitio.

lftp -c "
set ftp:ssl-allow no;
set ssl:verify-certificate no;
set net:timeout 20;
set net:max-retries 3;
set net:reconnect-interval-base 5;
set xfer:clobber on;
set cmd:fail-exit yes;

open -u '${DEPLOY_FTP_USER}','${DEPLOY_FTP_PASS}' '${DEPLOY_FTP_HOST}';

echo '--- public/ -> ${PUBLIC_PATH} ---';
mirror --reverse --only-newer --parallel=3 $VERBOSE $DRY_RUN \
    ${EXCLUIR_COMUNES[*]} \
    './public/' '${PUBLIC_PATH}/';

echo '--- src/ -> ${APP_PATH}/src ---';
mirror --reverse --only-newer --delete --parallel=3 $VERBOSE $DRY_RUN \
    ${EXCLUIR_COMUNES[*]} \
    './src/' '${APP_PATH}/src/';

echo '--- config/ -> ${APP_PATH}/config ---';
mirror --reverse --only-newer --delete $VERBOSE $DRY_RUN \
    ${EXCLUIR_COMUNES[*]} \
    './config/' '${APP_PATH}/config/';

echo '--- vendor/ -> ${APP_PATH}/vendor ---';
mirror --reverse --only-newer --delete --parallel=3 $VERBOSE $DRY_RUN \
    ${EXCLUIR_COMUNES[*]} \
    --exclude-glob 'phpunit*' \
    --exclude-glob '*/tests/*' \
    --exclude-glob '*/docs/*' \
    './vendor/' '${APP_PATH}/vendor/';

echo '--- scripts/ -> ${APP_PATH}/scripts ---';
mirror --reverse --only-newer $VERBOSE $DRY_RUN \
    ${EXCLUIR_COMUNES[*]} \
    --exclude-glob 'deploy.sh' \
    './scripts/' '${APP_PATH}/scripts/';

bye;
"

echo ""
echo "=================================================="
if [ -n "$DRY_RUN" ]; then
    echo " Simulacion terminada. No se subio nada."
else
    echo " Despliegue terminado."
    echo ""
    echo " Pendiente la primera vez:"
    echo "   1. Crear ${APP_PATH}/.env en el servidor con las credenciales"
    echo "      de produccion (base de datos y SMTP). NO se sube desde aqui."
    echo "   2. Importar database/schema.sql por phpMyAdmin, comentando las"
    echo "      lineas CREATE DATABASE y USE."
    echo "   3. Ajustar la ruta de vendor/autoload.php en public/index.php"
    echo "      si moviste la carpeta app/ a otro sitio."
    echo "   4. Dar permisos de escritura a ${APP_PATH}/storage/logs y"
    echo "      ${APP_PATH}/storage/cache."
    echo "   5. Programar el cron:"
    echo "      php ${APP_PATH}/scripts/send-reminders.php"
fi
echo "=================================================="
