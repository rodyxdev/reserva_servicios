<?php

declare(strict_types=1);

/**
 * Confirmacion de cita al cliente.
 *
 * @var array<string,mixed> $cita
 * @var string              $enlaceGestion
 */

use App\Support\Html;
?>
<p style="margin:0 0 14px;">Hola <?= Html::e($cita['customer_name']) ?>,</p>

<p style="margin:0 0 4px;">
    <?php if ($cita['status'] === 'pending'): ?>
        Recibimos tu solicitud de cita. Te avisaremos en cuanto la confirmemos.
    <?php else: ?>
        Tu cita quedo <strong>confirmada</strong>. Aqui tienes los detalles:
    <?php endif; ?>
</p>

<?= $view->partial('emails/_detalles', compact('cita', 'fechaLarga', 'horaInicio', 'horaFin')) ?>

<?php if (!empty($cita['customer_notes'])): ?>
    <p style="margin:0 0 14px;font-size:14px;color:#6c757d;">
        Tu nota: <em><?= Html::e($cita['customer_notes']) ?></em>
    </p>
<?php endif; ?>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0;">
    <tr><td style="background:#0d6efd;border-radius:6px;">
        <a href="<?= Html::url($enlaceGestion) ?>"
           style="display:inline-block;padding:11px 22px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;">
            Ver o cancelar mi cita
        </a>
    </td></tr>
</table>

<p style="margin:0 0 6px;font-size:13px;color:#6c757d;">
    Si no puedes venir, cancela desde ese enlace para que otra persona
    aproveche el horario. Puedes hacerlo hasta
    <?= (int) round($margenCancelacion / 60) ?>
    hora<?= (int) round($margenCancelacion / 60) === 1 ? '' : 's' ?> antes.
</p>

<p style="margin:0;font-size:12px;color:#adb5bd;word-break:break-all;">
    Si el boton no funciona, copia esta direccion en tu navegador:<br>
    <?= Html::e($enlaceGestion) ?>
</p>
