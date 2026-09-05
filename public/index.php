<?php

declare(strict_types=1);

/**
 * Front controller.
 *
 * Es el UNICO archivo PHP alcanzable por HTTP. Todo lo demas (src/, config/,
 * vendor/, .env) vive fuera del DocumentRoot, que apunta a public/.
 *
 * Esto no es una preferencia de estilo: si .env estuviera bajo el
 * DocumentRoot, bastaria con que el servidor dejara de interpretar PHP un
 * instante (una actualizacion fallida, un modulo caido) para servir las
 * credenciales de la base en texto plano.
 */

use App\Middleware\SecurityHeadersMiddleware;
use App\Support\Database;
use App\Support\Session;
use Slim\Factory\AppFactory;

// ---------------------------------------------------------------------
//  Archivos estaticos bajo el servidor embebido de PHP
// ---------------------------------------------------------------------
//  Con Apache o nginx esto no hace falta: el .htaccess de public/ ya
//  descarta las peticiones a archivos que existen antes de llegar a PHP
//  (RewriteCond %{REQUEST_FILENAME} !-f).
//
//  Pero "php -S localhost:8080 -t public public/index.php" pasa TODAS las
//  peticiones por el router, incluidas las de CSS, JS e imagenes, y Slim
//  responde 404 porque no tiene rutas para ellas. Devolver false le dice
//  al servidor embebido que sirva el archivo el mismo.
//
//  Es la forma soportada de hacerlo y solo se activa bajo el SAPI
//  cli-server, asi que no anade ni una comprobacion en produccion.
if (PHP_SAPI === 'cli-server') {
    $requested = __DIR__ . urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

    if (is_file($requested)) {
        return false;
    }
}

// ---------------------------------------------------------------------
//  Localizar la raiz de la aplicacion
// ---------------------------------------------------------------------
//  El proyecto vive en dos disposiciones distintas segun donde corra, y
//  este archivo funciona en ambas sin tener dos versiones que mantener.
//
//  A) TODO JUNTO — local, Docker, VPS con DocumentRoot propio:
//
//         proyecto/
//         ├── public/    <- DocumentRoot, aqui esta este archivo
//         ├── config/
//         ├── src/
//         └── vendor/
//
//     La raiz es el directorio padre de public/.
//
//  B) SEPARADO — hosting compartido tipo InfinityFree, donde el
//     DocumentRoot es /htdocs y no se puede mover:
//
//         /htdocs/       <- contenido de public/, aqui esta este archivo
//         /app/
//         ├── config/
//         ├── src/
//         └── vendor/
//
//     La raiz es /app, una carpeta hermana de htdocs. Se pone fuera del
//     DocumentRoot para que .env, src/ y vendor/ no sean alcanzables por
//     HTTP bajo ninguna circunstancia.
//
//  Se prueban las ubicaciones en orden y se usa la primera que contenga
//  realmente la aplicacion. La comprobacion busca vendor/autoload.php,
//  no solo que el directorio exista: una carpeta "app" vacia, o a medio
//  subir por FTP, no debe dar por buena una raiz que no funciona.
//
//  La variable de entorno APP_ROOT permite forzar una ruta concreta si
//  tu hosting usa una disposicion distinta a estas dos.
// ---------------------------------------------------------------------
$appRoot = (static function (): string {
    $candidatas = [];

    if (($forzada = getenv('APP_ROOT')) !== false && $forzada !== '') {
        $candidatas[] = rtrim($forzada, '/\\');
    }

    // A) todo junto: el padre de public/
    $candidatas[] = dirname(__DIR__);

    // B) separado: carpeta app/ hermana del DocumentRoot
    $candidatas[] = dirname(__DIR__) . '/app';

    // C) separado DENTRO del DocumentRoot. Necesario en hostings cuyo
    //    open_basedir encierra a PHP en el DocumentRoot: alli la opcion
    //    B no falla por estar mal subida, falla porque PHP tiene
    //    prohibido mirar fuera y file_exists() devuelve false sobre
    //    archivos que existen. InfinityFree publica, por ejemplo:
    //      open_basedir: ...:/home/vol5_2/infinityfree.com/if0_XXXX/htdocs
    //    Al estar app/ dentro, no queda mas remedio que impedir por HTTP
    //    lo que antes impedia la propia jerarquia de carpetas: por eso
    //    app/ lleva su propio .htaccess que deniega todo.
    $candidatas[] = __DIR__ . '/app';

    foreach ($candidatas as $ruta) {
        if (is_file($ruta . '/vendor/autoload.php') && is_file($ruta . '/config/settings.php')) {
            return $ruta;
        }
    }

    // Sin raiz no hay nada que hacer. Se falla con un mensaje que dice
    // que falta y donde se busco, en vez de con un "failed to open
    // stream" que no orienta a nadie.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    // Decir "no se encuentra" a secas no basta cuando el archivo SI
    // existe pero PHP tiene prohibido leerlo. En hosting compartido eso
    // pasa constantemente: open_basedir limita a PHP al DocumentRoot,
    // asi que un vendor/ subido por FTP fuera de el existe para el
    // cliente FTP y no existe para PHP. Sin este detalle el sintoma
    // (is_file falso) es identico al de un archivo ausente, y se pierden
    // horas volviendo a subir algo que ya estaba.
    $informe = '';

    foreach ($candidatas as $ruta) {
        $informe .= "  - {$ruta}\n";

        $comprobar = [
            ''                     => 'la carpeta',
            '/vendor/autoload.php' => 'vendor/autoload.php',
            '/config/settings.php' => 'config/settings.php',
        ];

        foreach ($comprobar as $sufijo => $etiqueta) {
            $completa = $ruta . $sufijo;
            $existe   = @file_exists($completa);
            $legible  = @is_readable($completa);

            $veredicto = match (true) {
                !$existe             => 'no existe',
                $existe && !$legible => 'EXISTE pero PHP no puede leerlo (permisos u open_basedir)',
                default              => 'correcto',
            };

            $informe .= sprintf("      %-22s %s\n", $etiqueta . ':', $veredicto);
        }
    }

    $basedir = ini_get('open_basedir');

    exit(
        "Error de instalacion: no se encuentra la aplicacion.\n\n"
        . "Se busco vendor/autoload.php y config/settings.php en:\n\n"
        . $informe . "\n"
        . 'open_basedir: '
        . ($basedir !== false && $basedir !== '' ? $basedir : '(sin restriccion)') . "\n\n"
        . "Comprueba que:\n"
        . "  1. Ejecutaste 'composer install'.\n"
        . "  2. Si algo dice 'EXISTE pero PHP no puede leerlo', el problema\n"
        . "     NO es que falten archivos: estan fuera de lo que permite\n"
        . "     open_basedir. Mueve app/ dentro del DocumentRoot y\n"
        . "     protegela con un .htaccess que la deniegue por HTTP.\n"
        . "  3. Si separaste public/ del resto (hosting compartido), la\n"
        . "     carpeta app/ esta donde toca. Puedes forzar su ruta con la\n"
        . "     variable de entorno APP_ROOT.\n"
    );
})();

require $appRoot . '/vendor/autoload.php';

/** @var array<string,mixed> $settings */
$settings = require $appRoot . '/config/settings.php';

// -----------------------------------------------------------------------
//  Zona horaria del proceso
// -----------------------------------------------------------------------
// PHP calcula en UTC de punta a punta. La hora local del negocio solo
// aparece al presentar y al interpretar lo que escribe el usuario, y esa
// conversion es explicita y esta localizada en el motor de disponibilidad.
// Dejar el proceso en la zona del negocio parece comodo hasta el primer
// cambio de horario de verano.
date_default_timezone_set('UTC');

// -----------------------------------------------------------------------
//  Errores
// -----------------------------------------------------------------------
$debug = (bool) $settings['app']['debug'];

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', $settings['storage_path'] . '/logs/php-error.log');

// -----------------------------------------------------------------------
//  Sesion
// -----------------------------------------------------------------------
$isHttps = ($_SERVER['HTTPS'] ?? '') === 'on'
    || ($_SERVER['SERVER_PORT'] ?? '') === '443'
    || str_starts_with($settings['app']['url'], 'https://');

Session::start(secure: $isHttps);

// -----------------------------------------------------------------------
//  Base de datos
// -----------------------------------------------------------------------
//  Esta conexion ocurre ANTES de que Slim monte su manejador de errores,
//  asi que si falla no hay nadie que convierta la excepcion en una
//  respuesta presentable: con display_errors en Off, el visitante recibe
//  una pagina completamente en blanco.
//
//  Y es el fallo numero uno en un primer despliegue (credenciales mal
//  copiadas, host equivocado, base sin crear). Un 500 vacio no dice nada
//  ni a quien lo despliega ni a quien lo visita.
//
//  Lo que se muestra depende de APP_DEBUG, y la diferencia es deliberada:
//  el detalle de una excepcion de PDO incluye el host, el nombre de la
//  base y a veces el usuario. Es justo lo que se necesita para arreglarlo
//  en desarrollo, y justo lo que no debe verse en un sitio publico.
// -----------------------------------------------------------------------
try {
    $pdo = Database::connect($settings['db']);
} catch (Throwable $e) {
    // El detalle completo va SIEMPRE al log, se muestre o no.
    error_log('[arranque] fallo la conexion con la base de datos: ' . $e->getMessage());

    for ($previa = $e->getPrevious(); $previa !== null; $previa = $previa->getPrevious()) {
        error_log('[arranque]   causa: ' . $previa->getMessage());
    }

    // 503 y no 500: el codigo no esta roto, es una dependencia que no
    // responde. Retry-After le dice a los buscadores que no desindexen
    // el sitio por una caida pasajera.
    http_response_code(503);
    header('Retry-After: 300');
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    $detalle = '';

    if ($debug) {
        // Se recorre la cadena de excepciones porque Database::connect()
        // envuelve la PDOException en una RuntimeException neutra: el
        // motivo real (host desconocido, acceso denegado, base
        // inexistente) esta en la causa, no en el mensaje de arriba.
        $mensajes = [get_class($e) . ': ' . $e->getMessage()];

        for ($previa = $e->getPrevious(); $previa !== null; $previa = $previa->getPrevious()) {
            $mensajes[] = get_class($previa) . ': ' . $previa->getMessage();
        }

        $detalle = '<h2 style="font-size:1rem;margin:1.5rem 0 .5rem">Detalle (APP_DEBUG=true)</h2>'
            . '<pre style="background:#f4f5f7;border:1px solid #e3e5e8;border-radius:6px;'
            . 'padding:1rem;overflow-x:auto;font-size:.8125rem;line-height:1.5">'
            . htmlspecialchars(implode("\n\n", $mensajes), ENT_QUOTES, 'UTF-8')
            . '</pre>'
            . '<p style="font-size:.8125rem;color:#6c757d">'
            . 'Revisa DB_HOST, DB_PORT, DB_NAME, DB_USER y DB_PASS en tu archivo '
            . '<code>.env</code>, y que la base exista y acepte conexiones desde este servidor. '
            . 'Este bloque solo aparece con <code>APP_DEBUG=true</code>.'
            . '</p>';
    }

    // Pagina autocontenida: no se usa el motor de plantillas ni se toca
    // nada mas. Si la base no responde, lo unico seguro es asumir que
    // tampoco funciona el resto.
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>Servicio no disponible</title></head>'
        . '<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
        . 'max-width:38rem;margin:4rem auto;padding:0 1.25rem;color:#212529;line-height:1.6">'
        . '<h1 style="font-size:1.35rem;margin:0 0 .75rem">Servicio no disponible</h1>'
        . '<p>No se pudo conectar a la base de datos. Verifica la configuracion.</p>'
        . '<p style="color:#6c757d;font-size:.9375rem">'
        . 'Si eres cliente y querias reservar una cita, vuelve a intentarlo en unos '
        . 'minutos o llama al negocio por telefono. Tus citas ya confirmadas no se '
        . 'ven afectadas.</p>'
        . $detalle
        . '</body></html>';

    exit(1);
}

// -----------------------------------------------------------------------
//  Aplicacion
// -----------------------------------------------------------------------
$app = AppFactory::create();

// Necesario cuando la app no cuelga de la raiz del dominio (por ejemplo en
// InfinityFree bajo /htdocs/reservas). Slim lo detecta solo.
$app->setBasePath(rtrim(str_replace('index.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/'));

// El orden importa: en Slim los middleware se ejecutan del ultimo anadido
// al primero en la entrada, y al reves en la salida. SecurityHeaders va
// primero en el codigo para ser el ULTIMO en tocar la respuesta, de modo
// que sus cabeceras se apliquen tambien a las respuestas de error.
$app->add(new SecurityHeadersMiddleware(httpsEnabled: $isHttps));

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// En produccion: no se muestran detalles, se registran.
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);

// -----------------------------------------------------------------------
//  Rutas
// -----------------------------------------------------------------------
// Se cargan desde config/routes.php, que recibe la app, la conexion y la
// configuracion. Mantener las rutas fuera de este archivo evita que el
// front controller crezca hasta volverse ilegible.
(require $appRoot . '/config/routes.php')($app, $pdo, $settings);

$app->run();
