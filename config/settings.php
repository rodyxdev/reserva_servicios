<?php

declare(strict_types=1);

/**
 * Carga de configuracion.
 *
 * Devuelve un array plano de settings leido de .env. Se llama una sola vez
 * desde public/index.php y desde los scripts de scripts/.
 *
 * Regla: NINGUN otro archivo del proyecto lee $_ENV ni getenv() directamente.
 * Todo pasa por aqui, para que exista un unico lugar donde ver que se
 * configura, que valores por defecto hay y como se castean los tipos.
 */

use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);

// El .env es opcional: en produccion (InfinityFree, contenedores) la
// configuracion suele llegar por variables de entorno reales.
if (is_file($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->load();
}

/**
 * Lee una variable de entorno con valor por defecto y casteo de literales.
 *
 * getenv() en algunos SAPI no ve lo que escribe Dotenv, y $_ENV no siempre
 * recibe lo que inyecta el contenedor. Se consultan ambos.
 */
$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true', '(true)'   => true,
        'false', '(false)' => false,
        'null', '(null)'   => null,
        default            => $value,
    };
};

$appEnv = (string) $env('APP_ENV', 'production');

return [
    'root_path'    => $rootPath,
    'storage_path' => $rootPath . '/storage',
    'views_path'   => $rootPath . '/src/Views',

    'app' => [
        'env'         => $appEnv,
        'debug'       => (bool) $env('APP_DEBUG', $appEnv !== 'production'),
        'url'         => rtrim((string) $env('APP_URL', 'http://localhost:8080'), '/'),
        'name'        => (string) $env('APP_NAME', 'Sistema de Reservas'),
        'key'         => (string) $env('APP_KEY', ''),
        // La v1 sirve un solo negocio. El esquema aguanta varios; cuando
        // llegue el momento, esto se resuelve por subdominio o por slug de
        // la URL en lugar de leerse de la configuracion.
        'business_id' => (int) $env('APP_BUSINESS_ID', 1),
    ],

    'db' => [
        'host'    => (string) $env('DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('DB_PORT', 3306),
        'name'    => (string) $env('DB_NAME', 'reservas'),
        'user'    => (string) $env('DB_USER', 'root'),
        'pass'    => (string) $env('DB_PASS', ''),
        'charset' => (string) $env('DB_CHARSET', 'utf8mb4'),
    ],

    'mail' => [
        'host'          => (string) $env('MAIL_HOST', 'sandbox.smtp.mailtrap.io'),
        'port'          => (int) $env('MAIL_PORT', 2525),
        'username'      => (string) $env('MAIL_USERNAME', ''),
        'password'      => (string) $env('MAIL_PASSWORD', ''),
        'encryption'    => (string) $env('MAIL_ENCRYPTION', 'tls'),
        'from_address'  => (string) $env('MAIL_FROM_ADDRESS', 'no-responder@localhost'),
        'from_name'     => (string) $env('MAIL_FROM_NAME', 'Sistema de Reservas'),
        'notify_to'     => (string) $env('MAIL_BUSINESS_NOTIFY', ''),
        // En true no se envia nada: los correos se escriben en
        // storage/logs/mail.log. Permite desarrollar sin credenciales SMTP.
        'pretend'       => (bool) $env('MAIL_PRETEND', false),

        // Pausa minima entre envios, en milisegundos.
        //
        // Casi todos los SMTP compartidos limitan la tasa. El sandbox
        // gratuito de Mailtrap rechaza con "550 Too many emails per
        // second" en cuanto se le mandan dos correos seguidos, y el flujo
        // de reserva manda justo dos (confirmacion y aviso al negocio).
        // 1100 ms deja margen sobre el limite de 1/segundo.
        //
        // En un SMTP propio sin limite se puede bajar a 0.
        'throttle_ms'   => (int) $env('MAIL_THROTTLE_MS', 2000),
    ],

    'security' => [
        'auth_max_attempts'    => (int) $env('AUTH_MAX_ATTEMPTS', 5),
        'auth_lockout_minutes' => (int) $env('AUTH_LOCKOUT_MINUTES', 15),

        // Antelacion minima para que el CLIENTE cancele por su cuenta desde
        // el enlace del correo. Deliberadamente MENOR que el
        // min_advance_minutes del negocio: si fueran iguales, quien reserva
        // justo en el limite no podria cancelar nunca, ni un segundo
        // despues de haber confirmado.
        'cancel_min_notice_minutes' => (int) $env('CANCEL_MIN_NOTICE_MINUTES', 60),
        'rate_limit' => [
            // bucket => [max intentos, ventana en minutos]
            'booking' => [
                (int) $env('RATE_LIMIT_BOOKING_MAX', 5),
                (int) $env('RATE_LIMIT_BOOKING_WINDOW', 10),
            ],
            'login' => [
                (int) $env('RATE_LIMIT_LOGIN_MAX', 20),
                (int) $env('RATE_LIMIT_LOGIN_WINDOW', 10),
            ],
        ],
        // Solo activar si hay un proxy de confianza delante (nginx, Cloudflare).
        // Con esto en true y sin proxy, cualquiera falsea X-Forwarded-For y
        // se salta el rate limiting escribiendo una IP distinta cada vez.
        'trust_proxy_headers' => (bool) $env('TRUST_PROXY_HEADERS', false),
    ],

    /**
     * Granularidad de la malla de bloqueo, en minutos.
     *
     * NO confundir con businesses.slot_granularity_minutes, que es el paso
     * de la grilla que ve el cliente (15, 20, 30...). Este valor es el
     * tamano del bloque que se materializa en appointment_slots y define la
     * resolucion maxima del sistema.
     *
     * Es una constante del sistema, no configuracion por negocio: cambiarla
     * con datos ya cargados invalidaria todos los slots existentes, porque
     * dejarian de estar alineados a la nueva malla.
     */
    'slot_block_minutes' => 5,
];
