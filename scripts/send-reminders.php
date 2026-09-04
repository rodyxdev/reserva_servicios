<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  ENVIO DE AVISOS PENDIENTES
 * =====================================================================
 *
 *  Procesa la cola de appointment_reminders: recoge lo que ya vencio, lo
 *  envia y marca cada fila como enviada o fallida.
 *
 *  USO
 *  ---
 *      php scripts/send-reminders.php [opciones]
 *
 *      --dry-run     No envia nada. Muestra que haria.
 *      --limit=N     Maximo de avisos por corrida (por defecto 50).
 *      --verbose     Detalla cada envio.
 *      --no-prune    No limpia rate_limits ni reencola atascados.
 *
 *  CRON
 *  ----
 *  Cada 5 minutos basta: el recordatorio de 24h no necesita precision al
 *  minuto, y una frecuencia alta solo multiplica conexiones a la base.
 *
 *      *slash5 * * * * /usr/bin/php /ruta/al/proyecto/scripts/send-reminders.php >> /ruta/logs/cron.log 2>&1
 *
 *  (donde "slash5" es una barra seguida de 5; escrito asi para no cerrar
 *  este bloque de comentario)
 *
 *  En InfinityFree el cron minimo suele ser cada hora; ajusta el
 *  recordatorio a 24h sabiendo que puede salir con hasta una hora de
 *  desfase, que para avisar "manana tienes cita" es irrelevante.
 *
 *  POR QUE ES SEGURO EJECUTARLO VARIAS VECES
 *  -----------------------------------------
 *  Tres capas, de la mas fuerte a la mas debil:
 *
 *    1. UNIQUE (appointment_id, kind, channel) en la tabla: no puede
 *       existir dos veces el mismo aviso para la misma cita.
 *    2. Estado 'sending': una fila reclamada por un proceso no la ve otro.
 *    3. FOR UPDATE SKIP LOCKED (o el bloqueo por UPDATE en motores que no
 *       lo soportan): dos crons simultaneos no se pisan ni se esperan.
 *
 *  Se puede lanzar a mano mientras el cron corre sin miedo a duplicados.
 * =====================================================================
 */

use App\Services\MailService;
use App\Services\NotificationService;
use App\Support\Database;
use App\Support\RateLimiter;
use App\Support\View;

// Este script solo tiene sentido en linea de comandos. Si alguien lo
// sube por error a un directorio publico y lo pide por HTTP, no debe
// ejecutarse: mandaria correos a peticion de cualquiera.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta desde la linea de comandos.\n");
}

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string,mixed> $settings */
$settings = require dirname(__DIR__) . '/config/settings.php';

date_default_timezone_set('UTC');

// ---------------------------------------------------------------------
//  Opciones
// ---------------------------------------------------------------------
$opciones = getopt('', ['dry-run', 'limit::', 'verbose', 'no-prune', 'help']);

if (isset($opciones['help'])) {
    exit(<<<TXT
    Uso: php scripts/send-reminders.php [opciones]

      --dry-run     No envia nada, solo muestra que haria
      --limit=N     Maximo de avisos por corrida (por defecto 50)
      --verbose     Detalla cada envio
      --no-prune    No limpia rate_limits ni reencola atascados
      --help        Esta ayuda

    TXT);
}

$dryRun  = isset($opciones['dry-run']);
$verbose = isset($opciones['verbose']) || $dryRun;
$limite  = max(1, min((int) ($opciones['limit'] ?? 50), 500));

$inicio = microtime(true);

function salida(string $mensaje): void
{
    fwrite(STDOUT, sprintf("[%s] %s\n", gmdate('H:i:s'), $mensaje));
}

// ---------------------------------------------------------------------
//  Arranque
// ---------------------------------------------------------------------
try {
    $pdo = Database::connect($settings['db']);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: no se pudo conectar con la base de datos.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$view = new View($settings['views_path']);
$mail = new MailService(
    $settings['mail'],
    $view,
    $settings['storage_path'] . '/logs/mail.log',
);

$notifications = new NotificationService($pdo, $mail, $settings);

salida('Motor: ' . $notifications->engineInfo());

if ($settings['mail']['pretend']) {
    salida('MAIL_PRETEND activo: los correos se escriben en storage/logs/mail.log.');
}

// ---------------------------------------------------------------------
//  Comprobar el SMTP ANTES de tocar la cola
// ---------------------------------------------------------------------
//  Si el servidor de correo no responde, procesar la cola solo serviria
//  para marcar veinte avisos como fallidos y gastarles un intento a cada
//  uno. Es mejor salir sin tocar nada y que la siguiente corrida lo
//  intente con el contador intacto.
if (!$dryRun) {
    $prueba = $mail->testConnection();

    if (!$prueba['ok']) {
        fwrite(STDERR, "ERROR: el servidor SMTP no responde.\n");
        fwrite(STDERR, '  ' . $prueba['message'] . "\n");
        fwrite(STDERR, "No se toco la cola: los avisos siguen pendientes.\n");
        exit(2);
    }

    if ($verbose) {
        salida('SMTP: ' . $prueba['message']);
    }
}

// ---------------------------------------------------------------------
//  Mantenimiento
// ---------------------------------------------------------------------
if (!isset($opciones['no-prune'])) {
    $reencolados = $notifications->requeueStale(15);

    if ($reencolados > 0) {
        salida(sprintf(
            '%d aviso(s) atascados en "sending" devueltos a la cola '
            . '(un proceso anterior murio a mitad).',
            $reencolados,
        ));
    }

    // La tabla de rate limiting crece con cada peticion publica. Si nadie
    // la limpia, en hosting compartido acaba comiendose la cuota de disco.
    $borrados = (new RateLimiter($pdo))->prune(24);

    if ($borrados > 0 && $verbose) {
        salida(sprintf('%d registro(s) antiguos de rate_limits eliminados.', $borrados));
    }
}

// ---------------------------------------------------------------------
//  Procesar la cola
// ---------------------------------------------------------------------
try {
    $pendientes = $notifications->claimDue($limite);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR al leer la cola: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($pendientes === []) {
    salida('No hay avisos pendientes.');
    exit(0);
}

salida(sprintf('%d aviso(s) por procesar.', count($pendientes)));

$enviados  = 0;
$fallidos  = 0;
$limitados = 0;

foreach ($pendientes as $aviso) {
    $etiqueta = sprintf(
        '#%d %s -> %s (cita #%d, %s)',
        $aviso['reminder_id'],
        $aviso['kind'],
        $aviso['customer_email'],
        $aviso['id'],
        $aviso['starts_at'],
    );

    if ($dryRun) {
        salida('  [simulacion] ' . $etiqueta);
        continue;
    }

    $resultado = $notifications->deliver($aviso);

    if ($resultado === NotificationService::RESULT_SENT) {
        $enviados++;

        if ($verbose) {
            salida('  enviado  ' . $etiqueta);
        }

        continue;
    }

    if ($resultado === NotificationService::RESULT_RATE_LIMITED) {
        // -----------------------------------------------------------------
        //  El servidor pide freno: se corta el lote aqui
        // -----------------------------------------------------------------
        //  Seguir enviando solo produce mas rechazos, y cada uno cuesta una
        //  conexion SMTP completa. Los avisos que quedan siguen en la cola
        //  con su contador intacto y saldran en la proxima corrida del cron.
        //
        //  No es un error: es el sistema respetando un limite ajeno. Por eso
        //  no cuenta como fallo ni cambia el codigo de salida.
        // -----------------------------------------------------------------
        $limitados++;

        salida('  el servidor de correo esta limitando el ritmo; se corta el lote.');
        salida('  quedan ' . (count($pendientes) - $enviados - $fallidos - $limitados)
            . ' aviso(s) para la proxima corrida.');
        break;
    }

    $fallidos++;
    fwrite(STDERR, '  FALLO    ' . $etiqueta . "\n");
}

// En dry-run las filas quedaron marcadas como 'sending' al reclamarlas.
// Hay que devolverlas a la cola o el simulacro las dejaria bloqueadas.
if ($dryRun) {
    $pdo->prepare(
        "UPDATE appointment_reminders SET status = 'pending', last_error = NULL
          WHERE status = 'sending'"
    )->execute();

    salida('Simulacion terminada: la cola queda como estaba.');
    exit(0);
}

salida(sprintf(
    'Terminado: %d enviado(s), %d fallido(s)%s, en %.2f s.',
    $enviados,
    $fallidos,
    $limitados > 0 ? ', lote cortado por limite de tasa' : '',
    microtime(true) - $inicio,
));

// Al cortar el lote quedan filas reclamadas ('sending') que no se llegaron
// a intentar. Se devuelven a la cola de inmediato en vez de esperar a que
// requeueStale() las rescate dentro de 15 minutos.
if ($limitados > 0) {
    $liberadas = $notifications->requeueStale(0);

    if ($liberadas > 0 && $verbose) {
        salida(sprintf('%d aviso(s) sin intentar devueltos a la cola.', $liberadas));
    }
}

// Codigo de salida distinto de cero si hubo FALLOS reales. Una limitacion
// de tasa no lo es: el trabajo simplemente continua en la siguiente
// corrida, y marcar el cron en rojo por eso solo genera ruido.
exit($fallidos > 0 ? 3 : 0);
