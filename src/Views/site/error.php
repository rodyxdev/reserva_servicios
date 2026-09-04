<?php

declare(strict_types=1);

/** @var string $mensaje */

use App\Support\Html;

$base = $appConfig['base_path'] ?? '';
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-7">
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <h1 class="h5 mb-3"><?= Html::e($title) ?></h1>
                <p class="text-body-secondary"><?= Html::e($mensaje) ?></p>
                <a href="<?= Html::e($base) ?>/reservar" class="btn btn-primary mt-2">
                    Reservar una cita
                </a>
            </div>
        </div>
    </div>
</div>
