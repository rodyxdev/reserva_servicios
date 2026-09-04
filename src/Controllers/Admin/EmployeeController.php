<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\EmployeeRepository;
use App\Models\ServiceRepository;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EmployeeController
{
    public function __construct(
        private readonly View $view,
        private readonly EmployeeRepository $employees,
        private readonly ServiceRepository $services,
        private readonly array $settings,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->html($response, 'admin/employees/index', [
            'title'     => 'Personal',
            'employees' => $this->employees->allForAdmin($this->businessId($request)),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $businessId = $this->businessId($request);

        return $this->html($response, 'admin/employees/form', [
            'title'      => 'Nuevo miembro del personal',
            'employee'   => $this->blank(),
            'services'   => $this->services->allForAdmin($businessId),
            'assigned'   => [],
            'hours'      => [],
            'errors'     => [],
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $businessId = $this->businessId($request);
        $id         = (int) $args['id'];
        $employee   = $this->employees->find($id, $businessId);

        if ($employee === null) {
            return $this->notFound($response);
        }

        return $this->html($response, 'admin/employees/form', [
            'title'    => 'Editar personal',
            'employee' => $employee,
            'services' => $this->services->allForAdmin($businessId),
            'assigned' => $this->employees->serviceIds($id),
            'hours'    => $this->employees->hours($id),
            'errors'   => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        return $this->save($request, $response, null);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->save($request, $response, (int) $args['id']);
    }

    private function save(Request $request, Response $response, ?int $id): Response
    {
        $businessId = $this->businessId($request);
        $body       = (array) ($request->getParsedBody() ?? []);
        $v          = new Validator($body);

        $data = [
            'name'       => $v->required('name', 'El nombre')->string('name', min: 2, max: 120),
            'email'      => ($body['email'] ?? '') !== '' ? $v->email('email') : null,
            'phone'      => ($body['phone'] ?? '') !== '' ? $v->phone('phone') : null,
            'role_title' => $v->string('role_title', max: 100),
            'is_active'  => $v->boolean('is_active'),
        ];

        $serviceIds = $this->parseServiceIds($body);
        $hours      = $this->parseHours($body, $v);

        if ($v->fails()) {
            return $this->html($response, 'admin/employees/form', [
                'title'    => $id === null ? 'Nuevo miembro del personal' : 'Editar personal',
                'employee' => array_merge($this->blank(), $body, ['id' => $id]),
                'services' => $this->services->allForAdmin($businessId),
                'assigned' => $serviceIds,
                'hours'    => $hours,
                'errors'   => $v->errors(),
            ], 422);
        }

        if ($id !== null && $this->employees->find($id, $businessId) === null) {
            return $this->notFound($response);
        }

        $this->employees->save($id, $businessId, $data, $serviceIds, $hours);

        Session::flash('success', $id === null ? 'Personal agregado.' : 'Datos actualizados.');

        return $response
            ->withHeader('Location', $this->base() . '/admin/personal')
            ->withStatus(303);
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $businessId = $this->businessId($request);
        $id         = (int) $args['id'];
        $employee   = $this->employees->find($id, $businessId);

        if ($employee === null) {
            return $this->notFound($response);
        }

        $activate = (int) $employee['is_active'] === 0;
        $this->employees->setActive($id, $businessId, $activate);

        if ($activate) {
            Session::flash('success', sprintf('%s vuelve a estar activo.', $employee['name']));
        } else {
            $pending = $this->employees->upcomingCount($id);

            Session::flash(
                $pending > 0 ? 'error' : 'success',
                sprintf(
                    '%s ya no aparece en el formulario publico.%s',
                    $employee['name'],
                    $pending > 0
                        ? sprintf(
                            ' Tiene %d cita%s futura%s que hay que reasignar a mano.',
                            $pending,
                            $pending === 1 ? '' : 's',
                            $pending === 1 ? '' : 's',
                        )
                        : '',
                ),
            );
        }

        return $response
            ->withHeader('Location', $this->base() . '/admin/personal')
            ->withStatus(303);
    }

    /**
     * Servicios marcados en el formulario.
     *
     * No se valida aqui que pertenezcan al negocio: de eso se encarga
     * EmployeeRepository::syncServices() con un INSERT ... SELECT filtrado
     * por business_id, que es donde no se puede olvidar.
     *
     * @param  array<string,mixed> $body
     * @return list<int>
     */
    private function parseServiceIds(array $body): array
    {
        $raw = $body['services'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);

            if ($id !== false && $id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Horario semanal enviado como hours[weekday][n][from|to].
     *
     * Las filas vacias se descartan en silencio: el formulario permite
     * dejar tramos sin rellenar y no tiene sentido convertir eso en error.
     *
     * @param  array<string,mixed> $body
     * @return array<int,list<array{starts_at:string,ends_at:string}>>
     */
    private function parseHours(array $body, Validator $v): array
    {
        $raw = $body['hours'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $weekday => $ranges) {
            $day = filter_var($weekday, FILTER_VALIDATE_INT);

            if ($day === false || $day < 1 || $day > 7 || !is_array($ranges)) {
                continue;
            }

            foreach ($ranges as $range) {
                if (!is_array($range)) {
                    continue;
                }

                $from = $this->normalizeTime($range['from'] ?? null);
                $to   = $this->normalizeTime($range['to'] ?? null);

                if ($from === null && $to === null) {
                    continue;   // fila vacia
                }

                if ($from === null || $to === null) {
                    $v->addError('hours', 'Hay un tramo horario incompleto.');
                    continue;
                }

                if ($to <= $from) {
                    $v->addError('hours', sprintf(
                        'En el dia %d, la hora de fin (%s) no es posterior a la de inicio (%s).',
                        $day,
                        $to,
                        $from,
                    ));
                    continue;
                }

                $result[$day][] = ['starts_at' => $from, 'ends_at' => $to];
            }
        }

        return $result;
    }

    /** Acepta "9:00" y "09:00", devuelve siempre "09:00". */
    private function normalizeTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }

        $h = (int) $m[1];
        $i = (int) $m[2];

        if ($h > 23 || $i > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $i);
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'id'         => null,
            'name'       => '',
            'email'      => '',
            'phone'      => '',
            'role_title' => '',
            'is_active'  => 1,
        ];
    }

    private function businessId(Request $request): int
    {
        return (int) $request->getAttribute('auth_user')['business_id'];
    }

    private function html(Response $response, string $tpl, array $data, int $status = 200): Response
    {
        $response->getBody()->write($this->view->render($tpl, $data, 'partials/layout-admin'));

        return $response->withStatus($status);
    }

    private function notFound(Response $response): Response
    {
        Session::flash('error', 'No se encontro a esa persona.');

        return $response
            ->withHeader('Location', $this->base() . '/admin/personal')
            ->withStatus(303);
    }

    private function base(): string
    {
        return (string) ($this->settings['base_path'] ?? '');
    }
}
