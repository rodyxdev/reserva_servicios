<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $servicios */

use App\Support\Html;

$base = $appConfig['base_path'] ?? '';
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-9">

        <h1 class="h4 mb-1">Reservar una cita</h1>
        <p class="text-body-secondary mb-4">Elige el servicio que necesitas.</p>

        <?php if ($servicios === []): ?>
            <div class="card"><div class="card-body text-center text-body-secondary py-5">
                Ahora mismo no hay servicios disponibles para reservar en linea.
                <?php if (!empty($business['phone'])): ?>
                    <div class="mt-2">Puedes llamarnos al <?= Html::e($business['phone']) ?>.</div>
                <?php endif; ?>
            </div></div>
        <?php else: ?>
            <div class="row g-3">
            <?php foreach ($servicios as $s): ?>
                <div class="col-12 col-md-6">
                    <a class="card h-100 text-decoration-none text-body servicio-card shadow-sm"
                       href="<?= Html::e($base) ?>/reservar/<?= (int) $s['id'] ?>">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <span class="servicio-punto"
                                      style="background:<?= Html::e($s['color']) ?>"></span>
                                <h2 class="h6 mb-0 flex-grow-1"><?= Html::e($s['name']) ?></h2>
                            </div>

                            <?php if (!empty($s['description'])): ?>
                                <p class="small text-body-secondary mb-3">
                                    <?= Html::e($s['description']) ?>
                                </p>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between align-items-end">
                                <span class="badge text-bg-light border">
                                    <?= (int) $s['duration_minutes'] ?> minutos
                                </span>
                                <span class="fw-semibold">
                                    <?= Html::e(number_format((float) $s['price'], 2)) ?>
                                    <span class="small text-body-secondary">
                                        <?= Html::e($business['currency']) ?>
                                    </span>
                                </span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
