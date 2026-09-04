<?php

declare(strict_types=1);

/**
 * Aviso de cancelacion.
 *
 * @var array<string,mixed> $cita
 * @var string              $enlaceReservar
 */

use App\Support\Html;
?>
<p style="margin:0 0 14px;">Hola <?= Html::e($cita['customer_name']) ?>,</p>

<p style="margin:0 0 4px;">
    Tu cita quedo <strong>cancelada</strong>
    <?= $cita['cancelled_by'] === 'staff' ? ' por el negocio' : '' ?>.
    Estos eran los datos:
</p>

<?= $view->partial('emails/_detalles', compact('cita', 'fechaLarga', 'horaInicio', 'horaFin')) ?>

<?php if (!empty($cita['cancel_reason'])): ?>
    <p style="margin:0 0 14px;font-size:14px;color:#6c757d;">
        Motivo registrado: <em><?= Html::e($cita['cancel_reason']) ?></em>
    </p>
<?php endif; ?>

<p style="margin:0 0 14px;font-size:14px;">
    No se te cobrara nada. Cuando quieras volver, puedes reservar otra cita
    cuando te venga bien.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0;">
    <tr><td style="background:#0d6efd;border-radius:6px;">
        <a href="<?= Html::url($enlaceReservar) ?>"
           style="display:inline-block;padding:11px 22px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;">
            Reservar otra cita
        </a>
    </td></tr>
</table>
