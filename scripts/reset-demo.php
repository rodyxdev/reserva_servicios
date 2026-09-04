<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  RESTAURAR LA DEMO
 * =====================================================================
 *
 *  Vacia todas las tablas y vuelve a cargar database/seed.sql, dejando la
 *  demo como recien instalada. Pensado para cuando alguien ensucia el
 *  flujo publico probando: reservas de prueba, cancelaciones, cuentas
 *  bloqueadas por teclear mal la contrasena.
 *
 *  USO
 *  ---
 *      php scripts/reset-demo.php --force
 *      php scripts/reset-demo.php --force --keep-tokens
 *
 *      --force         Obligatorio. Sin esto no hace nada.
 *      --keep-tokens   Conserva los public_token deterministas del seed.
 *                      Por defecto se regeneran al azar.
 *      --quiet         Solo errores.
 *
 *  POR QUE EXIGE --force
 *  ---------------------
 *  Porque borra TODO. Un script destructivo que se ejecuta sin confirmar
 *  es cuestion de tiempo que se lance en la base equivocada: basta un
 *  historial de shell y una pestana con el .env de produccion abierto.
 *
 *  Ademas se niega a correr si APP_ENV es production, aunque le pasen
 *  --force. Para forzarlo ahi hay que poner ALLOW_RESET_IN_PRODUCTION=1
 *  en el entorno, que es un gesto lo bastante deliberado como para que
 *  nadie lo haga por inercia.
 * =====================================================================
 */

use App\Support\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta desde la linea de comandos.\n");
}

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string,mixed> $settings */
$settings = require dirname(__DIR__) . '/config/settings.php';

date_default_timezone_set('UTC');

$opciones = getopt('', ['force', 'keep-tokens', 'quiet', 'help']);

if (isset($opciones['help']) || !isset($opciones['force'])) {
    exit(<<<TXT
    Restaura la demo al estado inicial. BORRA TODOS LOS DATOS.

    Uso: php scripts/reset-demo.php --force [opciones]

      --force         Obligatorio. Confirma que quieres borrar todo.
      --keep-tokens   Conserva los public_token del seed (por defecto se
                      regeneran al azar, que es lo correcto para una demo
                      publica: los del seed son MD5 predecibles).
      --quiet         Solo muestra errores.

    TXT);
}

$quiet = isset($opciones['quiet']);

function decir(string $mensaje): void
{
    global $quiet;

    if (!$quiet) {
        fwrite(STDOUT, $mensaje . "\n");
    }
}

// ---------------------------------------------------------------------
//  Salvaguarda de produccion
// ---------------------------------------------------------------------
if ($settings['app']['env'] === 'production' && getenv('ALLOW_RESET_IN_PRODUCTION') !== '1') {
    fwrite(STDERR, "NEGADO: APP_ENV es 'production'.\n");
    fwrite(STDERR, "Si de verdad quieres borrar esta base, exporta ALLOW_RESET_IN_PRODUCTION=1.\n");
    exit(1);
}

$seed = dirname(__DIR__) . '/database/seed.sql';

if (!is_file($seed)) {
    fwrite(STDERR, "ERROR: no se encuentra database/seed.sql\n");
    exit(1);
}

try {
    $pdo = Database::connect($settings['db']);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR de conexion: ' . $e->getMessage() . "\n");
    exit(1);
}

decir(sprintf(
    'Restaurando %s en %s:%d ...',
    $settings['db']['name'],
    $settings['db']['host'],
    $settings['db']['port'],
));

$inicio = microtime(true);

// ---------------------------------------------------------------------
//  Vaciado
// ---------------------------------------------------------------------
//  El propio seed.sql ya hace TRUNCATE de todo, pero se repite aqui por
//  una razon: si en el futuro alguien anade una tabla al esquema y olvida
//  incluirla en el seed, este barrido la deja limpia igualmente. La demo
//  no debe conservar restos de nadie.
$tablas = [
    'appointment_status_log',
    'appointment_reminders',
    'appointment_slots',
    'appointments',
    'customers',
    'schedule_exceptions',
    'employee_hours',
    'business_hours',
    'employee_service',
    'employees',
    'services',
    'users',
    'rate_limits',
    'businesses',
];

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

foreach ($tablas as $tabla) {
    try {
        $pdo->exec('TRUNCATE TABLE `' . $tabla . '`');
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("Aviso: no se pudo vaciar %s (%s)\n", $tabla, $e->getMessage()));
    }
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

decir(sprintf('  %d tablas vaciadas.', count($tablas)));

// ---------------------------------------------------------------------
//  Recarga del seed
// ---------------------------------------------------------------------
//  PDO::exec() no acepta varias sentencias separadas por ";" de forma
//  fiable con prepared statements reales, asi que el archivo se parte.
//  El separador tiene en cuenta que puede haber ";" DENTRO de cadenas
//  (los textos del seed llevan comas y puntos), por eso no vale un
//  explode(';') a secas.
$sql = file_get_contents($seed);

if ($sql === false) {
    fwrite(STDERR, "ERROR: no se pudo leer seed.sql\n");
    exit(1);
}

$sentencias = dividirSql($sql);
$ejecutadas = 0;

// ---------------------------------------------------------------------
//  SIN TRANSACCION, Y A PROPOSITO
// ---------------------------------------------------------------------
//  El primer intento envolvia esto en beginTransaction()/commit(). Fallaba
//  siempre, con "There is no active transaction", y la razon es
//  instructiva: seed.sql empieza con TRUNCATE TABLE, y TRUNCATE provoca un
//  COMMIT IMPLICITO en MySQL y MariaDB. El driver consulta el flag de
//  estado del servidor, ve que ya no hay transaccion abierta, y el commit
//  final revienta.
//
//  Y aunque no reventara, la transaccion seria decorativa: el commit
//  implicito ya habria hecho permanente el borrado, asi que un rollback no
//  podria devolver nada. La atomicidad aqui es imposible con este archivo.
//
//  Lo honesto es no fingirla: se ejecuta sentencia a sentencia y, si algo
//  falla, se dice exactamente donde y que el remedio es volver a lanzar el
//  script (que es idempotente, porque empieza vaciandolo todo).
// ---------------------------------------------------------------------
foreach ($sentencias as $sentencia) {
    try {
        $pdo->exec($sentencia);
        $ejecutadas++;
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf(
            "ERROR en la sentencia %d de %d:\n  %s\n  SQL: %s\n",
            $ejecutadas + 1,
            count($sentencias),
            $e->getMessage(),
            substr(preg_replace('/\s+/', ' ', $sentencia) ?? '', 0, 120),
        ));
        fwrite(STDERR, "\nLa base quedo a medias. Vuelve a ejecutar el script:\n");
        fwrite(STDERR, "  php scripts/reset-demo.php --force\n");
        exit(1);
    }
}

decir(sprintf('  %d sentencias ejecutadas.', $ejecutadas));

// ---------------------------------------------------------------------
//  Tokens
// ---------------------------------------------------------------------
//  Los del seed son MD5('seed-appt-N'): deterministas a proposito, para
//  que el seed sea reproducible. Pero en una demo publica eso significa
//  que cualquiera que lea el repositorio puede calcular el enlace de
//  gestion de cualquier cita y cancelarla.
//
//  Por eso, por defecto, se regeneran con el mismo random_bytes() que usa
//  la aplicacion en produccion.
if (!isset($opciones['keep-tokens'])) {
    $stmt = $pdo->query('SELECT id FROM appointments');
    $update = $pdo->prepare('UPDATE appointments SET public_token = :token WHERE id = :id');
    $n = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $update->execute(['token' => bin2hex(random_bytes(16)), 'id' => (int) $id]);
        $n++;
    }

    decir(sprintf('  %d token(s) publicos regenerados al azar.', $n));
} else {
    decir('  Tokens del seed conservados (--keep-tokens).');
}

// ---------------------------------------------------------------------
//  Resumen
// ---------------------------------------------------------------------
$conteos = [];

foreach (['businesses', 'users', 'services', 'employees', 'customers',
          'appointments', 'appointment_slots', 'appointment_reminders'] as $tabla) {
    $conteos[$tabla] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $tabla . '`')->fetchColumn();
}

decir('');
decir('Estado restaurado:');

foreach ($conteos as $tabla => $n) {
    decir(sprintf('  %-24s %4d', $tabla, $n));
}

// Comprobacion de coherencia: ninguna cita cancelada debe conservar
// bloques de agenda. Si esto falla, el seed esta mal.
$huerfanos = (int) $pdo->query(
    "SELECT COUNT(*) FROM appointment_slots s
       JOIN appointments a ON a.id = s.appointment_id
      WHERE a.status = 'cancelled'"
)->fetchColumn();

if ($huerfanos > 0) {
    fwrite(STDERR, sprintf(
        "AVISO: %d bloque(s) de agenda pertenecen a citas canceladas.\n",
        $huerfanos,
    ));
}

decir('');
decir(sprintf('Listo en %.2f s.', microtime(true) - $inicio));
decir('Acceso al panel: admin@spa-aurora.test / Demo1234!');

/**
 * Parte un volcado SQL en sentencias.
 *
 * No sirve un explode(';'): las descripciones de los servicios contienen
 * puntos y comas, y partir por ahi rompe los INSERT. Se recorre el texto
 * caracter a caracter respetando comillas, escapes y comentarios.
 *
 * @return list<string>
 */
function dividirSql(string $sql): array
{
    $sentencias = [];
    $actual     = '';
    $longitud   = strlen($sql);

    $enComillaSimple = false;
    $enComillaDoble  = false;
    $enComentario    = false;
    $enBloque        = false;

    for ($i = 0; $i < $longitud; $i++) {
        $c   = $sql[$i];
        $sig = $sql[$i + 1] ?? '';

        // --- comentarios ---
        if (!$enComillaSimple && !$enComillaDoble) {
            if (!$enComentario && !$enBloque && $c === '-' && $sig === '-') {
                $enComentario = true;
            } elseif (!$enComentario && !$enBloque && $c === '/' && $sig === '*') {
                $enBloque = true;
                $i++;
                continue;
            }

            if ($enComentario && $c === "\n") {
                $enComentario = false;
                $actual .= $c;
                continue;
            }

            if ($enBloque && $c === '*' && $sig === '/') {
                $enBloque = false;
                $i++;
                continue;
            }

            if ($enComentario || $enBloque) {
                continue;
            }
        }

        // --- comillas ---
        if ($c === "'" && !$enComillaDoble && ($sql[$i - 1] ?? '') !== '\\') {
            $enComillaSimple = !$enComillaSimple;
        } elseif ($c === '"' && !$enComillaSimple && ($sql[$i - 1] ?? '') !== '\\') {
            $enComillaDoble = !$enComillaDoble;
        }

        // --- fin de sentencia ---
        if ($c === ';' && !$enComillaSimple && !$enComillaDoble) {
            $limpia = trim($actual);

            if ($limpia !== '') {
                $sentencias[] = $limpia;
            }

            $actual = '';
            continue;
        }

        $actual .= $c;
    }

    $limpia = trim($actual);

    if ($limpia !== '') {
        $sentencias[] = $limpia;
    }

    return $sentencias;
}
