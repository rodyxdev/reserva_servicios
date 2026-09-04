<?php

declare(strict_types=1);

/**
 * Layout del panel.
 *
 * @var string              $content   Vista ya renderizada
 * @var string              $title
 * @var array<string,mixed> $appConfig
 * @var array<string,mixed> $authUser
 * @var App\Support\View    $view
 */

use App\Support\Csrf;
use App\Support\Html;
use App\Support\Session;

$base   = $appConfig['base_path'] ?? '';
$nav    = $appConfig['current_path'] ?? '';
$active = static fn (string $path): string => str_starts_with($nav, $path) ? ' active' : '';
?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::e($title ?? 'Panel') ?> · <?= Html::e($appConfig['name']) ?></title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= Html::e($base) ?>/assets/css/admin.css">

    <?php // El token viaja en un meta para que fetch() lo mande por cabecera. ?>
    <meta name="csrf-token" content="<?= Html::e(Csrf::token()) ?>">
</head>
<body class="bg-body-tertiary">

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="<?= Html::e($base) ?>/admin">
            <?= Html::e($appConfig['name']) ?>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#nav" aria-controls="nav" aria-expanded="false"
                aria-label="Abrir menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link<?= $active('/admin/calendario') ?>"
                       href="<?= Html::e($base) ?>/admin/calendario">Calendario</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $active('/admin/servicios') ?>"
                       href="<?= Html::e($base) ?>/admin/servicios">Servicios</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $active('/admin/personal') ?>"
                       href="<?= Html::e($base) ?>/admin/personal">Personal</a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-3">
                <span class="text-body-secondary small">
                    <?= Html::e($authUser['name'] ?? '') ?>
                    <span class="badge text-bg-light border ms-1">
                        <?= Html::e($authUser['role'] ?? '') ?>
                    </span>
                </span>
                <form method="post" action="<?= Html::e($base) ?>/admin/logout" class="m-0">
                    <?= Csrf::field() ?>
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Salir</button>
                </form>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid py-4">
    <?= $view->partial('partials/flash') ?>
    <?= $content ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php // Scripts propios de la vista, si los declaro. ?>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= Html::e($base) ?>/assets/js/<?= Html::e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
