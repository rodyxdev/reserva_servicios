<?php

declare(strict_types=1);

/**
 * Mensajes flash. Se consumen al leerlos: sobreviven exactamente una peticion.
 */

use App\Support\Html;
use App\Support\Session;

$flash = Session::takeFlash();

$styles = [
    'success' => 'alert-success',
    'error'   => 'alert-danger',
    'warning' => 'alert-warning',
    'info'    => 'alert-info',
];
?>
<?php foreach ($flash as $type => $messages): ?>
    <?php foreach ($messages as $message): ?>
        <div class="alert <?= Html::e($styles[$type] ?? 'alert-secondary') ?> alert-dismissible fade show"
             role="alert">
            <?= Html::e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"
                    aria-label="Cerrar"></button>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
