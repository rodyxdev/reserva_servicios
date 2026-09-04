<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $today
 * @var list<array<string,mixed>> $upcoming
 * @var DateTimeZone              $tz
 * @var array<string,string>      $statuses
 */

use App\Support\Html;

$base = $appConfig['base_path'] ?? '';
$utc  = new DateTimeZone('UTC');

$hora = static function (string $utcDate) use ($utc, $tz): string {
    return (new DateTimeImmutable($utcDate, $utc))->setTimezone($tz)->format('H:i');
};
$fecha = static function (string $utcDate) use ($utc, $tz): string {
    return (new DateTimeImmutable($utcDate, $utc))->setTimezone($tz)->format('d/m H:i');
};

$badge = [
    'pending'   => 'text-bg-warning',
    'confirmed' => 'text-bg-primary',
    'completed' => 'text-bg-success',
    'cancelled' => 'text-bg-secondary',
    'no_show'   => 'text-bg-danger',
];

$activas = array_filter($today, static fn (array $a): bool => $a['status'] !== 'cancelled');
$ingreso = array_sum(array_map(
    static fn (array $a): float => $a['status'] === 'completed' ? (float) $a['price'] : 0.0,
    $today,
));
?>
<h1 class="h4 mb-3">Resumen de hoy</h1>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-body-secondary small">Citas hoy</div>
            <div class="fs-3 fw-semibold"><?= count($activas) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-body-secondary small">Completadas</div>
            <div class="fs-3 fw-semibold">
                <?= count(array_filter($today, static fn ($a) => $a['status'] === 'completed')) ?>
            </div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-body-secondary small">Pendientes de aprobar</div>
            <div class="fs-3 fw-semibold">
                <?= count(array_filter($today, static fn ($a) => $a['status'] === 'pending')) ?>
            </div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-body-secondary small">Ingreso realizado</div>
            <div class="fs-3 fw-semibold"><?= Html::e(number_format($ingreso, 2)) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-medium">Agenda de hoy</span>
                <a href="<?= Html::e($base) ?>/admin/calendario" class="btn btn-sm btn-outline-primary">
                    Ver calendario
                </a>
            </div>
            <?php if ($today === []): ?>
                <div class="card-body text-center text-body-secondary py-5">
                    No hay citas para hoy.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($today as $a): ?>
                        <tr class="<?= $a['status'] === 'cancelled' ? 'opacity-50' : '' ?>">
                            <td class="fw-medium text-nowrap"><?= Html::e($hora($a['starts_at'])) ?></td>
                            <td>
                                <div><?= Html::e($a['customer_name']) ?></div>
                                <div class="small text-body-secondary">
                                    <?= Html::e($a['service_name']) ?> ·
                                    <?= Html::e($a['employee_name']) ?>
                                </div>
                            </td>
                            <td class="text-end">
                                <span class="badge <?= Html::e($badge[$a['status']] ?? 'text-bg-light') ?>">
                                    <?= Html::e($statuses[$a['status']] ?? $a['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-medium">Proximos 7 dias</div>
            <?php if ($upcoming === []): ?>
                <div class="card-body text-center text-body-secondary py-5">
                    Nada agendado todavia.
                </div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $a): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>
                            <span class="fw-medium"><?= Html::e($fecha($a['starts_at'])) ?></span>
                            <span class="text-body-secondary small d-block">
                                <?= Html::e($a['customer_name']) ?> ·
                                <?= Html::e($a['service_name']) ?>
                            </span>
                        </span>
                        <span class="badge <?= Html::e($badge[$a['status']] ?? 'text-bg-light') ?>">
                            <?= Html::e($statuses[$a['status']] ?? $a['status']) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
