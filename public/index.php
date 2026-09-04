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

require __DIR__ . '/../vendor/autoload.php';

/** @var array<string,mixed> $settings */
$settings = require __DIR__ . '/../config/settings.php';

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
$pdo = Database::connect($settings['db']);

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
(require __DIR__ . '/../config/routes.php')($app, $pdo, $settings);

$app->run();
