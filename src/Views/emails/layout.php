<?php

declare(strict_types=1);

/**
 * Layout de los correos.
 *
 * Nada de CSS externo ni clases: los clientes de correo (Outlook sobre todo)
 * ignoran las hojas de estilo y buena parte de los selectores. Todo va en
 * atributos style en linea y sobre tablas, que es el unico HTML que
 * renderiza igual en Gmail, Outlook y Apple Mail.
 *
 * @var string $content
 * @var string $subject
 */

use App\Support\Html;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::e($subject ?? '') ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#212529;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e3e5e8;">

        <tr>
            <td style="padding:20px 28px;border-bottom:1px solid #e3e5e8;">
                <span style="font-size:17px;font-weight:600;color:#212529;">
                    <?= Html::e($negocio['name'] ?? '') ?>
                </span>
            </td>
        </tr>

        <tr>
            <td style="padding:28px;font-size:15px;line-height:1.55;">
                <?= $content ?>
            </td>
        </tr>

        <tr>
            <td style="padding:18px 28px;border-top:1px solid #e3e5e8;background:#fafbfc;font-size:12px;line-height:1.5;color:#6c757d;">
                <?= Html::e($negocio['name'] ?? '') ?>
                <?php if (!empty($negocio['phone'])): ?>
                    &middot; <?= Html::e($negocio['phone']) ?>
                <?php endif; ?>
                <br>
                Este correo se envio automaticamente. No hace falta que respondas.
            </td>
        </tr>
    </table>

</td></tr>
</table>
</body>
</html>
