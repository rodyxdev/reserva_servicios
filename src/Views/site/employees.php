<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $servicio
 * @var list<array<string,mixed>> $empleados
 * @var string                    $any
 */

use App\Support\Html;

$base = $appConfig['base_path'] ?? '';
$url  = $base . '/reservar/' . (int) $servicio['id'];
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">

        <a href="<?= Html::e($base) ?>/reservar" class="small text-decoration-none">
            &larr; Cambiar de servicio
        </a>

        <h1 class="h4 mt-2 mb-1">Con quien prefieres tu cita?</h1>
        <p class="text-body-secondary mb-4">
            <?= Html::e($servicio['name']) ?> ·
            <?= (int) $servicio['duration_minutes'] ?> minutos ·
            <?= Html::e(number_format((float) $servicio['price'], 2)) ?>
            <?= Html::e($business['currency']) ?>
        </p>

        <div class="list-group shadow-sm mb-3">
            <?php // "Sin preferencia" va primero: es la opcion que mas
                  // horarios ofrece, y por tanto la que mas reservas cierra. ?>
            <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3"
               href="<?= Html::e($url) ?>/<?= Html::e($any) ?>">
                <span class="avatar avatar-any">?</span>
                <span class="flex-grow-1">
                    <span class="d-block fw-medium">Sin preferencia</span>
                    <span class="small text-body-secondary">
                        Te asignamos a quien este libre. Es la opcion con mas horarios.
                    </span>
                </span>
                <span class="text-body-tertiary">&rsaquo;</span>
            </a>

            <?php foreach ($empleados as $e): ?>
                <a class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3"
                   href="<?= Html::e($url) ?>/<?= (int) $e['id'] ?>">
                    <span class="avatar">
                        <?= Html::e(mb_strtoupper(mb_substr((string) $e['name'], 0, 1))) ?>
                    </span>
                    <span class="flex-grow-1">
                        <span class="d-block fw-medium"><?= Html::e($e['name']) ?></span>
                        <?php if (!empty($e['role_title'])): ?>
                            <span class="small text-body-secondary"><?= Html::e($e['role_title']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="text-body-tertiary">&rsaquo;</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
