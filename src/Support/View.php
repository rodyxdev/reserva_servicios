<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Renderizador de plantillas PHP planas.
 *
 * No se usa Twig ni Blade a proposito: PHP ya es un lenguaje de plantillas,
 * y el objetivo del proyecto es que se vean los fundamentos. La disciplina
 * que sustituye a lo que daria un motor de plantillas es una sola regla,
 * aplicada sin excepciones:
 *
 *   TODA variable que se imprime pasa por Html::e(), sin excepciones.
 *
 * Las vistas reciben un array de datos que se extrae a variables locales,
 * y se renderizan dentro de un layout mediante buffer de salida.
 */
final class View
{
    /** @var array<string,mixed> Datos disponibles en todas las vistas */
    private array $shared = [];

    public function __construct(private readonly string $viewsPath)
    {
    }

    /** Datos que toda vista recibe: usuario en sesion, nombre del negocio... */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Renderiza una vista dentro de un layout.
     *
     * @param string              $template Ruta relativa sin .php, ej. 'admin/services/index'
     * @param array<string,mixed> $data
     * @param string|null         $layout   Ruta del layout, o null para renderizar suelta
     */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->capture($template, $data);

        if ($layout === null) {
            return $content;
        }

        return $this->capture($layout, $data + ['content' => $content]);
    }

    /**
     * Ejecuta una plantilla y devuelve su salida.
     *
     * @param array<string,mixed> $data
     */
    private function capture(string $template, array $data): string
    {
        $file = $this->viewsPath . '/' . str_replace('..', '', $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("Plantilla no encontrada: {$template}");
        }

        // Los datos compartidos van primero para que una vista pueda
        // sobreescribirlos con algo mas especifico.
        $vars = $this->shared;

        foreach ($data as $key => $value) {
            $vars[$key] = $value;
        }

        // $view queda disponible dentro de la plantilla para incluir
        // parciales anidados.
        $vars['view'] = $this;

        ob_start();

        try {
            (static function (array $__vars, string $__file): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($vars, $file);
        } catch (Throwable $e) {
            // Sin esto, una excepcion a mitad de plantilla deja el buffer
            // abierto y la salida se mezcla con la pagina de error.
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** Incluye un parcial desde dentro de otra plantilla. */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }
}
