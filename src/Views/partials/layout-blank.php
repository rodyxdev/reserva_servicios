<?php

declare(strict_types=1);

/**
 * Layout minimo, sin navegacion: para el login.
 *
 * @var string $content
 * @var string $title
 */

use App\Support\Html;
?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::e($title ?? '') ?> · <?= Html::e($appConfig['name']) ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= Html::e($appConfig['base_path'] ?? '') ?>/assets/css/admin.css">
</head>
<body class="bg-body-tertiary">
<main class="container py-5">
    <?= $view->partial('partials/flash') ?>
    <?= $content ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
