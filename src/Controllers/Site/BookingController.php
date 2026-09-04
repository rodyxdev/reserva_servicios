<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Models\CatalogRepository;
use App\Services\AppointmentService;
use App\Services\AvailabilityService;
use App\Services\BookingException;
use App\Services\NotificationService;
use App\Support\RateLimiter;
use App\Support\Validator;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Flujo publico de reserva, en tres pasos.
 *
 *   1. /reservar                      -> elegir servicio
 *   2. /reservar/{servicio}           -> elegir profesional (o "sin preferencia")
 *   3. /reservar/{servicio}/{quien}   -> elegir dia y hora, y datos de contacto
 *
 * Cada paso es una URL propia con estado en la ruta, no en la sesion. Es
 * mas trabajo que guardar un array en $_SESSION, pero a cambio el cliente
 * puede usar el boton "atras" del navegador, recargar sin perder nada y
 * compartir el enlace de un servicio concreto. Un wizard que se rompe al
 * pulsar "atras" es de los motivos mas tontos por los que se pierde una
 * reserva.
 */
final class BookingController
{
    /** Valor que representa "cualquier profesional" en la URL. */
    private const ANY = 'cualquiera';

    /** Dias que se muestran de una vez en el paso 3. */
    private const WINDOW_DAYS = 14;

    public function __construct(
        private readonly View $view,
        private readonly CatalogRepository $catalog,
        private readonly AvailabilityService $availability,
        private readonly AppointmentService $booking,
        private readonly RateLimiter $limiter,
        private readonly NotificationService $notifications,
        private readonly array $settings,
    ) {
    }

    // =================================================================
    //  PASO 1: servicios
    // =================================================================

    public function services(Request $request, Response $response): Response
    {
        $business = $this->business();
        $servicios = $this->catalog->activeServices((int) $business['id']);

        // Un servicio que nadie presta no puede reservarse. Mostrarlo solo
        // llevaria al cliente a un paso 2 vacio.
        $servicios = array_values(array_filter(
            $servicios,
            fn (array $s): bool => $this->catalog->employeesForService(
                (int) $s['id'],
                (int) $business['id'],
            ) !== [],
        ));

        return $this->html($response, 'site/services', [
            'title'     => 'Reservar una cita',
            'business'  => $business,
            'servicios' => $servicios,
            'paso'      => 1,
        ]);
    }

    // =================================================================
    //  PASO 2: profesional
    // =================================================================

    public function employees(Request $request, Response $response, array $args): Response
    {
        $business = $this->business();
        $servicio = $this->catalog->service((int) $args['service'], (int) $business['id']);

        if ($servicio === null) {
            return $this->notFound($response, 'Ese servicio ya no esta disponible.');
        }

        $empleados = $this->catalog->employeesForService(
            (int) $servicio['id'],
            (int) $business['id'],
        );

        if ($empleados === []) {
            return $this->notFound($response, 'Ese servicio no tiene personal asignado ahora mismo.');
        }

        return $this->html($response, 'site/employees', [
            'title'     => 'Elegir profesional',
            'business'  => $business,
            'servicio'  => $servicio,
            'empleados' => $empleados,
            'any'       => self::ANY,
            'paso'      => 2,
        ]);
    }

    // =================================================================
    //  PASO 3: dia, hora y datos
    // =================================================================

    public function schedule(Request $request, Response $response, array $args): Response
    {
        $business = $this->business();
        $servicio = $this->catalog->service((int) $args['service'], (int) $business['id']);

        if ($servicio === null) {
            return $this->notFound($response, 'Ese servicio ya no esta disponible.');
        }

        $quien     = (string) $args['employee'];
        $empleados = $this->catalog->employeesForService((int) $servicio['id'], (int) $business['id']);
        $empleado  = null;

        if ($quien !== self::ANY) {
            foreach ($empleados as $e) {
                if ((int) $e['id'] === (int) $quien) {
                    $empleado = $e;
                    break;
                }
            }

            if ($empleado === null) {
                return $this->notFound($response, 'Ese profesional no ofrece el servicio elegido.');
            }
        }

        return $this->html($response, 'site/schedule', [
            'title'     => 'Elegir fecha y hora',
            'business'  => $business,
            'servicio'  => $servicio,
            'empleado'  => $empleado,
            'quien'     => $quien,
            'errors'    => [],
            'old'       => [],
            'paso'      => 3,
            'cancelNotice' => (int) $this->settings['security']['cancel_min_notice_minutes'],
            'pageScripts' => ['booking.js'],
        ]);
    }

    /**
     * Huecos disponibles, en JSON, para el paso 3.
     *
     * Es el unico endpoint publico que consulta disponibilidad. Devuelve
     * una ventana de dias completa en una sola peticion en vez de un dia
     * por peticion: el cliente puede recorrer las fechas sin esperar a la
     * red en cada clic.
     */
    public function availability(Request $request, Response $response, array $args): Response
    {
        $business = $this->business();
        $servicio = $this->catalog->service((int) $args['service'], (int) $business['id']);

        if ($servicio === null) {
            return $this->json($response, ['ok' => false, 'error' => 'Servicio no disponible.'], 404);
        }

        $tz    = new DateTimeZone((string) $business['timezone']);
        $query = $request->getQueryParams();

        // El "desde" nunca puede ser anterior a hoy: aceptarlo permitiria
        // sondear la agenda del pasado, que no aporta nada al cliente y si
        // expone el historial del negocio.
        $hoy = new DateTimeImmutable('today', $tz);

        try {
            $desde = isset($query['desde']) && is_string($query['desde']) && $query['desde'] !== ''
                ? new DateTimeImmutable($query['desde'] . ' 00:00:00', $tz)
                : $hoy;
        } catch (Throwable) {
            return $this->json($response, ['ok' => false, 'error' => 'Fecha invalida.'], 400);
        }

        if ($desde < $hoy) {
            $desde = $hoy;
        }

        $limite = $hoy->modify(sprintf('+%d days', (int) $business['max_advance_days']));

        if ($desde > $limite) {
            return $this->json($response, ['ok' => true, 'dias' => [], 'fin' => true]);
        }

        $quien = (string) $args['employee'];

        if ($quien === self::ANY) {
            $ids = array_map(
                static fn (array $e): int => (int) $e['id'],
                $this->catalog->employeesForService((int) $servicio['id'], (int) $business['id']),
            );

            $porDia = $this->availability->slotsForRangeAnyEmployee(
                $ids,
                $business,
                $servicio,
                $desde,
                self::WINDOW_DAYS,
            );
        } else {
            if (!$this->catalog->employeeOffersService((int) $quien, (int) $servicio['id'], (int) $business['id'])) {
                return $this->json($response, ['ok' => false, 'error' => 'Profesional no valido.'], 404);
            }

            $porDia = $this->availability->slotsForRange(
                $business,
                (int) $quien,
                $servicio,
                $desde,
                self::WINDOW_DAYS,
            );
        }

        // Se emite la hora LOCAL para mostrar y el instante UTC para
        // enviar de vuelta. El navegador nunca convierte nada: solo copia
        // el valor que le dio el servidor. Es la misma leccion que costo
        // seis horas de desfase en el calendario del panel.
        $dias = [];

        foreach ($porDia as $fecha => $slots) {
            $dias[] = [
                'fecha'     => $fecha,
                'etiqueta'  => $this->diaLegible($fecha, $tz),
                'horarios'  => array_map(
                    static fn (DateTimeImmutable $s): array => [
                        'utc'   => $s->format('Y-m-d H:i:s'),
                        'local' => $s->setTimezone($tz)->format('H:i'),
                    ],
                    $slots,
                ),
            ];
        }

        return $this->json($response, [
            'ok'        => true,
            'dias'      => $dias,
            'siguiente' => $desde->modify(sprintf('+%d days', self::WINDOW_DAYS))->format('Y-m-d'),
            'anterior'  => $desde->modify(sprintf('-%d days', self::WINDOW_DAYS))->format('Y-m-d'),
            'hayAnterior' => $desde > $hoy,
            'fin'       => $desde->modify(sprintf('+%d days', self::WINDOW_DAYS)) > $limite,
        ]);
    }

    // =================================================================
    //  CONFIRMACION
    // =================================================================

    public function store(Request $request, Response $response, array $args): Response
    {
        $business = $this->business();
        $body     = (array) ($request->getParsedBody() ?? []);
        $v        = new Validator($body);

        // -----------------------------------------------------------------
        //  HONEYPOT
        // -----------------------------------------------------------------
        //  El formulario lleva un campo "website" oculto por CSS. Una
        //  persona no lo ve nunca; los bots que rellenan todo lo que
        //  encuentran, si.
        //
        //  Cuando viene relleno se responde EXACTAMENTE igual que en un
        //  envio correcto: misma pagina, mismo aspecto, mismo codigo HTTP.
        //  Lo unico que no ocurre es la escritura en la base.
        //
        //  Es deliberado no mostrar un error. Si al bot se le dice que fue
        //  detectado, quien lo opera ajusta el script y vuelve a intentarlo
        //  hasta dar con la forma de pasar. Si cree que funciono, sigue
        //  mandando reservas a un agujero negro y nadie toca nada.
        // -----------------------------------------------------------------
        if ($v->isHoneypotFilled('website')) {
            return $this->fakeConfirmation($response, $business, $body);
        }

        $servicio = $this->catalog->service((int) $args['service'], (int) $business['id']);

        if ($servicio === null) {
            return $this->notFound($response, 'Ese servicio ya no esta disponible.');
        }

        $quien      = (string) $args['employee'];
        $employeeId = $quien === self::ANY ? null : (int) $quien;

        // ---- Validacion de los datos del cliente ------------------------
        $nombre   = $v->required('name', 'El nombre')->string('name', min: 2, max: 120);
        $email    = $v->required('email', 'El correo')->email('email');
        $telefono = $v->required('phone', 'El telefono')->phone('phone');
        $notas    = $v->string('notes', max: 1000, singleLine: false);

        $inicioRaw = is_string($body['starts_at'] ?? null) ? trim($body['starts_at']) : '';
        $inicio    = null;

        if ($inicioRaw === '') {
            $v->addError('starts_at', 'Elige un horario antes de confirmar.');
        } else {
            try {
                $inicio = new DateTimeImmutable($inicioRaw, new DateTimeZone('UTC'));
            } catch (Throwable) {
                $v->addError('starts_at', 'El horario elegido no es valido.');
            }
        }

        if (!$v->boolean('accept')) {
            $v->addError('accept', 'Debes aceptar la politica de cancelacion.');
        }

        if ($v->fails() || $inicio === null) {
            return $this->backToSchedule($response, $business, $servicio, $quien, $v->errors(), $body);
        }

        // -----------------------------------------------------------------
        //  LIMITE ESTRICTO DE RESERVAS
        // -----------------------------------------------------------------
        //  Se aplica AQUI, despues de validar, y no en el middleware.
        //
        //  El motivo lo destapo la prueba: con el limite en el middleware,
        //  cada envio con un error de formulario gastaba un intento. Alguien
        //  que se equivoca al escribir su correo tres veces se quedaba sin
        //  poder reservar durante diez minutos, sin haber hecho nada malo.
        //
        //  Lo que hay que limitar de verdad son las RESERVAS, no los
        //  formularios mal rellenados. Un envio invalido no escribe en la
        //  base ni manda correos: es barato, y de las avalanchas se encarga
        //  el limite antiflood del middleware, mucho mas holgado.
        // -----------------------------------------------------------------
        [$max, $ventana] = $this->settings['security']['rate_limit']['booking'];

        $ip = RateLimiter::clientIp(
            $request,
            (bool) $this->settings['security']['trust_proxy_headers'],
        );

        if (!$this->limiter->attempt('booking', $ip, (int) $max, (int) $ventana)) {
            return $this->backToSchedule(
                $response,
                $business,
                $servicio,
                $quien,
                ['starts_at' => sprintf(
                    'Has hecho varias reservas seguidas. Espera %d minutos antes de crear otra, '
                    . 'o llamanos por telefono si necesitas algo urgente.',
                    (int) $ventana,
                )],
                $body,
            );
        }

        // ---- Reserva -----------------------------------------------------
        try {
            $cita = $this->booking->book(
                businessId:    (int) $business['id'],
                serviceId:     (int) $servicio['id'],
                employeeId:    $employeeId,
                startUtc:      $inicio,
                customerName:  $nombre,
                customerEmail: $email,
                customerPhone: $telefono,
                notes:         $notas,
                source:        'public',
            );
        } catch (BookingException $e) {
            // Errores que el cliente puede corregir: se le devuelve al
            // paso 3 con el motivo, no a una pagina de error generica.
            return $this->backToSchedule(
                $response,
                $business,
                $servicio,
                $quien,
                ['starts_at' => $e->getMessage()],
                $body,
            );
        } catch (Throwable $e) {
            error_log('[reserva] fallo inesperado: ' . $e->getMessage());

            return $this->backToSchedule(
                $response,
                $business,
                $servicio,
                $quien,
                ['starts_at' => 'No pudimos completar la reserva. Intentalo de nuevo en unos minutos.'],
                $body,
            );
        }

        // -----------------------------------------------------------------
        //  Correos
        // -----------------------------------------------------------------
        //  AppointmentService::book() ya encolo la confirmacion dentro de
        //  su transaccion. Aqui solo se intenta enviarla ya mismo.
        //
        //  Va envuelto en try/catch y DESPUES del commit a proposito: la
        //  cita ya existe y es valida. Si el SMTP esta caido, el cliente no
        //  tiene por que enterarse ni ver un error: su reserva esta hecha,
        //  la fila sigue en 'pending' y el cron la enviara en cuanto vuelva
        //  el correo. Dejar que una excepcion de red rompa esta peticion
        //  seria convertir un problema nuestro en un problema suyo.
        // -----------------------------------------------------------------
        try {
            $this->notifications->flushFor($cita['id']);
            $this->notifications->notifyBusinessFor($cita['id']);
        } catch (Throwable $e) {
            error_log('[reserva] la cita ' . $cita['id'] . ' se creo pero fallo el correo: '
                . $e->getMessage());
        }

        // Patron POST-Redirect-GET: sin la redireccion, recargar la pagina
        // de confirmacion reenviaria el formulario y crearia una cita
        // duplicada (la de las 10:15, porque las 10:00 ya estarian tomadas).
        return $response
            ->withHeader('Location', $this->base() . '/cita/' . $cita['token'])
            ->withStatus(303);
    }

    // =================================================================
    //  Auxiliares
    // =================================================================

    /**
     * Respuesta al honeypot: identica a la real, pero sin escribir nada.
     *
     * Se pinta la pagina de confirmacion con los datos que envio el bot y
     * un token aleatorio que no existe en la base. Si alguna vez lo visita,
     * recibira el mismo 404 que cualquier token inventado.
     */
    private function fakeConfirmation(Response $response, array $business, array $body): Response
    {
        return $response
            ->withHeader('Location', $this->base() . '/cita/' . bin2hex(random_bytes(16)))
            ->withStatus(303);
    }

    /** @param array<string,string> $errors */
    private function backToSchedule(
        Response $response,
        array $business,
        array $servicio,
        string $quien,
        array $errors,
        array $old,
    ): Response {
        $empleado = null;

        if ($quien !== self::ANY) {
            foreach ($this->catalog->employeesForService((int) $servicio['id'], (int) $business['id']) as $e) {
                if ((int) $e['id'] === (int) $quien) {
                    $empleado = $e;
                    break;
                }
            }
        }

        return $this->html($response, 'site/schedule', [
            'title'       => 'Elegir fecha y hora',
            'business'    => $business,
            'servicio'    => $servicio,
            'empleado'    => $empleado,
            'quien'       => $quien,
            'errors'      => $errors,
            'old'         => $old,
            'paso'        => 3,
            'cancelNotice' => (int) $this->settings['security']['cancel_min_notice_minutes'],
            'pageScripts' => ['booking.js'],
        ], 422);
    }

    /** "Lunes 7 de septiembre" a partir de un Y-m-d. */
    private function diaLegible(string $fecha, DateTimeZone $tz): string
    {
        static $dias  = ['Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];
        static $meses = ['','enero','febrero','marzo','abril','mayo','junio','julio',
                         'agosto','septiembre','octubre','noviembre','diciembre'];

        $d = new DateTimeImmutable($fecha . ' 00:00:00', $tz);

        return sprintf(
            '%s %d de %s',
            $dias[(int) $d->format('w')],
            (int) $d->format('j'),
            $meses[(int) $d->format('n')],
        );
    }

    /** @return array<string,mixed> */
    private function business(): array
    {
        $id = (int) $this->settings['app']['business_id'];

        return $this->catalog->business($id)
            ?? throw new \RuntimeException('El negocio configurado no existe o esta inactivo.');
    }

    private function html(Response $response, string $tpl, array $data, int $status = 200): Response
    {
        $response->getBody()->write($this->view->render($tpl, $data, 'partials/layout-site'));

        return $response->withStatus($status);
    }

    private function json(Response $response, mixed $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    private function notFound(Response $response, string $mensaje): Response
    {
        return $this->html($response, 'site/error', [
            'title'    => 'No encontrado',
            'business' => $this->business(),
            'mensaje'  => $mensaje,
            'paso'     => 0,
        ], 404);
    }

    private function base(): string
    {
        return (string) ($this->settings['base_path'] ?? '');
    }
}
