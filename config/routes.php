<?php

declare(strict_types=1);

/**
 * Definicion de rutas y cableado de dependencias.
 *
 * Se devuelve una funcion en vez de ejecutar codigo suelto para que el
 * archivo pueda incluirse desde los tests sin arrancar la aplicacion.
 *
 * No se usa un contenedor de inyeccion: con este numero de clases, un
 * cableado explicito se lee mejor que una configuracion de contenedor, y
 * deja a la vista de que depende cada cosa. Es tambien parte del objetivo
 * del proyecto: que se vea el PHP, no la magia del framework.
 */

use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\CalendarController;
use App\Controllers\Admin\EmployeeController;
use App\Controllers\Admin\ServiceController;
use App\Controllers\Site\AppointmentController;
use App\Controllers\Site\BookingController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Models\AppointmentRepository;
use App\Models\CatalogRepository;
use App\Models\CustomerRepository;
use App\Models\EmployeeRepository;
use App\Models\ScheduleRepository;
use App\Models\ServiceRepository;
use App\Models\UserRepository;
use App\Services\AppointmentService;
use App\Services\AuthService;
use App\Services\AvailabilityService;
use App\Services\MailService;
use App\Services\NotificationService;
use App\Support\RateLimiter;
use App\Support\Session;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, PDO $pdo, array $settings): void {

    $basePath = $app->getBasePath();

    // -------------------------------------------------------------------
    //  Repositorios y servicios
    // -------------------------------------------------------------------
    $users        = new UserRepository($pdo);
    $catalog      = new CatalogRepository($pdo);
    $customers    = new CustomerRepository($pdo);
    $appointments = new AppointmentRepository($pdo);
    $schedules    = new ScheduleRepository($pdo);
    $serviceRepo  = new ServiceRepository($pdo);
    $employeeRepo = new EmployeeRepository($pdo);

    $limiter = new RateLimiter($pdo);

    $availability = new AvailabilityService(
        $schedules,
        $appointments,
        (int) $settings['slot_block_minutes'],
    );

    // Lo usan el panel (creacion manual) y el flujo publico de reserva.
    $booking = new AppointmentService(
        $pdo,
        $catalog,
        $customers,
        $appointments,
        $availability,
        (int) $settings['slot_block_minutes'],
    );

    // -------------------------------------------------------------------
    //  Vistas
    // -------------------------------------------------------------------
    // El mismo renderizador sirve para las paginas HTML y para las
    // plantillas de correo: son plantillas PHP igual, solo cambia el
    // layout que las envuelve.
    $view = new View($settings['views_path']);

    // -------------------------------------------------------------------
    //  Correo y cola de avisos
    // -------------------------------------------------------------------
    $mail = new MailService(
        $settings['mail'],
        $view,
        $settings['storage_path'] . '/logs/mail.log',
    );

    $notifications = new NotificationService($pdo, $mail, $settings);

    $auth = new AuthService(
        $users,
        (int) $settings['security']['auth_max_attempts'],
        (int) $settings['security']['auth_lockout_minutes'],
    );

    // Configuracion visible desde cualquier plantilla. Se comparte solo lo
    // que las vistas necesitan: nunca las credenciales de la base ni las
    // del SMTP, aunque esten en el mismo array de settings.
    $view->share('appConfig', [
        'name'               => $settings['app']['name'],
        'base_path'          => $basePath,
        'slot_block_minutes' => $settings['slot_block_minutes'],
        'app_url'            => $settings['app']['url'],
        'current_path'       => '',
    ]);

    $controllerSettings = $settings + ['base_path' => $basePath];

    $authController     = new AuthController($view, $auth, $limiter, $controllerSettings);
    $serviceController  = new ServiceController($view, $serviceRepo, $controllerSettings);
    $employeeController = new EmployeeController($view, $employeeRepo, $serviceRepo, $controllerSettings);
    $calendarController = new CalendarController($view, $appointments, $catalog, $controllerSettings);

    // -------------------------------------------------------------------
    //  Middleware que expone la ruta activa a las vistas
    // -------------------------------------------------------------------
    // Sirve para marcar la pestana actual en la navegacion. Va aqui y no en
    // cada controlador para que ninguno pueda olvidarlo.
    $shareCurrentPath = static function (Request $request, $handler) use ($view, $settings, $basePath): Response {
        $path = $request->getUri()->getPath();

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $view->share('appConfig', [
            'name'               => $settings['app']['name'],
            'base_path'          => $basePath,
            'slot_block_minutes' => $settings['slot_block_minutes'],
            'app_url'            => $settings['app']['url'],
            'current_path'       => '/' . trim($path, '/'),
        ]);

        $view->share('authUser', $request->getAttribute('auth_user'));

        return $handler->handle($request);
    };

    // ===================================================================
    //  PANEL DE ADMINISTRACION
    // ===================================================================
    $app->group('/admin', function (RouteCollectorProxy $g) use (
        $authController,
        $serviceController,
        $employeeController,
        $calendarController,
    ): void {

        // ---- Sesion ----
        $g->get('/login', [$authController, 'showLogin'])->setName('login');
        $g->post('/login', [$authController, 'login']);
        $g->post('/logout', [$authController, 'logout']);

        // ---- Resumen ----
        $g->get('', [$calendarController, 'dashboard']);
        $g->get('/', [$calendarController, 'dashboard']);

        // ---- Calendario ----
        $g->get('/calendario', [$calendarController, 'index']);
        $g->get('/calendario/eventos', [$calendarController, 'events']);
        $g->post('/citas/{id:[0-9]+}/estado', [$calendarController, 'changeStatus']);

        // ---- Servicios ----
        $g->get('/servicios', [$serviceController, 'index']);
        $g->get('/servicios/nuevo', [$serviceController, 'create']);
        $g->post('/servicios', [$serviceController, 'store']);
        $g->get('/servicios/{id:[0-9]+}/editar', [$serviceController, 'edit']);
        $g->post('/servicios/{id:[0-9]+}', [$serviceController, 'update']);
        $g->post('/servicios/{id:[0-9]+}/estado', [$serviceController, 'toggle']);

        // ---- Personal ----
        $g->get('/personal', [$employeeController, 'index']);
        $g->get('/personal/nuevo', [$employeeController, 'create']);
        $g->post('/personal', [$employeeController, 'store']);
        $g->get('/personal/{id:[0-9]+}/editar', [$employeeController, 'edit']);
        $g->post('/personal/{id:[0-9]+}', [$employeeController, 'update']);
        $g->post('/personal/{id:[0-9]+}/estado', [$employeeController, 'toggle']);
    })
        // El orden de los middleware importa. En Slim se ejecutan del
        // ULTIMO anadido al PRIMERO, asi que la cadena de entrada real es:
        //
        //   1. AuthMiddleware      -> sin sesion, redirige al login
        //   2. CsrfMiddleware      -> valida el token en POST
        //   3. $shareCurrentPath   -> deja datos listos para las vistas
        //
        // CSRF DESPUES de Auth es deliberado: a quien no ha iniciado sesion
        // se le manda al login en vez de darle un 419 incomprensible.
        ->add($shareCurrentPath)
        ->add(new CsrfMiddleware())
        ->add(new AuthMiddleware($auth, $basePath));

    // ===================================================================
    //  PUBLICO: flujo de reserva
    // ===================================================================
    $bookingController = new BookingController(
        $view,
        $catalog,
        $availability,
        $booking,
        $limiter,
        $notifications,
        $controllerSettings,
    );

    $appointmentController = new AppointmentController(
        $view,
        $appointments,
        $notifications,
        $controllerSettings,
    );

    [$bookingMax, $bookingWindow] = $settings['security']['rate_limit']['booking'];
    $trustProxy = (bool) $settings['security']['trust_proxy_headers'];

    // -------------------------------------------------------------------
    //  DOS LIMITES CON PROPOSITOS DISTINTOS
    // -------------------------------------------------------------------
    //  Este middleware es solo el ANTIFLOOD: corta a quien aporrea el
    //  endpoint, con un umbral holgado (4 veces el limite de reservas).
    //  Cuenta todo POST, valido o no.
    //
    //  El limite estricto de RESERVAS (el configurable, 5 cada 10 minutos)
    //  vive dentro de BookingController::store(), y solo se aplica cuando
    //  la validacion paso. Asi un cliente que se equivoca al escribir su
    //  correo no gasta intentos de reserva por corregirlo.
    // -------------------------------------------------------------------
    $bookingLimit = new RateLimitMiddleware(
        $limiter,
        'booking_flood',
        (int) $bookingMax * 4,
        (int) $bookingWindow,
        $trustProxy,
    );

    $app->group('/reservar', function (RouteCollectorProxy $g) use ($bookingController): void {
        $g->get('', [$bookingController, 'services']);
        $g->get('/', [$bookingController, 'services']);

        $g->get('/{service:[0-9]+}', [$bookingController, 'employees']);

        // {employee} acepta un id o la palabra "cualquiera".
        $g->get('/{service:[0-9]+}/{employee:[0-9]+|cualquiera}', [$bookingController, 'schedule']);
        $g->get(
            '/{service:[0-9]+}/{employee:[0-9]+|cualquiera}/disponibilidad',
            [$bookingController, 'availability'],
        );
        $g->post('/{service:[0-9]+}/{employee:[0-9]+|cualquiera}', [$bookingController, 'store']);
    })->add($bookingLimit);

    // -------------------------------------------------------------------
    //  PUBLICO: gestion de una cita por su token
    // -------------------------------------------------------------------
    // Bucket propio: agotar los intentos de reserva no debe impedirle a
    // nadie cancelar una cita que ya tiene, ni al reves.
    $cancelLimit = new RateLimitMiddleware($limiter, 'cancel', 10, 10, $trustProxy);

    $app->group('/cita', function (RouteCollectorProxy $g) use ($appointmentController): void {
        $g->get('/{token:[a-f0-9]{32}}', [$appointmentController, 'show']);
        $g->post('/{token:[a-f0-9]{32}}/cancelar', [$appointmentController, 'cancel']);
    })->add($cancelLimit);

    // La raiz lleva al formulario publico, que es lo que espera un visitante.
    // El panel esta en /admin y no se anuncia desde aqui.
    $app->get('/', static function (Request $request, Response $response) use ($basePath): Response {
        return $response->withHeader('Location', $basePath . '/reservar')->withStatus(302);
    });

    // -------------------------------------------------------------------
    //  Chequeo de salud
    // -------------------------------------------------------------------
    $app->get('/health', static function (Request $request, Response $response) use ($pdo, $settings): Response {
        $checks = [];

        try {
            $row = $pdo->query('SELECT VERSION() AS v, @@session.time_zone AS tz')->fetch();
            $checks['db'] = [
                'ok'        => true,
                'version'   => $row['v'],
                // Debe decir "+00:00". Si dice "SYSTEM", el INIT_COMMAND de
                // Database no se aplico y las fechas van a ir corridas.
                'time_zone' => $row['tz'],
            ];
        } catch (Throwable $e) {
            $checks['db'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        $checks['php']      = ['version' => PHP_VERSION, 'timezone' => date_default_timezone_get()];
        $checks['env']      = $settings['app']['env'];
        $checks['session']  = session_status() === PHP_SESSION_ACTIVE;
        $checks['writable'] = [
            'logs'  => is_writable($settings['storage_path'] . '/logs'),
            'cache' => is_writable($settings['storage_path'] . '/cache'),
        ];

        $response->getBody()->write(json_encode(
            $checks,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    });
};
