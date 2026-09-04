<?php

declare(strict_types=1);

/**
 * Ficha de la cita: sirve como confirmacion tras reservar y como pagina de
 * gestion cuando el cliente vuelve desde el correo.
 *
 * @var array<string,mixed> $cita
 * @var DateTimeZone        $tz
 * @var bool                $puedeCancelar
 * @var string|null         $motivoNoCancelable
 */

use App\Support\Html;

$base = $appConfig['base_path'] ?? '';
$utc  = new DateTimeZone('UTC');

$inicio = (new DateTimeImmutable((string) $cita['starts_at'], $utc))->setTimezone($tz);
$fin    = (new DateTimeImmutable((string) $cita['ends_at'], $utc))->setTimezone($tz);

$dias  = ['Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];
$meses = ['','enero','febrero','marzo','abril','mayo','junio','julio',
          'agosto','septiembre','octubre','noviembre','diciembre'];

$fechaLarga = sprintf(
    '%s %d de %s de %d',
    $dias[(int) $inicio->format('w')],
    (int) $inicio->format('j'),
    $meses[(int) $inicio->format('n')],
    (int) $inicio->format('Y'),
);

$estados = [
    'pending'   => ['Pendiente de confirmar', 'text-bg-warning'],
    'confirmed' => ['Confirmada',             'text-bg-success'],
    'completed' => ['Realizada',              'text-bg-secondary'],
    'cancelled' => ['Cancelada',              'text-bg-danger'],
    'no_show'   => ['No presentada',          'text-bg-danger'],
];

[$estadoTexto, $estadoClase] = $estados[$cita['status']] ?? ['—', 'text-bg-light'];

// El enlace absoluto es el que se manda por correo. Se muestra aqui para
// que el cliente pueda guardarlo antes de cerrar la pagina.
$enlace = rtrim((string) $appConfig['app_url'], '/') . $base . '/cita/' . $cita['public_token'];
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-7">

        <?php if ($cita['status'] !== 'cancelled'): ?>
            <div class="text-center mb-4">
                <div class="check-ok mb-3" aria-hidden="true">&#10003;</div>
                <h1 class="h4 mb-1">Tu cita esta <?= Html::e(mb_strtolower($estadoTexto)) ?></h1>
                <p class="text-body-secondary mb-0">
                    Te enviamos los detalles a <?= Html::e($cita['customer_email']) ?>.
                </p>
            </div>
        <?php else: ?>
            <div class="text-center mb-4">
                <h1 class="h4 mb-1">Cita cancelada</h1>
                <p class="text-body-secondary mb-0">
                    El horario volvio a quedar disponible para otras personas.
                </p>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="h5 mb-1"><?= Html::e($cita['service_name']) ?></div>
                        <div class="text-body-secondary small">
                            <?= Html::e($cita['business_name']) ?>
                        </div>
                    </div>
                    <span class="badge <?= Html::e($estadoClase) ?>"><?= Html::e($estadoTexto) ?></span>
                </div>

                <dl class="row mb-0 small">
                    <dt class="col-4 col-sm-3 text-body-secondary">Fecha</dt>
                    <dd class="col-8 col-sm-9"><?= Html::e($fechaLarga) ?></dd>

                    <dt class="col-4 col-sm-3 text-body-secondary">Hora</dt>
                    <dd class="col-8 col-sm-9">
                        <?= Html::e($inicio->format('H:i')) ?> a <?= Html::e($fin->format('H:i')) ?>
                        <span class="text-body-secondary">
                            (<?= (int) $cita['duration_minutes'] ?> minutos)
                        </span>
                    </dd>

                    <dt class="col-4 col-sm-3 text-body-secondary">Te atiende</dt>
                    <dd class="col-8 col-sm-9">
                        <?= Html::e($cita['employee_name']) ?>
                        <?php if (!empty($cita['employee_role'])): ?>
                            <span class="text-body-secondary">
                                · <?= Html::e($cita['employee_role']) ?>
                            </span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-4 col-sm-3 text-body-secondary">A nombre de</dt>
                    <dd class="col-8 col-sm-9"><?= Html::e($cita['customer_name']) ?></dd>

                    <dt class="col-4 col-sm-3 text-body-secondary">Precio</dt>
                    <dd class="col-8 col-sm-9">
                        <?= Html::e(number_format((float) $cita['price'], 2)) ?>
                        <?= Html::e($cita['currency']) ?>
                    </dd>

                    <?php if (!empty($cita['customer_notes'])): ?>
                        <dt class="col-4 col-sm-3 text-body-secondary">Tu nota</dt>
                        <dd class="col-8 col-sm-9"><?= Html::e($cita['customer_notes']) ?></dd>
                    <?php endif; ?>

                    <?php if ($cita['status'] === 'cancelled' && !empty($cita['cancel_reason'])): ?>
                        <dt class="col-4 col-sm-3 text-body-secondary">Motivo</dt>
                        <dd class="col-8 col-sm-9"><?= Html::e($cita['cancel_reason']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php // ---------------- Enlace de gestion ---------------- ?>
        <?php if ($cita['status'] !== 'cancelled'): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 mb-2">Guarda este enlace</h2>
                <p class="small text-body-secondary mb-2">
                    Es tu acceso a esta cita. No necesitas contrasena: quien tenga
                    el enlace puede consultarla o cancelarla, asi que no lo compartas.
                </p>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control font-monospace" readonly
                           value="<?= Html::e($enlace) ?>"
                           onfocus="this.select()" aria-label="Enlace de tu cita">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php // ---------------- Cancelacion ---------------- ?>
        <?php if ($puedeCancelar): ?>
            <div class="card shadow-sm border-danger-subtle">
                <div class="card-body">
                    <h2 class="h6 mb-2">Necesitas cancelar?</h2>
                    <p class="small text-body-secondary">
                        Si no puedes venir, cancelala para que otra persona
                        aproveche el horario.
                    </p>

                    <form method="post"
                          action="<?= Html::e($base) ?>/cita/<?= Html::e($cita['public_token']) ?>/cancelar"
                          onsubmit="return confirm('Seguro que quieres cancelar tu cita? No se puede deshacer.');">
                        <div class="mb-2">
                            <label for="reason" class="form-label small">
                                Motivo <span class="text-body-secondary">(opcional)</span>
                            </label>
                            <input type="text" class="form-control form-control-sm"
                                   id="reason" name="reason" maxlength="255"
                                   placeholder="Nos ayuda a mejorar">
                        </div>
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            Cancelar mi cita
                        </button>
                    </form>
                </div>
            </div>
        <?php elseif ($motivoNoCancelable !== null && $cita['status'] !== 'cancelled'): ?>
            <div class="alert alert-secondary small mb-0">
                <?= Html::e($motivoNoCancelable) ?>
                <?php if (!empty($cita['business_phone'])): ?>
                    <br>Telefono: <?= Html::e($cita['business_phone']) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($cita['status'] === 'cancelled'): ?>
            <div class="text-center mt-3">
                <a href="<?= Html::e($base) ?>/reservar" class="btn btn-primary">
                    Reservar otra cita
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
