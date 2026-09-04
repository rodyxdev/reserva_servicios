<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $business
 * @var list<array<string,mixed>> $employees
 * @var array<string,string>      $statuses
 */

use App\Support\Html;

$base = $appConfig['base_path'] ?? '';

// Configuracion que necesita el JavaScript. Se pasa como JSON en un
// data-attribute en vez de imprimir un <script> con variables sueltas: asi
// la CSP puede seguir prohibiendo scripts en linea.
$config = [
    'eventsUrl'  => $base . '/admin/calendario/eventos',
    'statusUrl'  => $base . '/admin/citas/:id/estado',
    'timezone'   => (string) $business['timezone'],
    'currency'   => (string) $business['currency'],
    'firstDay'   => 1,   // la semana empieza en lunes
    'slotMin'    => '07:00:00',
    'slotMax'    => '22:00:00',
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Calendario</h1>

    <div class="d-flex align-items-center gap-2">
        <label for="filtro-empleado" class="form-label mb-0 small text-body-secondary">
            Ver
        </label>
        <select id="filtro-empleado" class="form-select form-select-sm" style="width:auto">
            <option value="">Todo el personal</option>
            <?php foreach ($employees as $e): ?>
                <option value="<?= (int) $e['id'] ?>"><?= Html::e($e['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div id="calendario"
             data-config="<?= Html::attr(json_encode($config, JSON_THROW_ON_ERROR)) ?>"></div>
    </div>
</div>

<p class="text-body-secondary small mt-3 mb-0">
    Los bloques incluyen el buffer de limpieza, no solo la duracion del
    servicio: es el tiempo que la agenda esta realmente ocupada. Las citas
    canceladas se muestran en gris y ya no bloquean el horario.
</p>

<?php // ------------------- Modal de detalle ------------------- ?>
<div class="modal fade" id="modal-cita" tabindex="-1" aria-hidden="true"
     aria-labelledby="modal-cita-titulo">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modal-cita-titulo">Cita</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <dl class="row mb-3 small">
                    <dt class="col-4 text-body-secondary">Cliente</dt>
                    <dd class="col-8" data-field="customerName"></dd>

                    <dt class="col-4 text-body-secondary">Telefono</dt>
                    <dd class="col-8" data-field="customerPhone"></dd>

                    <dt class="col-4 text-body-secondary">Servicio</dt>
                    <dd class="col-8" data-field="serviceName"></dd>

                    <dt class="col-4 text-body-secondary">Atiende</dt>
                    <dd class="col-8" data-field="employeeName"></dd>

                    <dt class="col-4 text-body-secondary">Horario</dt>
                    <dd class="col-8" data-field="horario"></dd>

                    <dt class="col-4 text-body-secondary">Precio</dt>
                    <dd class="col-8" data-field="price"></dd>

                    <dt class="col-4 text-body-secondary">Estado</dt>
                    <dd class="col-8">
                        <span class="badge text-bg-secondary" data-field="statusLabel"></span>
                    </dd>
                </dl>

                <div data-field="acciones-wrap">
                    <label for="nota-estado" class="form-label small">
                        Nota <span class="text-body-secondary">(opcional, queda en la auditoria)</span>
                    </label>
                    <input type="text" id="nota-estado" class="form-control form-control-sm mb-3"
                           maxlength="255" placeholder="Motivo o comentario">

                    <div class="d-flex flex-wrap gap-2" data-field="acciones"></div>
                </div>

                <div class="alert alert-secondary small mb-0 d-none" data-field="sin-acciones">
                    Esta cita esta en un estado final: no admite mas cambios.
                </div>

                <div class="alert alert-danger small mt-3 mb-0 d-none" data-field="error"></div>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.15/locales/es.global.min.js"></script>
