<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $employees */

use App\Support\Csrf;
use App\Support\Html;

$base = $appConfig['base_path'] ?? '';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Personal</h1>
    <a href="<?= Html::e($base) ?>/admin/personal/nuevo" class="btn btn-primary btn-sm">
        Agregar persona
    </a>
</div>

<?php if ($employees === []): ?>
    <div class="card"><div class="card-body text-center text-body-secondary py-5">
        Aun no hay personal registrado.
    </div></div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nombre</th>
                    <th>Contacto</th>
                    <th class="text-center">Servicios</th>
                    <th class="text-center">Horario</th>
                    <th class="text-center">Citas futuras</th>
                    <th class="text-center">Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $e): ?>
                <?php $inactive = (int) $e['is_active'] === 0; ?>
                <tr class="<?= $inactive ? 'opacity-50' : '' ?>">
                    <td>
                        <div class="fw-medium"><?= Html::e($e['name']) ?></div>
                        <?php if ($e['role_title'] !== null && $e['role_title'] !== ''): ?>
                            <div class="small text-body-secondary"><?= Html::e($e['role_title']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($e['email']): ?>
                            <div><?= Html::e($e['email']) ?></div>
                        <?php endif; ?>
                        <?php if ($e['phone']): ?>
                            <div class="text-body-secondary"><?= Html::e($e['phone']) ?></div>
                        <?php endif; ?>
                        <?php if (!$e['email'] && !$e['phone']): ?>
                            <span class="text-body-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((int) $e['service_count'] === 0): ?>
                            <span class="badge text-bg-warning"
                                  title="Sin servicios asignados no se le puede reservar nada">
                                ninguno
                            </span>
                        <?php else: ?>
                            <?= (int) $e['service_count'] ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-center small">
                        <?php if ((int) $e['hours_count'] === 0): ?>
                            <span class="text-body-secondary" title="Sin horario propio: usa el del negocio">
                                hereda
                            </span>
                        <?php else: ?>
                            propio
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int) $e['upcoming_count'] ?></td>
                    <td class="text-center">
                        <span class="badge <?= $inactive ? 'text-bg-secondary' : 'text-bg-success' ?>">
                            <?= $inactive ? 'inactivo' : 'activo' ?>
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= Html::e($base) ?>/admin/personal/<?= (int) $e['id'] ?>/editar">
                            Editar
                        </a>
                        <form method="post" class="d-inline"
                              action="<?= Html::e($base) ?>/admin/personal/<?= (int) $e['id'] ?>/estado"
                              <?php if (!$inactive && (int) $e['upcoming_count'] > 0): ?>
                              onsubmit="return confirm('<?= Html::e($e['name']) ?> tiene <?= (int) $e['upcoming_count'] ?> citas futuras. Al desactivarle habra que reasignarlas a mano. Continuar?');"
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
    <strong>Horario "hereda":</strong> quien no declara horario propio usa el del
    negocio completo. En cuanto se le define aunque sea un solo dia, su horario
    pasa a ser exhaustivo: los dias que no aparezcan cuentan como libres.
</p>
<?php endif; ?>
