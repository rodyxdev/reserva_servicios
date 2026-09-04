<?php

declare(strict_types=1);

/**
 * Layout de la parte publica.
 *
 * Sin navegacion de panel, sin datos internos, sin token CSRF: quien llega
 * aqui no tiene sesion ni privilegios. Cuanto menos se le exponga, mejor.
 *
 * @var string             $content
 * @var string             $title
 * @var array<string,mixed> $business
 * @var int                $paso
 * @var App\Support\View   $view
 */

use App\Support\Html;

$base   = $appConfig['base_path'] ?? '';
$nombre = $business['name'] ?? $appConfig['name'];
$paso   = $paso ?? 0;

$pasos = [1 => 'Servicio', 2 => 'Profesional', 3 => 'Fecha y hora'];
?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::e($title ?? '') ?> · <?= Html::e($nombre) ?></title>

    <?php // Las paginas de una cita concreta no deben acabar en un buscador. ?>
    <meta name="robots" content="<?= $paso === 0 ? 'noindex, nofollow' : 'index, follow' ?>">

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= Html::e($base) ?>/assets/css/site.css">
</head>
<body class="bg-body-tertiary">

<header class="bg-white border-bottom">
    <div class="container py-3 d-flex justify-content-between align-items-center">
        <a class="text-decoration-none text-body" href="<?= Html::e($base) ?>/reservar">
            <span class="h5 mb-0 fw-semibold"><?= Html::e($nombre) ?></span>
        </a>
        <?php if (!empty($business['phone'])): ?>
            <a class="small text-decoration-none"
               href="<?= Html::url('tel:' . preg_replace('/\s+/', '', (string) $business['phone'])) ?>">
                <?= Html::e($business['phone']) ?>
            </a>
        <?php endif; ?>
    </div>
</header>

<?php if ($paso >= 1): ?>
<div class="bg-white border-bottom">
    <div class="container">
        <ol class="wizard-pasos list-unstyled d-flex gap-2 gap-sm-4 mb-0 py-3 small">
            <?php foreach ($pasos as $n => $etiqueta): ?>
                <li class="d-flex align-items-center gap-2
                           <?= $n === $paso ? 'fw-semibold text-primary' : ($n < $paso ? 'text-body-secondary' : 'text-body-tertiary') ?>">
                    <span class="wizard-num <?= $n <= $paso ? 'wizard-num-on' : '' ?>"><?= $n ?></span>
                    <span class="d-none d-sm-inline"><?= Html::e($etiqueta) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<?php endif; ?>

<main class="container py-4">
    <?= $view->partial('partials/flash') ?>
    <?= $content ?>
</main>

<footer class="container py-4 text-center text-body-secondary small">
    <?= Html::e($nombre) ?>
    <?php if (!empty($business['timezone'])): ?>
        · Los horarios se muestran en hora de
        <?= Html::e(str_replace('_', ' ', explode('/', (string) $business['timezone'])[1] ?? '')) ?>
    <?php endif; ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= Html::e($base) ?>/assets/js/<?= Html::e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
