<?php

declare(strict_types=1);

/**
 * Bloque de detalles de la cita, compartido por las tres plantillas.
 *
 * @var array<string,mixed> $cita
 * @var string              $fechaLarga
 * @var string              $horaInicio
 * @var string              $horaFin
 */

use App\Support\Html;
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="background:#f8f9fa;border-radius:6px;margin:18px 0;">
    <tr><td style="padding:16px 18px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
            <tr>
                <td style="padding:3px 0;color:#6c757d;width:38%;">Servicio</td>
                <td style="padding:3px 0;font-weight:600;"><?= Html::e($cita['service_name']) ?></td>
            </tr>
            <tr>
                <td style="padding:3px 0;color:#6c757d;">Fecha</td>
                <td style="padding:3px 0;"><?= Html::e($fechaLarga) ?></td>
            </tr>
            <tr>
                <td style="padding:3px 0;color:#6c757d;">Hora</td>
                <td style="padding:3px 0;">
                    <?= Html::e($horaInicio) ?> a <?= Html::e($horaFin) ?>
                    (<?= (int) $cita['duration_minutes'] ?> minutos)
                </td>
            </tr>
            <tr>
                <td style="padding:3px 0;color:#6c757d;">Te atiende</td>
                <td style="padding:3px 0;"><?= Html::e($cita['employee_name']) ?></td>
            </tr>
            <tr>
                <td style="padding:3px 0;color:#6c757d;">Precio</td>
                <td style="padding:3px 0;">
                    <?= Html::e(number_format((float) $cita['price'], 2)) ?>
                    <?= Html::e($cita['currency'] ?? '') ?>
                </td>
            </tr>
        </table>
    </td></tr>
</table>
