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
#      /htdocs/       <- contenido de public/ (index.php, assets, .htaccess)
#      /htdocs/app/   <- src/, config/, vendor/, scripts/, .env
#
#  DENTRO del DocumentRoot, y no fuera, aunque fuera sea lo correcto en un
#  servidor propio. El motivo no es comodidad: el open_basedir de estos
#  hosting encierra a PHP en el DocumentRoot. Medido en InfinityFree:
#
#      open_basedir: /php_sessions:/home/uploads:/tmp:/var/www/errors:
#                    /home/vol5_2/infinityfree.com/if0_XXXXXXXX/htdocs
#
#  Con app/ fuera, el FTP la sube y la lista sin problema, pero PHP no
#  puede ni mirarla: file_exists() devuelve false sobre archivos que
#  existen. El sintoma es identico al de una subida incompleta, y por ahi
#  se van horas buscando en el sitio equivocado.
#
#  El precio es que .env queda en una carpeta alcanzable por HTTP. Lo
#  unico que lo protege es deploy/app.htaccess, que este script sube
#  automaticamente y que conviene verificar despues de cada despliegue:
#
#      curl -sI https://TU-DOMINIO/app/.env      # debe dar 403
#
#  Si tu hosting SI deja leer fuera del DocumentRoot (VPS, cPanel con
#  open_basedir laxo), saca la carpeta y gana esa capa de vuelta:
#
#      export DEPLOY_APP_PATH="/app"
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

# curl no es opcional: es el plan B cuando lftp falla. Contra InfinityFree
# se midio que "mirror" anuncia "Transferring file" de los 52 archivos de
# src/ y solo persisten 5, mientras que subirlos de uno en uno con curl
# deja los 52. No se ha identificado la causa en el servidor, asi que el
# script no intenta adivinarla: detecta el desajuste contando y cambia de
# herramienta.
if ! command -v curl >/dev/null 2>&1; then
    echo "ERROR: curl no esta instalado (se usa como respaldo de lftp)." >&2
    echo "  Debian/Ubuntu : sudo apt install curl" >&2
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
# Por defecto DENTRO del DocumentRoot: es lo unico que funciona cuando
# open_basedir encierra a PHP ahi. Ver la explicacion de la cabecera.
APP_PATH="${DEPLOY_APP_PATH:-${PUBLIC_PATH}/app}"

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

# ---------------------------------------------------------------------
#  Normalizacion de rutas
# ---------------------------------------------------------------------
#  APP_PATH se resuelve AQUI, en local, en vez de mandarle al servidor una
#  ruta con "..". Muchos servidores FTP no normalizan "/htdocs/../app": o
#  fallan al crear la ruta, o la interpretan como "/htdocs/app" y dejan la
#  aplicacion dentro del DocumentRoot, que es justo lo que se quiere evitar.
#
#  Es puro manejo de cadenas: se colapsa cada "algo/.." y se limpian las
#  barras repetidas. El bucle cubre rutas con varios ".." encadenados.
#  Las barras repetidas se colapsan PRIMERO: con "//" de por medio, el
#  patron del ".." no encaja y la ruta quedaria a medio resolver.
APP_PATH="$(printf '%s' "$APP_PATH" | sed 's#//*#/#g')"

while [[ "$APP_PATH" == */../* || "$APP_PATH" == */.. ]]; do
    APP_PATH="$(printf '%s' "$APP_PATH" | sed 's#/[^/]*/\.\.#/#')"
done

APP_PATH="$(printf '%s' "$APP_PATH" | sed 's#//*#/#g; s#/$##')"
[ -z "$APP_PATH" ] && APP_PATH="/app"

echo "=================================================="
echo " Despliegue"
echo "=================================================="
echo "  Origen   : $RAIZ"
echo "  Servidor : ${DEPLOY_FTP_USER}@${DEPLOY_FTP_HOST}"
echo "  Publico  : $PUBLIC_PATH        <- contenido de public/"
echo "  App      : $APP_PATH   <- src/, config/, vendor/, scripts/"
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
#  Ajustes comunes de lftp
# ---------------------------------------------------------------------
#  Dos de estas lineas son la razon de que el script antes se cortara:
#
#  set cmd:fail-exit no
#      ANTES estaba en "yes". Le decia a lftp que abortara su script
#      entero al primer comando con codigo distinto de cero. Y "mirror"
#      devuelve distinto de cero si acumulo CUALQUIER error, incluido un
#      "SITE CHMOD" rechazado. En InfinityFree el chmod SIEMPRE falla
#      (responde 550), asi que la primera seccion mataba a las cuatro
#      siguientes. Como ademas todas iban en una sola invocacion de lftp
#      y el script corre con "set -e", bash remataba la ejecucion y ni
#      siquiera se imprimia un resumen. El sintoma era desconcertante:
#      cada corrida avanzaba exactamente una seccion mas, porque
#      --only-newer saltaba lo ya subido.
#
#  set ftp:use-site-chmod no
#      Ataca la causa en vez del sintoma: lftp deja de intentar el chmod.
#      En un hosting compartido los permisos los fija el servidor y no se
#      pueden cambiar por FTP, asi que ese comando nunca iba a servir
#      para nada. Sin el, no hay errores que ignorar.
LFTP_AJUSTES="
set ftp:ssl-allow no;
set ssl:verify-certificate no;
set net:timeout 20;
set net:max-retries 3;
set net:reconnect-interval-base 5;
set xfer:clobber on;
set cmd:fail-exit no;
set ftp:use-site-chmod no;
set mirror:set-permissions no;
"

# ---------------------------------------------------------------------
#  Logs
# ---------------------------------------------------------------------
#  Los logs de lftp se guardan aqui y SOLO se borran si todo fue bien.
#  Antes se borraban siempre al salir, justo cuando hacian falta: el
#  script decia "vendor INCOMPLETO" y el detalle del porque ya no existia.
LOGS="$(mktemp -d)"
CONSERVAR_LOGS=0
trap '[ "$CONSERVAR_LOGS" = "0" ] && rm -rf "$LOGS" || true' EXIT

# Reintentos por seccion cuando queda incompleta. Una conexion lenta o
# inestable corta transferencias a medias; reintentar es barato porque
# lftp solo vuelve a subir lo que falta.
MAX_INTENTOS="${DEPLOY_REINTENTOS:-3}"

# Resultados de cada seccion, para el resumen final.
SECCIONES=()
RESULTADOS=()
DETALLES=()

# ---------------------------------------------------------------------
#  contar_remoto <ruta>  ->  numero de archivos que hay en el servidor
# ---------------------------------------------------------------------
#  El segundo argumento excluye rutas del recuento. Hace falta desde que
#  app/ vive DENTRO del DocumentRoot: un "find /htdocs" recorre tambien
#  /htdocs/app y devuelve los 380 archivos de la aplicacion como si
#  fueran de public/. Sin excluirlos, el resumen dice "public 6 local /
#  385 servidor", que no significa nada.
contar_remoto() {
    local excluir="${2:-}"
    set +e
    lftp -c "
${LFTP_AJUSTES}
open -u '${DEPLOY_FTP_USER}','${DEPLOY_FTP_PASS}' '${DEPLOY_FTP_HOST}';
find '$1';
bye;
" 2>/dev/null \
        | grep -v '/$' \
        | { if [ -n "$excluir" ]; then grep -vE "$excluir" || true; else cat; fi; } \
        | grep -c .
    set -e
}

# ---------------------------------------------------------------------
#  subir_seccion <nombre> <origen local> <destino remoto> [extras...]
# ---------------------------------------------------------------------
#  Cada seccion se sube en su PROPIA invocacion de lftp. Es la diferencia
#  clave: si una falla, las demas se intentan igualmente. Antes iban las
#  cinco en un solo lftp y un tropiezo se llevaba por delante el resto.
# ---------------------------------------------------------------------
subir_seccion() {
    local nombre="$1" origen="$2" destino="$3" esperados="$4"
    shift 4
    local extras=("$@")

    echo "--- ${nombre}:  ${origen}  ->  ${destino}  (${esperados} archivos) ---"

    local intento=0 codigo=0 ruido=0 reales=0 remotos=-1 log=""

    while :; do
        intento=$((intento + 1))
        log="$LOGS/${nombre}-intento${intento}.log"

        [ "$intento" -gt 1 ] && echo "    reintento ${intento}/${MAX_INTENTOS}..."

        # El PRIMER intento va con lftp: es incremental (--only-newer) y
        # no resube lo que no ha cambiado. Del SEGUNDO en adelante se
        # cambia a curl, porque repetir con la misma herramienta que
        # acaba de dejar la seccion corta solo repite el resultado: se
        # comprobo tres veces seguidas sobre src/, siempre 5 de 52.
        if [ "$intento" -gt 1 ] && [ -z "$DRY_RUN" ]; then
            echo "    cambiando a curl (lftp dejo la seccion incompleta)..."
            set +e
            subir_con_curl "$origen" "$destino" "$EXTRA_GREP" > "$log" 2>&1
            codigo=$?
            set -e
            [ -n "$VERBOSE" ] && sed 's/^/    /' "$log"
        else
        # set +e alrededor: sin esto, "set -e" mataria el script en la
        # primera seccion que devuelva algo distinto de cero, que es
        # justamente el fallo que se esta corrigiendo.
        set +e
        lftp -c "
${LFTP_AJUSTES}
open -u '${DEPLOY_FTP_USER}','${DEPLOY_FTP_PASS}' '${DEPLOY_FTP_HOST}';
mirror --reverse --only-newer --no-perms $VERBOSE $DRY_RUN \
    ${EXCLUIR_COMUNES[*]} ${extras[*]} \
    '${origen}' '${destino}/';
bye;
" > "$log" 2>&1
        codigo=$?
        set -e

        [ -n "$VERBOSE" ] && sed 's/^/    /' "$log"
        fi

        # -------------------------------------------------------------
        #  Separar el ruido de permisos de los fallos de verdad
        # -------------------------------------------------------------
        #  Un chmod rechazado no significa que el archivo no se subiera:
        #  el contenido esta, solo con los permisos que el servidor
        #  decide. Se cuentan aparte para no confundirlo con un problema.
        ruido=$(grep -ciE 'chmod|SITE CHMOD' "$log" || true)
        reales=$(grep -viE 'chmod' "$log" \
                 | grep -ciE 'Transfer failed|Access failed|Login failed|Fatal error|No such file|Permission denied|not connected' \
                 || true)

        # En simulacion no hay nada que contar en el servidor.
        [ -n "$DRY_RUN" ] && break

        # -------------------------------------------------------------
        #  El criterio para reintentar es el CONTEO REAL, no el log
        # -------------------------------------------------------------
        #  Interpretar los mensajes de lftp es fragil: cambian entre
        #  versiones y una transferencia cortada por un timeout puede no
        #  dejar ninguna linea reconocible. Preguntarle al servidor
        #  cuantos archivos tiene responde a la unica pregunta que
        #  importa, y es la que destapo que src subia 5 de 52.
        remotos=$(contar_remoto "$destino" "$EXCLUIR_REMOTO")

        if [ "$remotos" -ge "$esperados" ]; then
            break
        fi

        echo "    incompleto: ${remotos} de ${esperados} en el servidor"

        if [ "$intento" -ge "$MAX_INTENTOS" ]; then
            break
        fi

        # Pausa breve: si el corte fue por saturacion del servidor,
        # reconectar en el acto suele fallar otra vez.
        sleep 3
    done

    SECCIONES+=("$nombre")

    if [ -z "$DRY_RUN" ] && [ "$remotos" -ge 0 ] && [ "$remotos" -lt "$esperados" ]; then
        RESULTADOS+=("INCOMPLETO")
        DETALLES+=("${remotos}/${esperados} tras ${intento} intento(s)")
        CONSERVAR_LOGS=1
    elif [ "$reales" -gt 0 ]; then
        RESULTADOS+=("FALLO")
        DETALLES+=("$reales error(es) de transferencia")
        CONSERVAR_LOGS=1
    elif [ "$intento" -gt 1 ]; then
        # Que hicieran falta reintentos es mas relevante que el ruido de
        # permisos: avisa de que la conexion se esta cortando.
        RESULTADOS+=("OK")
        DETALLES+=("completo tras ${intento} intentos")
    elif [ "$codigo" -ne 0 ] && [ "$ruido" -gt 0 ]; then
        RESULTADOS+=("OK")
        DETALLES+=("$ruido aviso(s) de permisos, ignorados")
    elif [ "$codigo" -ne 0 ]; then
        RESULTADOS+=("REVISAR")
        DETALLES+=("lftp devolvio $codigo sin errores reconocibles")
        CONSERVAR_LOGS=1
    else
        RESULTADOS+=("OK")
        DETALLES+=("sin incidencias")
    fi
}

# ---------------------------------------------------------------------
#  Las cinco secciones
# ---------------------------------------------------------------------
#  Se quito --delete de src/, config/ y vendor/. Con una subida que se
#  corta a medias, --delete puede borrar en el servidor archivos que
#  todavia no se han vuelto a subir y dejar el despliegue peor que antes.
#  Para una limpieza a fondo, borra la carpeta remota a mano y vuelve a
#  desplegar.
#  Cuantos archivos deberia haber en el servidor por seccion. Se calcula
#  aplicando los mismos filtros que usa el mirror, para que la comparacion
#  sea justa y no marque como incompleto algo que se excluyo a proposito.
#  El filtro comun DEBE reflejar EXCLUIR_COMUNES. Cuando no lo hacia, el
#  conteo local de vendor incluia 23 README.md/LICENSE.md/.gitignore que
#  el mirror nunca sube, y la seccion salia "321 de 344" para siempre:
#  un despliegue perfecto marcado como incompleto en cada ejecucion.
FILTRO_COMUN='\.(md|sql|log)$|/\.git|/\.env|/\.DS_Store$|/Thumbs\.db$'

listar_local() {
    # listar_local <ruta> [patron extra a excluir]  -> un archivo por linea
    local ruta="$1" extra="${2:-}"
    if [ -n "$extra" ]; then
        find "$ruta" -type f | grep -vE "$FILTRO_COMUN" | grep -vE "$extra" || true
    else
        find "$ruta" -type f | grep -vE "$FILTRO_COMUN" || true
    fi
}

contar_local() {
    listar_local "$@" | grep -c . || true
}

# ---------------------------------------------------------------------
#  subir_con_curl <origen local> <destino remoto> [patron extra]
# ---------------------------------------------------------------------
#  Sube archivo a archivo. Es mas lento que un mirror porque abre una
#  conexion por archivo y no sabe de --only-newer: lo resube todo. A
#  cambio funciona donde el mirror no, que es lo unico que importa cuando
#  la alternativa es un despliegue a medias.
# ---------------------------------------------------------------------
subir_con_curl() {
    local origen="$1" destino="$2" extra="${3:-}"
    local subidos=0 fallidos=0 archivo relativo

    while IFS= read -r archivo; do
        [ -z "$archivo" ] && continue
        relativo="${archivo#"$origen"}"
        relativo="${relativo#./}"

        if curl -sS --ftp-create-dirs -T "$archivo" \
                --user "${DEPLOY_FTP_USER}:${DEPLOY_FTP_PASS}" \
                "ftp://${DEPLOY_FTP_HOST}${destino}/${relativo}" >/dev/null 2>&1; then
            subidos=$((subidos + 1))
        else
            fallidos=$((fallidos + 1))
            echo "curl FALLO: ${relativo}"
        fi
    done < <(listar_local "$origen" "$extra")

    echo "curl: ${subidos} subidos, ${fallidos} fallidos"
    [ "$fallidos" -eq 0 ]
}

# ---------------------------------------------------------------------
#  subir_archivo <archivo local> <ruta remota completa>
# ---------------------------------------------------------------------
subir_archivo() {
    curl -sS --ftp-create-dirs -T "$1" \
         --user "${DEPLOY_FTP_USER}:${DEPLOY_FTP_PASS}" \
         "ftp://${DEPLOY_FTP_HOST}$2" >/dev/null 2>&1
}

N_PUBLIC=$(contar_local ./public)
N_SRC=$(contar_local ./src)
N_CONFIG=$(contar_local ./config)
N_VENDOR=$(contar_local ./vendor '/tests/|/docs/|phpunit')
N_SCRIPTS=$(( $(contar_local ./scripts) - 1 ))   # menos deploy.sh

VERIF_NOMBRES=("public" "src" "config" "vendor" "scripts")
VERIF_LOCALES=("$N_PUBLIC" "$N_SRC" "$N_CONFIG" "$N_VENDOR" "$N_SCRIPTS")
VERIF_RUTAS=("$PUBLIC_PATH" "${APP_PATH}/src" "${APP_PATH}/config" "${APP_PATH}/vendor" "${APP_PATH}/scripts")
# Paralelo a VERIF_RUTAS: que descontar del recuento remoto de cada una.
VERIF_EXCLUIR=("^${APP_PATH}/" '' '' '' '')

# EXTRA_GREP traduce para curl las mismas exclusiones que lftp recibe como
# --exclude-glob. Van en paralelo a proposito: si se cambia una hay que
# cambiar la otra, o el respaldo con curl subira lo que el mirror excluye.
# public/ es el unico caso con exclusion de recuento: su carpeta remota
# contiene a app/, que se cuenta aparte.
EXTRA_GREP=''
EXCLUIR_REMOTO="^${APP_PATH}/"
subir_seccion "public"  './public/'  "${PUBLIC_PATH}"        "$N_PUBLIC"
EXCLUIR_REMOTO=''

EXTRA_GREP=''
subir_seccion "src"     './src/'     "${APP_PATH}/src"       "$N_SRC"

EXTRA_GREP=''
subir_seccion "config"  './config/'  "${APP_PATH}/config"    "$N_CONFIG"

EXTRA_GREP='/tests/|/docs/|phpunit'
subir_seccion "vendor"  './vendor/'  "${APP_PATH}/vendor"    "$N_VENDOR" \
    --exclude-glob 'phpunit*' --exclude-glob '*/tests/*' --exclude-glob '*/docs/*'

EXTRA_GREP='deploy\.sh'
subir_seccion "scripts" './scripts/' "${APP_PATH}/scripts"   "$N_SCRIPTS" \
    --exclude-glob 'deploy.sh'

# ---------------------------------------------------------------------
#  Lo que el mirror no cubre
# ---------------------------------------------------------------------
#  Estas tres cosas no salen de sincronizar carpetas y sin ellas el
#  despliegue arranca roto. Se hacen SIEMPRE, tambien cuando las secciones
#  van bien, porque son idempotentes y cuestan un segundo.
if [ -z "$DRY_RUN" ]; then
    echo "--- extras ---"

    # 1. storage/: la app escribe aqui sus logs. El mirror no lo sube
    #    (no hay nada que sincronizar, solo estructura vacia).
    if lftp -c "
${LFTP_AJUSTES}
open -u '${DEPLOY_FTP_USER}','${DEPLOY_FTP_PASS}' '${DEPLOY_FTP_HOST}';
mkdir -p '${APP_PATH}/storage/logs';
mkdir -p '${APP_PATH}/storage/cache';
bye;
" >/dev/null 2>&1; then
        echo "    storage/logs y storage/cache listos"
    else
        echo "    AVISO: no se pudo crear storage/ (revisalo a mano)"
    fi

    # 2. El .htaccess que protege app/. Solo tiene sentido si app/ esta
    #    dentro del DocumentRoot, que es el caso por defecto.
    case "$APP_PATH" in
        "$PUBLIC_PATH"/*)
            if [ -f deploy/app.htaccess ]; then
                if subir_archivo deploy/app.htaccess "${APP_PATH}/.htaccess"; then
                    echo "    .htaccess de proteccion subido a ${APP_PATH}/"
                else
                    echo "    AVISO: NO se pudo subir ${APP_PATH}/.htaccess"
                    echo "           app/ queda EXPUESTA por HTTP. Subelo a mano."
                    CONSERVAR_LOGS=1
                fi
            else
                echo "    AVISO: falta deploy/app.htaccess; app/ quedara expuesta"
            fi
            ;;
        *)
            echo "    app/ esta fuera del DocumentRoot: no hace falta .htaccess"
            ;;
    esac

    # 3. Recordatorio del .env, que NUNCA se sube automaticamente.
    echo "    recuerda: el .env se sube a mano una sola vez ->"
    echo "      curl -T .env.production --user \"\$DEPLOY_FTP_USER:\$DEPLOY_FTP_PASS\" \\"
    echo "           \"ftp://\$DEPLOY_FTP_HOST${APP_PATH}/.env\""
    echo ""
fi

# ---------------------------------------------------------------------
#  Recuento final contra el servidor
# ---------------------------------------------------------------------
#  Cada seccion ya se verifico y reintento durante su subida. Este pase
#  vuelve a contarlo todo al final para dar una foto coherente del estado
#  real, tomada despues de que todo haya terminado.
VERIF_REMOTOS=()

if [ -z "$DRY_RUN" ]; then
    echo ""
    echo "--- recuento final contra el servidor ---"

    for i in "${!VERIF_RUTAS[@]}"; do
        VERIF_REMOTOS+=("$(contar_remoto "${VERIF_RUTAS[$i]}" "${VERIF_EXCLUIR[$i]}")")
    done
fi

# ---------------------------------------------------------------------
#  Resumen
# ---------------------------------------------------------------------
echo ""
echo "=================================================="
echo " Resumen"
echo "=================================================="
printf "  %-9s %-11s %-31s\n" "SECCION" "ESTADO" "DETALLE"
printf "  %-9s %-11s %-31s\n" "---------" "--------" "----------------------------------"

hay_fallos=0
for i in "${!SECCIONES[@]}"; do
    printf "  %-9s %-11s %-31s\n" "${SECCIONES[$i]}" "${RESULTADOS[$i]}" "${DETALLES[$i]}"
    [ "${RESULTADOS[$i]}" = "FALLO" ] && hay_fallos=1
done

if [ -z "$DRY_RUN" ]; then
    echo ""
    printf "  %-9s %8s %8s   %s\n" "SECCION" "LOCAL" "SERVIDOR" "ESTADO"
    printf "  %-9s %8s %8s   %s\n" "---------" "--------" "--------" "------"

    for i in "${!VERIF_NOMBRES[@]}"; do
        local_n="${VERIF_LOCALES[$i]}"
        remoto_n="${VERIF_REMOTOS[$i]}"
        if [ "$remoto_n" -ge "$local_n" ]; then
            estado="completo"
        elif [ "$remoto_n" -eq 0 ]; then
            estado="VACIO"
            hay_fallos=1
        else
            estado="INCOMPLETO (faltan $((local_n - remoto_n)))"
            hay_fallos=1
        fi
        printf "  %-9s %8s %8s   %s\n" "${VERIF_NOMBRES[$i]}" "$local_n" "$remoto_n" "$estado"
    done
fi

echo ""

if [ -n "$DRY_RUN" ]; then
    echo " Simulacion terminada. No se subio nada."
    echo "=================================================="
    exit 0
fi

if [ "$hay_fallos" -eq 1 ]; then
    CONSERVAR_LOGS=1

    echo " TERMINADO CON PROBLEMAS."
    echo ""
    echo " Que hacer, en este orden:"
    echo ""
    echo "   1. Vuelve a lanzarlo. El comando es este, tal cual:"
    echo ""
    echo "        ./scripts/deploy.sh"
    echo ""
    echo "      Solo sube lo que falta, asi que tarda mucho menos que la"
    echo "      primera vez."
    echo ""
    echo "   2. Si una seccion sigue incompleta, mira que dijo lftp:"
    echo ""
    echo "        ls ${LOGS}/"
    echo "        less ${LOGS}/<seccion>-intento1.log"
    echo ""
    echo "      Los logs se conservan porque hubo problemas; en un"
    echo "      despliegue limpio se borran solos."
    echo ""
    echo "   3. Si tras varios intentos sigue sin completarse, con una"
    echo "      conexion lenta lo fiable es subir esa carpeta comprimida"
    echo "      en un solo archivo y descomprimirla en el servidor."
    echo "=================================================="
    exit 3
fi

echo " Despliegue completo: las 5 secciones subieron correctamente."
echo ""
echo " Pendiente la primera vez:"
echo "   1. Subir el .env de produccion (NO se sube desde aqui):"
echo "        curl -T .env.production --user \"\$DEPLOY_FTP_USER:\$DEPLOY_FTP_PASS\" \\"
echo "             \"ftp://\$DEPLOY_FTP_HOST${APP_PATH}/.env\""
echo "   2. Importar deploy/import-hosting.sql por phpMyAdmin, sobre la"
echo "      base que te dio el panel. Ese archivo ya viene sin CREATE"
echo "      DATABASE, sin USE y sin CREATE TEMPORARY TABLE, que son los"
echo "      tres que un hosting compartido rechaza."
echo "   3. Programar el cron:"
echo "      php ${APP_PATH}/scripts/send-reminders.php"
echo ""
echo " Comprueba que funciona, EN ESTE ORDEN:"
echo "   https://tu-dominio/health       -> \"db\": {\"ok\": true}"
echo "   https://tu-dominio/app/.env     -> 403. Si devuelve 200, tu"
echo "                                      contrasena de MySQL es publica:"
echo "                                      falta ${APP_PATH}/.htaccess."
echo "=================================================="
