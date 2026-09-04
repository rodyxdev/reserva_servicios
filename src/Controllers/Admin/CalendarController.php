<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\AppointmentRepository;
use App\Models\CatalogRepository;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Calendario del panel y cambios de estado de las citas.
 */
final class CalendarController
{
    /**
     * Transiciones permitidas.
     *
     * Una maquina de estados explicita, no una lista de estados sueltos.
     * Sin esto se puede pasar de 'cancelled' a 'completed' con una peticion
     * manipulada, y el historial deja de significar nada.
     *
     * Las canceladas y las completadas son terminales: para revivir una
     * cancelada hay que crear una cita nueva, porque su horario ya se
     * libero y puede haberlo tomado otra persona.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['completed', 'cancelled', 'no_show'],
        'no_show'   => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    private const LABELS = [
        'pending'   => 'pendiente',
        'confirmed' => 'confirmada',
        'completed' => 'completada',
        'cancelled' => 'cancelada',
        'no_show'   => 'no se presento',
    ];

    public function __construct(
        private readonly View $view,
        private readonly AppointmentRepository $appointments,
        private readonly CatalogRepository $catalog,
        private readonly array $settings,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $businessId = $this->businessId($request);
        $business   = $this->catalog->business($businessId);

        $html = $this->view->render('admin/calendar', [
            'title'       => 'Calendario',
            'business'    => $business,
            'employees'   => $this->catalog->activeEmployees($businessId),
            'services'    => $this->catalog->activeServices($businessId),
            'statuses'    => self::LABELS,
            // El layout carga los scripts propios que declare la vista.
            'pageScripts' => ['calendar.js'],
        ], 'partials/layout-admin');

        $response->getBody()->write($html);

        return $response;
    }

    /**
     * Fuente de eventos para FullCalendar.
     *
     * FullCalendar pide el rango visible como start/end en ISO-8601 con
     * zona horaria. Se convierte a UTC para consultar, y las fechas de
     * vuelta se emiten en la zona del negocio con desplazamiento explicito
     * para que el navegador no las reinterprete en la zona del visitante:
     * un administrador de viaje debe seguir viendo la agenda en hora del
     * spa, no en la suya.
     */
    public function events(Request $request, Response $response): Response
    {
        $businessId = $this->businessId($request);
        $business   = $this->catalog->business($businessId);
        $tz         = new DateTimeZone((string) $business['timezone']);
        $utc        = new DateTimeZone('UTC');

        $query = $request->getQueryParams();

        try {
            $from = (new DateTimeImmutable((string) ($query['start'] ?? 'now')))->setTimezone($utc);
            $to   = (new DateTimeImmutable((string) ($query['end'] ?? '+30 days')))->setTimezone($utc);
        } catch (Throwable) {
            return $this->json($response, ['error' => 'Rango de fechas invalido.'], 400);
        }

        // Tope defensivo: una peticion con un rango de diez anos traeria
        // toda la tabla a memoria.
        if ($to->getTimestamp() - $from->getTimestamp() > 86400 * 400) {
            return $this->json($response, ['error' => 'Rango demasiado amplio.'], 400);
        }

        $employeeId = isset($query['employee_id']) && $query['employee_id'] !== ''
            ? (int) $query['employee_id']
            : null;

        $rows   = $this->appointments->betweenDates($businessId, $from, $to, $employeeId);
        $events = [];

        foreach ($rows as $row) {
            $start = (new DateTimeImmutable((string) $row['starts_at'], $utc))->setTimezone($tz);
            $end   = (new DateTimeImmutable((string) $row['ends_at'], $utc))->setTimezone($tz);
            $block = (new DateTimeImmutable((string) $row['blocked_until'], $utc))->setTimezone($tz);

            $cancelled = $row['status'] === 'cancelled';

            $events[] = [
                'id'              => (string) $row['id'],
                'title'           => $row['customer_name'] . ' - ' . $row['service_name'],
                'start'           => $start->format('c'),
                // Se pinta hasta blocked_until, no hasta ends_at: el buffer
                // ocupa la agenda de verdad, y si no se ve, el personal cree
                // que hay un hueco donde no lo hay.
                'end'             => $block->format('c'),
                'backgroundColor' => $cancelled ? '#adb5bd' : (string) $row['color'],
                'borderColor'     => $cancelled ? '#6c757d' : (string) $row['color'],
                'classNames'      => $cancelled ? ['appt-cancelled'] : [],
                'extendedProps'   => [
                    'status'        => $row['status'],
                    'statusLabel'   => self::LABELS[$row['status']] ?? $row['status'],
                    'customerName'  => $row['customer_name'],
                    'customerPhone' => $row['customer_phone'],
                    'serviceName'   => $row['service_name'],
                    'employeeName'  => $row['employee_name'],
                    'price'         => number_format((float) $row['price'], 2),
                    // Las horas van ya formateadas en la zona del negocio.
                    // El cliente NO las recalcula: FullCalendar entrega
                    // objetos Date desplazados (sus campos UTC representan
                    // la hora local), asi que volver a convertirlos en el
                    // navegador aplica el desfase dos veces.
                    'serviceStart'  => $start->format('H:i'),
                    'serviceEnd'    => $end->format('H:i'),
                    'blockedEnd'    => $block->format('H:i'),
                    'hasBuffer'     => $block > $end,
                    'transitions'   => self::TRANSITIONS[$row['status']] ?? [],
                    'labels'        => self::LABELS,
                ],
            ];
        }

        return $this->json($response, $events);
    }

    /**
     * Cambia el estado de una cita.
     *
     * El registro en appointment_status_log lo hace
     * AppointmentRepository::changeStatus() dentro de la misma transaccion
     * que el UPDATE: o se guardan los dos, o ninguno. Una auditoria que
     * puede quedar incompleta no sirve como auditoria.
     */
    public function changeStatus(Request $request, Response $response, array $args): Response
    {
        $businessId  = $this->businessId($request);
        $userId      = (int) $request->getAttribute('auth_user')['id'];
        $id          = (int) $args['id'];
        $body        = (array) ($request->getParsedBody() ?? []);

        $appointment = $this->appointments->findById($id, $businessId);

        if ($appointment === null) {
            return $this->json($response, ['ok' => false, 'error' => 'Cita no encontrada.'], 404);
        }

        $current = (string) $appointment['status'];
        $target  = is_string($body['status'] ?? null) ? $body['status'] : '';

        if (!in_array($target, self::TRANSITIONS[$current] ?? [], true)) {
            return $this->json($response, [
                'ok'    => false,
                'error' => sprintf(
                    'No se puede pasar de "%s" a "%s".',
                    self::LABELS[$current] ?? $current,
                    self::LABELS[$target] ?? $target,
                ),
            ], 422);
        }

        $note = is_string($body['note'] ?? null) && trim($body['note']) !== ''
            ? mb_substr(trim($body['note']), 0, 255)
            : null;

        try {
            $changed = $this->appointments->changeStatus(
                $id,
                $target,
                $userId,
                $note,
                $target === 'cancelled' ? 'staff' : null,
            );
        } catch (Throwable $e) {
            error_log('[calendar] fallo al cambiar estado: ' . $e->getMessage());

            return $this->json($response, [
                'ok'    => false,
                'error' => 'No se pudo actualizar la cita.',
            ], 500);
        }

        if (!$changed) {
            return $this->json($response, [
                'ok'    => false,
                'error' => 'La cita ya estaba en ese estado.',
            ], 409);
        }

        $message = sprintf('Cita marcada como %s.', self::LABELS[$target] ?? $target);

        if ($target === 'cancelled') {
            $message .= ' El horario vuelve a estar disponible.';
        }

        // Sin Session::flash aqui a proposito: este endpoint responde JSON a
        // una peticion de fetch(), y el calendario ya muestra el resultado.
        // Dejar un flash lo haria aparecer despues, descolgado, en la
        // siguiente pagina HTML que el usuario abriera.
        return $this->json($response, [
            'ok'          => true,
            'status'      => $target,
            'statusLabel' => self::LABELS[$target] ?? $target,
            'transitions' => self::TRANSITIONS[$target] ?? [],
            'message'     => $message,
        ]);
    }

    /** Agenda del dia en la portada del panel. */
    public function dashboard(Request $request, Response $response): Response
    {
        $businessId = $this->businessId($request);
        $business   = $this->catalog->business($businessId);
        $tz         = new DateTimeZone((string) $business['timezone']);
        $utc        = new DateTimeZone('UTC');

        // "Hoy" es el dia LOCAL del negocio, que en UTC son dos fechas
        // distintas. Se construye el rango en local y se convierte.
        $startLocal = new DateTimeImmutable('today', $tz);
        $endLocal   = $startLocal->modify('+1 day');

        $today = $this->appointments->betweenDates(
            $businessId,
            $startLocal->setTimezone($utc),
            $endLocal->setTimezone($utc),
        );

        $upcoming = $this->appointments->betweenDates(
            $businessId,
            $endLocal->setTimezone($utc),
            $endLocal->modify('+7 days')->setTimezone($utc),
        );

        $html = $this->view->render('admin/dashboard', [
            'title'    => 'Resumen',
            'business' => $business,
            'today'    => $today,
            'upcoming' => array_slice($upcoming, 0, 10),
            'tz'       => $tz,
            'statuses' => self::LABELS,
        ], 'partials/layout-admin');

        $response->getBody()->write($html);

        return $response;
    }

    private function businessId(Request $request): int
    {
        return (int) $request->getAttribute('auth_user')['business_id'];
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
}
