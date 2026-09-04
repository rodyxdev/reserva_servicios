<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>      $servicio
 * @var array<string,mixed>|null $empleado
 * @var string                   $quien
 * @var array<string,string>     $errors
 * @var array<string,mixed>      $old
 */

use App\Support\Html;

$base    = $appConfig['base_path'] ?? '';
$accion  = $base . '/reservar/' . (int) $servicio['id'] . '/' . rawurlencode($quien);
$invalid = static fn (string $f): string => isset($errors[$f]) ? ' is-invalid' : '';
$viejo   = static fn (string $f): string => (string) ($old[$f] ?? '');

$config = [
    'url'      => $accion . '/disponibilidad',
    'duracion' => (int) $servicio['duration_minutes'],
];
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-9">

        <a href="<?= Html::e($base) ?>/reservar/<?= (int) $servicio['id'] ?>"
           class="small text-decoration-none">&larr; Cambiar de profesional</a>

        <h1 class="h4 mt-2 mb-1">Elige fecha y hora</h1>
        <p class="text-body-secondary mb-4">
            <?= Html::e($servicio['name']) ?> ·
            <?= (int) $servicio['duration_minutes'] ?> minutos ·
            <?= $empleado !== null ? Html::e($empleado['name']) : 'sin preferencia de profesional' ?>
        </p>

        <?php if (isset($errors['starts_at'])): ?>
            <div class="alert alert-warning" role="alert">
                <?= Html::e($errors['starts_at']) ?>
            </div>
        <?php endif; ?>

        <?php // ---------------- Selector de dia y hora ---------------- ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div id="disponibilidad"
                     data-config="<?= Html::attr(json_encode($config, JSON_THROW_ON_ERROR)) ?>">
                    <?php // Estado inicial mientras carga. Si el JavaScript
                          // esta desactivado, este texto es lo unico que se
                          // ve, y explica que hacer. ?>
                    <div class="text-center text-body-secondary py-4" data-rol="cargando">
                        <div class="spinner-border spinner-border-sm me-2" role="status"
                             aria-hidden="true"></div>
                        Buscando horarios disponibles...
                    </div>

                    <noscript>
                        <div class="alert alert-warning mb-0">
                            Para elegir el horario necesitas activar JavaScript.
                            <?php if (!empty($business['phone'])): ?>
                                Si prefieres, llamanos al <?= Html::e($business['phone']) ?>.
                            <?php endif; ?>
                        </div>
                    </noscript>
                </div>
            </div>
        </div>

        <?php // ---------------- Datos del cliente ---------------- ?>
        <form method="post" action="<?= Html::e($accion) ?>" novalidate id="form-reserva">

            <?php // ============================================================
                  //  HONEYPOT
                  //
                  //  Se oculta con CSS (.campo-website en site.css), no con
                  //  type="hidden": un bot que analice el HTML descarta los
                  //  hidden, pero rellena un input de texto normal.
                  //
                  //  tabindex="-1" y autocomplete="off" evitan que una persona
                  //  llegue aqui por accidente tabulando o que el navegador se
                  //  lo autocomplete, que serian falsos positivos.
                  //
                  //  aria-hidden lo saca del arbol de accesibilidad, para que
                  //  un lector de pantalla no se lo lea a nadie.
                  //
                  //  El nombre "website" es deliberado: es un campo que un bot
                  //  de spam espera encontrar y quiere rellenar.
                  // ============================================================ ?>
            <div class="campo-website" aria-hidden="true">
                <label for="website">No rellenes este campo</label>
                <input type="text" id="website" name="website" tabindex="-1"
                       autocomplete="off" value="">
            </div>

            <input type="hidden" name="starts_at" id="starts_at"
                   value="<?= Html::e($viejo('starts_at')) ?>">

            <div class="card shadow-sm">
                <div class="card-header bg-white fw-medium">Tus datos</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="name" class="form-label">Nombre completo</label>
                            <input type="text" id="name" name="name" required maxlength="120"
                                   autocomplete="name"
                                   class="form-control<?= $invalid('name') ?>"
                                   value="<?= Html::e($viejo('name')) ?>">
                            <?php if (isset($errors['name'])): ?>
                                <div class="invalid-feedback"><?= Html::e($errors['name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="phone" class="form-label">Telefono</label>
                            <input type="tel" id="phone" name="phone" required maxlength="30"
                                   autocomplete="tel"
                                   class="form-control<?= $invalid('phone') ?>"
                                   value="<?= Html::e($viejo('phone')) ?>">
                            <?php if (isset($errors['phone'])): ?>
                                <div class="invalid-feedback"><?= Html::e($errors['phone']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="email" class="form-label">Correo</label>
                            <input type="email" id="email" name="email" required maxlength="190"
                                   autocomplete="email"
                                   class="form-control<?= $invalid('email') ?>"
                                   value="<?= Html::e($viejo('email')) ?>">
                            <div class="form-text">Ahi te enviamos la confirmacion.</div>
                            <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback"><?= Html::e($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label">
                                Algo que debamos saber
                                <span class="text-body-secondary fw-normal">(opcional)</span>
                            </label>
                            <textarea id="notes" name="notes" rows="2" maxlength="1000"
                                      class="form-control<?= $invalid('notes') ?>"
                                      placeholder="Alergias, preferencias, primera visita..."
                            ><?= Html::e($viejo('notes')) ?></textarea>
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input<?= $invalid('accept') ?>"
                                       type="checkbox" value="1" id="accept" name="accept"
                                       <?= $viejo('accept') !== '' ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="accept">
                                    Entiendo que puedo cancelar desde el enlace que recibire por
                                    correo, hasta
                                    <?= (int) round($cancelNotice / 60) ?>
                                    hora<?= (int) round($cancelNotice / 60) === 1 ? '' : 's' ?>
                                    antes de la cita.
                                </label>
                                <?php if (isset($errors['accept'])): ?>
                                    <div class="invalid-feedback d-block">
                                        <?= Html::e($errors['accept']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <span class="small text-body-secondary" data-rol="resumen">
                        Elige un horario arriba para continuar.
                    </span>
                    <button type="submit" class="btn btn-primary" id="btn-confirmar" disabled>
                        Confirmar reserva
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
