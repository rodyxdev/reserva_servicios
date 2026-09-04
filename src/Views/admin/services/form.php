<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>  $service
 * @var array<string,string> $errors
 */

use App\Support\Csrf;
use App\Support\Html;

$base     = $appConfig['base_path'] ?? '';
$isEdit   = !empty($service['id']);
$action   = $isEdit
    ? $base . '/admin/servicios/' . (int) $service['id']
    : $base . '/admin/servicios';
$invalid  = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$block    = (int) $appConfig['slot_block_minutes'];
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">

        <h1 class="h4 mb-3"><?= Html::e($title) ?></h1>

        <form method="post" action="<?= Html::e($action) ?>" novalidate>
            <?= Csrf::field() ?>

            <div class="card shadow-sm mb-3">
                <div class="card-body">

                    <div class="mb-3">
                        <label for="name" class="form-label">Nombre</label>
                        <input type="text" id="name" name="name"
                               class="form-control<?= $invalid('name') ?>"
                               value="<?= Html::e($service['name']) ?>" required maxlength="150">
                        <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?= Html::e($errors['name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">
                            Descripcion <span class="text-body-secondary fw-normal">(la ve el cliente)</span>
                        </label>
                        <textarea id="description" name="description" rows="3"
                                  class="form-control<?= $invalid('description') ?>"
                                  maxlength="2000"><?= Html::e($service['description']) ?></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label for="duration_minutes" class="form-label">Duracion</label>
                            <div class="input-group">
                                <input type="number" id="duration_minutes" name="duration_minutes"
                                       class="form-control<?= $invalid('duration_minutes') ?>"
                                       value="<?= Html::e($service['duration_minutes']) ?>"
                                       min="5" max="1440" step="<?= $block ?>" required>
                                <span class="input-group-text">min</span>
                                <?php if (isset($errors['duration_minutes'])): ?>
                                    <div class="invalid-feedback"><?= Html::e($errors['duration_minutes']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="buffer_minutes" class="form-label">Buffer</label>
                            <div class="input-group">
                                <input type="number" id="buffer_minutes" name="buffer_minutes"
                                       class="form-control<?= $invalid('buffer_minutes') ?>"
                                       value="<?= Html::e($service['buffer_minutes']) ?>"
                                       min="0" max="255" step="<?= $block ?>">
                                <span class="input-group-text">min</span>
                                <?php if (isset($errors['buffer_minutes'])): ?>
                                    <div class="invalid-feedback"><?= Html::e($errors['buffer_minutes']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="price" class="form-label">Precio</label>
                            <input type="text" inputmode="decimal" id="price" name="price"
                                   class="form-control<?= $invalid('price') ?>"
                                   value="<?= Html::e($service['price']) ?>">
                            <?php if (isset($errors['price'])): ?>
                                <div class="invalid-feedback"><?= Html::e($errors['price']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="sort_order" class="form-label">Orden</label>
                            <input type="number" id="sort_order" name="sort_order"
                                   class="form-control<?= $invalid('sort_order') ?>"
                                   value="<?= Html::e($service['sort_order']) ?>" min="0" max="9999">
                        </div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-6 col-md-3">
                            <label for="color" class="form-label">Color</label>
                            <input type="color" id="color" name="color"
                                   class="form-control form-control-color<?= $invalid('color') ?>"
                                   value="<?= Html::e($service['color']) ?>">
                        </div>

                        <div class="col-12 col-md-9 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1"
                                       id="is_active" name="is_active"
                                       <?= !empty($service['is_active']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">
                                    Se puede reservar
                                    <span class="d-block small text-body-secondary">
                                        Al desmarcarlo desaparece del formulario publico,
                                        pero conserva su historial.
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="alert alert-light border small">
                <strong>Buffer:</strong> tiempo que la agenda queda bloqueada
                <em>despues</em> del servicio (limpieza, preparacion de cabina).
                No se cobra ni se le muestra al cliente, pero impide que la
                siguiente cita empiece antes de tiempo.
                Duracion y buffer deben ser multiplos de <?= $block ?> minutos,
                que es la resolucion de la malla de bloqueo.
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <?= $isEdit ? 'Guardar cambios' : 'Crear servicio' ?>
                </button>
                <a href="<?= Html::e($base) ?>/admin/servicios" class="btn btn-outline-secondary">
                    Cancelar
                </a>
            </div>
        </form>
    </div>
</div>
