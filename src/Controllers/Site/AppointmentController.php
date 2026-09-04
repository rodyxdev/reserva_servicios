<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Models\AppointmentRepository;
use App\Services\NotificationService;
use App\Support\Session;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Consulta y cancelacion de una cita por su token publico, sin login.
 *
 * ------------------------------------------------------------------
 *  POR QUE UN TOKEN Y NO EL ID
 * ------------------------------------------------------------------
 *  Con /cita/57 cualquiera recorre los numeros y ve, o cancela, las
 *  citas de todos los demas. El token son 16 bytes de random_bytes()
 *  en hexadecimal: 128 bits de entropia, imposible de adivinar por
 *  fuerza bruta y sin relacion con el orden de creacion.
 *
 *  Es la misma idea que un enlace de "restablecer contrasena": conocer
 *  la URL ES la autorizacion. Por eso el token no se muestra en ninguna
 *  pagina indexable, viaja solo en el correo del cliente, y la cabecera
 *  Referrer-Policy impide que se filtre al navegar a un dominio externo.
 * ------------------------------------------------------------------
 */
final class AppointmentController
{
    /** Estados desde los que el cliente puede cancelar por su cuenta. */
    private const CANCELABLE = ['pending', 'confirmed'];

    public function __construct(
        private readonly View $view,
        private readonly AppointmentRepository $appointments,
        private readonly NotificationService $notifications,
        private readonly array $settings,
    ) {
    }

    /**
     * Ficha de la cita. Sirve de pagina de confirmacion tras reservar y de
     * pagina de gestion cuando el cliente vuelve desde el correo.
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $cita = $this->find((string) $args['token']);

        if ($cita === null) {
            return $this->notFound($response);
        }

        [$estado, $motivo] = $this->cancelabilidad($cita);

        return $this->html($response, 'site/appointment', [
            'title'              => 'Tu cita',
            'cita'               => $cita,
            'tz'                 => new DateTimeZone((string) $cita['business_timezone']),
            'puedeCancelar'      => $estado,
            'motivoNoCancelable' => $motivo,
            // La cabecera y el pie del layout necesitan los datos del
            // negocio. Se toman de la propia cita en vez de volver a
            // consultarlos: findByToken ya los trajo en el mismo JOIN.
            'business' => [
                'name'     => $cita['business_name'],
                'phone'    => $cita['business_phone'],
                'timezone' => $cita['business_timezone'],
                'currency' => $cita['currency'],
            ],
        ]);
    }

    /**
     * Cancelacion por el propio cliente.
     *
     * AppointmentRepository::changeStatus() hace las tres cosas en una
     * transaccion: cambia el estado, BORRA los bloques de
     * appointment_slots (el horario vuelve al mercado en el acto) y deja
     * la fila en appointment_status_log con changed_by_user_id NULL, que
     * es como se distingue una cancelacion del cliente de una del
     * personal.
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];
        $cita  = $this->find($token);

        if ($cita === null) {
            return $this->notFound($response);
        }

        [$puede, $motivo] = $this->cancelabilidad($cita);

        if (!$puede) {
            Session::start();
            Session::flash('error', $motivo ?? 'Esta cita ya no se puede cancelar.');

            return $this->redirect($response, '/cita/' . $token);
        }

        $body   = (array) ($request->getParsedBody() ?? []);
        $razon  = is_string($body['reason'] ?? null) && trim($body['reason']) !== ''
            ? mb_substr(trim($body['reason']), 0, 255)
            : null;

        try {
            $this->appointments->changeStatus(
                (int) $cita['id'],
                'cancelled',
                // NULL: no lo cancelo un usuario del panel, sino el cliente
                // desde su enlace. Es lo que permite despues distinguir en
                // la auditoria quien hizo que.
                userId: null,
                note: $razon !== null
                    ? 'Cancelada por el cliente: ' . $razon
                    : 'Cancelada por el cliente desde su enlace',
                cancelledBy: 'customer',
            );
        } catch (Throwable $e) {
            error_log('[cancelacion] ' . $e->getMessage());

            Session::start();
            Session::flash('error', 'No pudimos cancelar la cita. Intentalo de nuevo.');

            return $this->redirect($response, '/cita/' . $token);
        }

        // El aviso de cancelacion se ENCOLA primero y se intenta enviar
        // despues. Si el SMTP esta caido, la cancelacion ya esta hecha (que
        // es lo que el cliente necesita) y el correo saldra con el cron.
        try {
            $this->notifications->queueCancellation((int) $cita['id']);
            $this->notifications->flushFor((int) $cita['id']);
        } catch (Throwable $e) {
            error_log('[cancelacion] no se pudo avisar por correo: ' . $e->getMessage());
        }

        Session::start();
        Session::flash('success', 'Tu cita quedo cancelada. El horario vuelve a estar disponible.');

        return $this->redirect($response, '/cita/' . $token);
    }

    // =================================================================

    /** @return array<string,mixed>|null */
    private function find(string $token): ?array
    {
        // Se valida la forma antes de consultar: el token es exactamente
        // 32 caracteres hexadecimales. Cualquier otra cosa ni llega a la
        // base de datos.
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        return $this->appointments->findByToken($token);
    }

    /**
     * Decide si el cliente puede cancelar, y si no, por que.
     *
     * @param  array<string,mixed> $cita
     * @return array{0:bool,1:?string}
     */
    private function cancelabilidad(array $cita): array
    {
        if (!in_array($cita['status'], self::CANCELABLE, true)) {
            return [false, match ($cita['status']) {
                'cancelled' => 'Esta cita ya estaba cancelada.',
                'completed' => 'Esta cita ya se realizo.',
                'no_show'   => 'Esta cita figura como no presentada. Llama al negocio si crees que hay un error.',
                default     => 'Esta cita ya no se puede cancelar.',
            }];
        }

        $utc    = new DateTimeZone('UTC');
        $inicio = new DateTimeImmutable((string) $cita['starts_at'], $utc);
        $ahora  = new DateTimeImmutable('now', $utc);

        if ($inicio <= $ahora) {
            return [false, 'Esta cita ya paso. Si no pudiste asistir, llama al negocio.'];
        }

        // ---------------------------------------------------------------
        //  Margen minimo de cancelacion
        // ---------------------------------------------------------------
        //  Es un valor PROPIO, no min_advance_minutes.
        //
        //  El primer intento fue reutilizar min_advance_minutes: si no se
        //  acepta una reserva con menos de dos horas de antelacion, parecia
        //  logico no permitir cancelar dentro de esas mismas dos horas.
        //
        //  La prueba lo tumbo. Con los dos valores iguales, quien reserva
        //  justo en el limite (a las 15:30 para las 17:30) no puede cancelar
        //  NUNCA: en el instante siguiente a confirmar ya esta dentro de la
        //  ventana cerrada. Se queda atrapado con una cita que quiza acaba
        //  de crear por error.
        //
        //  Por defecto es mas corto que el de reserva (60 contra 120), de
        //  modo que siempre existe un intervalo real en el que el cliente
        //  puede rectificar.
        // ---------------------------------------------------------------
        $margen = (int) ($this->settings['security']['cancel_min_notice_minutes'] ?? 60);

        if ($margen > 0 && $inicio < $ahora->modify(sprintf('+%d minutes', $margen))) {
            $horas = round($margen / 60, 1);

            return [false, sprintf(
                'Faltan menos de %s horas para tu cita, asi que ya no se puede cancelar por aqui. '
                . 'Llama al negocio y lo resolvemos.',
                rtrim(rtrim(number_format($horas, 1, ',', ''), '0'), ','),
            )];
        }

        return [true, null];
    }

    private function html(Response $response, string $tpl, array $data, int $status = 200): Response
    {
        $data['business'] ??= [
            'name'     => $this->settings['app']['name'],
            'timezone' => 'UTC',
        ];
        $data['paso'] ??= 0;

        $response->getBody()->write($this->view->render($tpl, $data, 'partials/layout-site'));

        return $response->withStatus($status);
    }

    /**
     * Un token que no existe da 404, igual que uno mal formado.
     *
     * Deliberadamente NO se distingue entre "no existe" y "existe pero no
     * es tuyo": eso convertiria la pagina en un oraculo para comprobar
     * tokens a ciegas.
     */
    private function notFound(Response $response): Response
    {
        return $this->html($response, 'site/error', [
            'title'   => 'Cita no encontrada',
            'mensaje' => 'No encontramos ninguna cita con ese enlace. '
                . 'Revisa que lo hayas copiado completo desde el correo de confirmacion.',
        ], 404);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response
            ->withHeader('Location', ($this->settings['base_path'] ?? '') . $path)
            ->withStatus(303);
    }
}
