<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $services */

use App\Support\Csrf;
use App\Support\Html;

$base = $appConfig['base_path'] ?? '';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Servicios</h1>
    <a href="<?= Html::e($base) ?>/admin/servicios/nuevo" class="btn btn-primary btn-sm">
        Nuevo servicio
    </a>
</div>

<?php if ($services === []): ?>
    <div class="card"><div class="card-body text-center text-body-secondary py-5">
        Aun no hay servicios. Crea el primero para que se pueda reservar.
    </div></div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:2.5rem"></th>
                    <th>Servicio</th>
                    <th class="text-end">Duracion</th>
                    <th class="text-end">Buffer</th>
                    <th class="text-end">Precio</th>
                    <th class="text-center">Personal</th>
                    <th class="text-center">Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($services as $s): ?>
                <?php $inactive = (int) $s['is_active'] === 0; ?>
                <tr class="<?= $inactive ? 'opacity-50' : '' ?>">
                    <td>
                        <span class="d-inline-block rounded-circle"
                              style="width:.9rem;height:.9rem;background:<?= Html::e($s['color']) ?>"></span>
                    </td>
                    <td>
                        <div class="fw-medium"><?= Html::e($s['name']) ?></div>
                        <?php if ($s['description'] !== null && $s['description'] !== ''): ?>
                            <div class="small text-body-secondary text-truncate" style="max-width:32rem">
                                <?= Html::e($s['description']) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= (int) $s['duration_minutes'] ?> min</td>
                    <td class="text-end">
                        <?php if ((int) $s['buffer_minutes'] > 0): ?>
                            +<?= (int) $s['buffer_minutes'] ?> min
                        <?php else: ?>
                            <span class="text-body-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= Html::e(number_format((float) $s['price'], 2)) ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int) $s['employee_count'] === 0): ?>
                            <span class="badge text-bg-warning" title="Nadie puede prestarlo: no aparecera en el formulario publico">
                                sin asignar
                            </span>
                        <?php else: ?>
                            <?= (int) $s['employee_count'] ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $inactive ? 'text-bg-secondary' : 'text-bg-success' ?>">
                            <?= $inactive ? 'inactivo' : 'activo' ?>
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= Html::e($base) ?>/admin/servicios/<?= (int) $s['id'] ?>/editar">
                            Editar
                        </a>
                        <form method="post" class="d-inline"
                              action="<?= Html::e($base) ?>/admin/servicios/<?= (int) $s['id'] ?>/estado"
                              <?php if (!$inactive && (int) $s['upcoming_count'] > 0): ?>
                              onsubmit="return confirm('Quedan <?= (int) $s['upcoming_count'] ?> citas futuras con este servicio. Seguiran en pie, pero no se podran reservar nuevas. Continuar?');"
                              <?php endif; ?>>
                            <?= Csrf::field() ?>
                            <button class="btn btn-sm <?= $inactive ? 'btn-outline-success' : 'btn-outline-danger' ?>"
                                    type="submit">
                                <?= $inactive ? 'Reactivar' : 'Desactivar' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-body-secondary small mt-3 mb-0">
    Los servicios no se borran: se desactivan. Asi el historial de citas
    pasadas sigue siendo coherente y los reportes no pierden filas.
</p>
<?php endif; ?>
