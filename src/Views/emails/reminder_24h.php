<?php

declare(strict_types=1);

/**
 * Recordatorio 24 horas antes.
 *
 * @var array<string,mixed> $cita
 * @var string              $enlaceGestion
 */

use App\Support\Html;
?>
<p style="margin:0 0 14px;">Hola <?= Html::e($cita['customer_name']) ?>,</p>

<p style="margin:0 0 4px;">
    Te recordamos que tienes una cita <strong>manana</strong>.
</p>

<?= $view->partial('emails/_detalles', compact('cita', 'fechaLarga', 'horaInicio', 'horaFin')) ?>

<p style="margin:0 0 14px;font-size:14px;">
    Te esperamos. Si te surge algo y no puedes venir, avisanos cuanto antes
    para poder ofrecer el horario a otra persona.
</p>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0;">
    <tr><td style="border:1px solid #dc3545;border-radius:6px;">
        <a href="<?= Html::url($enlaceGestion) ?>"
           style="display:inline-block;padding:9px 18px;color:#dc3545;text-decoration:none;font-weight:600;font-size:14px;">
            No puedo asistir, cancelar
        </a>
    </td></tr>
</table>

<p style="margin:0;font-size:12px;color:#adb5bd;word-break:break-all;">
    <?= Html::e($enlaceGestion) ?>
</p>
