<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>                                    $employee
 * @var list<array<string,mixed>>                              $services
 * @var list<int>                                              $assigned
 * @var array<int,list<array{starts_at:string,ends_at:string}>> $hours
 * @var array<string,string>                                   $errors
 */

use App\Support\Csrf;
use App\Support\Html;

$base    = $appConfig['base_path'] ?? '';
$isEdit  = !empty($employee['id']);
$action  = $isEdit
    ? $base . '/admin/personal/' . (int) $employee['id']
    : $base . '/admin/personal';
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';

$dias = [
    1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves',
    5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo',
];

// Siempre se pintan dos tramos por dia: cubre la jornada partida sin
// necesidad de JavaScript para agregar filas.
$maxTramos = 2;
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-9">

        <h1 class="h4 mb-3"><?= Html::e($title) ?></h1>

        <form method="post" action="<?= Html::e($action) ?>" novalidate>
            <?= Csrf::field() ?>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-medium">Datos</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="name" class="form-label">Nombre</label>
                            <input type="text" id="name" name="name"
                                   class="form-control<?= $invalid('name') ?>"
                                   value="<?= Html::e($employee['name']) ?>" required maxlength="120">
                            <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback"><?= Html::e($errors['name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="role_title" class="form-label">
                                Puesto <span class="text-body-secondary fw-normal">(opcional)</span>
                            </label>
                            <input type="text" id="role_title" name="role_title"
                                   class="form-control<?= $invalid('role_title') ?>"
                                   value="<?= Html::e($employee['role_title']) ?>" maxlength="100"
                                   placeholder="Terapeuta, Dra., Mecanico...">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="email" class="form-label">
                                Correo <span class="text-body-secondary fw-normal">(opcional)</span>
                            </label>
                            <input type="email" id="email" name="email"
                                   class="form-control<?= $invalid('email') ?>"
                                   value="<?= Html::e($employee['email']) ?>" maxlength="190">
                            <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback"><?= Html::e($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="phone" class="form-label">
                                Telefono <span class="text-body-secondary fw-normal">(opcional)</span>
                            </label>
                            <input type="text" id="phone" name="phone"
                                   class="form-control<?= $invalid('phone') ?>"
                                   value="<?= Html::e($employee['phone']) ?>" maxlength="30">
                            <?php if (isset($errors['phone'])): ?>
                                <div class="invalid-feedback"><?= Html::e($errors['phone']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="is_active" name="is_active"
                                       <?= !empty($employee['is_active']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">Activo</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-medium">Servicios que puede prestar</div>
                <div class="card-body">
                    <?php if ($services === []): ?>
                        <p class="text-body-secondary mb-0">
                            No hay servicios creados todavia.
                        </p>
                    <?php else: ?>
                        <div class="row g-2">
                        <?php foreach ($services as $s): ?>
                            <?php if ((int) $s['is_active'] === 0 && !in_array((int) $s['id'], $assigned, true)) {
                                continue;   // los inactivos solo si ya estaban marcados
                            } ?>
                            <div class="col-12 col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="services[]" value="<?= (int) $s['id'] ?>"
                                           id="svc<?= (int) $s['id'] ?>"
                                           <?= in_array((int) $s['id'], $assigned, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="svc<?= (int) $s['id'] ?>">
                                        <?= Html::e($s['name']) ?>
                                        <span class="text-body-secondary small">
                                            (<?= (int) $s['duration_minutes'] ?> min)
                                        </span>
                                        <?php if ((int) $s['is_active'] === 0): ?>
                                            <span class="badge text-bg-secondary">inactivo</span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <p class="small text-body-secondary mt-3 mb-0">
                            Sin al menos un servicio marcado, esta persona no
                            aparecera nunca en el formulario publico.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-medium d-flex justify-content-between">
                    <span>Horario propio</span>
                    <span class="small text-body-secondary fw-normal">hora local del negocio</span>
                </div>
                <div class="card-body">

                    <?php if (isset($errors['hours'])): ?>
                        <div class="alert alert-danger py-2 small"><?= Html::e($errors['hours']) ?></div>
                    <?php endif; ?>

                    <div class="alert alert-light border small">
                        Deja <strong>todo vacio</strong> para que herede el horario del
                        negocio. En cuanto rellenes aunque sea un solo dia, el horario
                        pasa a ser exhaustivo: los dias en blanco contaran como dias
                        libres, no como "usa el del negocio".
                        Los dos tramos por dia permiten la jornada partida.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:8rem">Dia</th>
                                    <th>Tramo 1</th>
                                    <th>Tramo 2</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($dias as $n => $nombre): ?>
                                <tr>
                                    <td class="fw-medium"><?= Html::e($nombre) ?></td>
                                    <?php for ($i = 0; $i < $maxTramos; $i++): ?>
                                        <?php
                                        $from = $hours[$n][$i]['starts_at'] ?? '';
                                        $to   = $hours[$n][$i]['ends_at'] ?? '';
                                        ?>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <input type="time" class="form-control"
                                                       name="hours[<?= $n ?>][<?= $i ?>][from]"
                                                       value="<?= Html::e($from) ?>"
                                                       aria-label="<?= Html::e($nombre) ?> tramo <?= $i + 1 ?> desde">
                                                <span class="input-group-text">a</span>
                                                <input type="time" class="form-control"
                                                       name="hours[<?= $n ?>][<?= $i ?>][to]"
                                                       value="<?= Html::e($to) ?>"
                                                       aria-label="<?= Html::e($nombre) ?> tramo <?= $i + 1 ?> hasta">
                                            </div>
                                        </td>
                                    <?php endfor; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <?= $isEdit ? 'Guardar cambios' : 'Agregar' ?>
                </button>
                <a href="<?= Html::e($base) ?>/admin/personal" class="btn btn-outline-secondary">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</div>
