<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\ServiceRepository;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ServiceController
{
    public function __construct(
        private readonly View $view,
        private readonly ServiceRepository $services,
        private readonly array $settings,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $businessId = $this->businessId($request);

        return $this->html($response, 'admin/services/index', [
            'title'    => 'Servicios',
            'services' => $this->services->allForAdmin($businessId),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->html($response, 'admin/services/form', [
            'title'   => 'Nuevo servicio',
            'service' => $this->blank(),
            'errors'  => [],
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $service = $this->services->find((int) $args['id'], $this->businessId($request));

        if ($service === null) {
            return $this->notFound($response);
        }

        return $this->html($response, 'admin/services/form', [
            'title'   => 'Editar servicio',
            'service' => $service,
            'errors'  => [],
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
            'name'             => $v->required('name', 'El nombre')->string('name', min: 2, max: 150),
            'description'      => $v->string('description', max: 2000, singleLine: false),
            'duration_minutes' => $v->required('duration_minutes')->int('duration_minutes', min: 5, max: 1440),
            'buffer_minutes'   => $v->int('buffer_minutes', min: 0, max: 255) ?? 0,
            'price'            => $v->decimal('price', min: 0) ?? 0.0,
            'color'            => $v->hexColor('color') ?? '#0d6efd',
            'sort_order'       => $v->int('sort_order', min: 0, max: 9999) ?? 0,
            'is_active'        => $v->boolean('is_active'),
        ];

        // La duracion debe ser multiplo del bloque de la malla. Si no, los
        // slots de esa cita nunca cuadrarian con los de las demas y la
        // proteccion contra solapamientos dejaria huecos.
        $block = (int) $this->settings['slot_block_minutes'];

        if ($data['duration_minutes'] !== null && $data['duration_minutes'] % $block !== 0) {
            $v->addError('duration_minutes', sprintf('Debe ser multiplo de %d minutos.', $block));
        }

        if ($data['buffer_minutes'] % $block !== 0) {
            $v->addError('buffer_minutes', sprintf('Debe ser multiplo de %d minutos.', $block));
        }

        if ($v->fails()) {
            return $this->html($response, 'admin/services/form', [
                'title'   => $id === null ? 'Nuevo servicio' : 'Editar servicio',
                'service' => array_merge($this->blank(), $body, ['id' => $id]),
                'errors'  => $v->errors(),
            ], 422);
        }

        if ($id === null) {
            $this->services->create($businessId, $data);
            Session::flash('success', 'Servicio creado.');
        } else {
            if ($this->services->find($id, $businessId) === null) {
                return $this->notFound($response);
            }

            $this->services->update($id, $businessId, $data);
            Session::flash('success', 'Servicio actualizado.');
        }

        return $response
            ->withHeader('Location', $this->base() . '/admin/servicios')
            ->withStatus(303);
    }

    /**
     * Desactiva o reactiva. Nunca borra.
     *
     * Ver el comentario de ServiceRepository: la FK RESTRICT contra
     * appointments haria fallar un DELETE, y aunque no lo hiciera, se
     * perderia el historial.
     */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $businessId = $this->businessId($request);
        $id         = (int) $args['id'];
        $service    = $this->services->find($id, $businessId);

        if ($service === null) {
            return $this->notFound($response);
        }

        $activate = (int) $service['is_active'] === 0;
        $this->services->setActive($id, $businessId, $activate);

        if ($activate) {
            Session::flash('success', sprintf('"%s" vuelve a estar disponible.', $service['name']));
        } else {
            $pending = $this->services->upcomingCount($id);

            Session::flash('success', sprintf(
                '"%s" ya no se ofrece a nuevos clientes.%s',
                $service['name'],
                $pending > 0
                    ? sprintf(
                        ' Atencion: quedan %d cita%s futura%s ya reservada%s que siguen en pie.',
                        $pending,
                        $pending === 1 ? '' : 's',
                        $pending === 1 ? '' : 's',
                        $pending === 1 ? '' : 's',
                    )
                    : '',
            ));
        }

        return $response
            ->withHeader('Location', $this->base() . '/admin/servicios')
            ->withStatus(303);
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'id'               => null,
            'name'             => '',
            'description'      => '',
            'duration_minutes' => 60,
            'buffer_minutes'   => 0,
            'price'            => '0.00',
            'color'            => '#0d6efd',
            'sort_order'       => 0,
            'is_active'        => 1,
        ];
    }

    private function businessId(Request $request): int
    {
        $user = $request->getAttribute('auth_user');

        return (int) $user['business_id'];
    }

    private function html(Response $response, string $tpl, array $data, int $status = 200): Response
    {
        $response->getBody()->write($this->view->render($tpl, $data, 'partials/layout-admin'));

        return $response->withStatus($status);
    }

    private function notFound(Response $response): Response
    {
        Session::flash('error', 'No se encontro ese servicio.');

        return $response
            ->withHeader('Location', $this->base() . '/admin/servicios')
            ->withStatus(303);
    }

    private function base(): string
    {
        return (string) ($this->settings['base_path'] ?? '');
    }
}
