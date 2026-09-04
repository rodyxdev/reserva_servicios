<?php

declare(strict_types=1);

/**
 * @var array<string,string> $errors
 * @var string               $email
 */

use App\Support\Csrf;
use App\Support\Html;

$base = $appConfig['base_path'] ?? '';
?>
<div class="row justify-content-center">
    <div class="col-12 col-sm-9 col-md-6 col-lg-4">

        <div class="text-center mb-4">
            <h1 class="h4 fw-semibold mb-1"><?= Html::e($appConfig['name']) ?></h1>
            <p class="text-body-secondary small mb-0">Panel de administracion</p>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-4">

                <?php if (isset($errors['email'])): ?>
                    <div class="alert alert-danger py-2 small" role="alert">
                        <?= Html::e($errors['email']) ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= Html::e($base) ?>/admin/login" novalidate>
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label for="email" class="form-label">Correo</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= Html::e($email) ?>" required autofocus
                               autocomplete="username">
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">Contrasena</label>
                        <input type="password" class="form-control" id="password"
                               name="password" required autocomplete="current-password">
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>
            </div>
        </div>

        <p class="text-center text-body-secondary small mt-3 mb-0">
            Demo: admin@spa-aurora.test / Demo1234!
        </p>
    </div>
</div>
