<?php

declare(strict_types=1);

/**
 * Aviso al negocio de que entro una reserva nueva.
 *
 * Lleva el telefono y el correo del cliente, que en los correos al cliente
 * no aparecen: aqui el destinatario es el propio negocio.
 *
 * @var array<string,mixed> $cita
 * @var string              $enlacePanel
 */

use App\Support\Html;
?>
<p style="margin:0 0 14px;font-size:16px;font-weight:600;">
    Nueva reserva desde la web
</p>

<?= $view->partial('emails/_detalles', compact('cita', 'fechaLarga', 'horaInicio', 'horaFin')) ?>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="font-size:14px;margin:0 0 18px;">
    <tr>
        <td style="padding:3px 0;color:#6c757d;width:38%;">Cliente</td>
        <td style="padding:3px 0;font-weight:600;"><?= Html::e($cita['customer_name']) ?></td>
    </tr>
    <tr>
        <td style="padding:3px 0;color:#6c757d;">Telefono</td>
        <td style="padding:3px 0;"><?= Html::e($cita['customer_phone'] ?? '-') ?></td>
    </tr>
    <tr>
        <td style="padding:3px 0;color:#6c757d;">Correo</td>
        <td style="padding:3px 0;"><?= Html::e($cita['customer_email']) ?></td>
    </tr>
    <?php if (!empty($cita['customer_notes'])): ?>
    <tr>
        <td style="padding:3px 0;color:#6c757d;vertical-align:top;">Nota</td>
        <td style="padding:3px 0;"><?= Html::e($cita['customer_notes']) ?></td>
    </tr>
    <?php endif; ?>
</table>

<table role="presentation" cellpadding="0" cellspacing="0">
    <tr><td style="border:1px solid #0d6efd;border-radius:6px;">
        <a href="<?= Html::url($enlacePanel) ?>"
           style="display:inline-block;padding:9px 18px;color:#0d6efd;text-decoration:none;font-weight:600;font-size:14px;">
            Abrir el calendario
        </a>
    </td></tr>
</table>
