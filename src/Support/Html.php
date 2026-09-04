<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Escape de salida.
 *
 * El principio: los datos se guardan crudos y se escapan AL IMPRIMIR, con
 * el escape que corresponda al contexto donde caen. Escapar al guardar es
 * el antipatron clasico: acaba con dobles escapes, con &amp;amp; en los
 * correos y con datos inservibles para cualquier consumidor que no sea HTML.
 *
 * Hay un metodo por contexto porque las reglas son distintas: lo que es
 * seguro dentro de un <p> no lo es dentro de un atributo, y menos dentro de
 * un <script>.
 */
final class Html
{
    /** Texto dentro de un elemento HTML, y tambien dentro de un atributo entrecomillado. */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars(
            (string) $value,
            // ENT_QUOTES escapa comillas simples y dobles: sin esto, un
            // valor dentro de attr='...' puede cerrar el atributo.
            // ENT_SUBSTITUTE evita que un byte UTF-8 invalido devuelva
            // cadena vacia y borre el contenido en silencio.
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
    }

    /** Alias corto para las vistas: <?= Html::attr($x) ?> dentro de un atributo. */
    public static function attr(mixed $value): string
    {
        return self::e($value);
    }

    /**
     * Valor a incrustar dentro de un bloque <script>.
     *
     * htmlspecialchars NO sirve aqui: dentro de <script> el navegador no
     * decodifica entidades HTML, asi que escaparlas rompe el dato y no
     * protege. Lo correcto es serializar a JSON con las banderas que
     * neutralizan </script> y los delimitadores de linea de JavaScript.
     */
    public static function js(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG      // < y > -> < >, mata </script>
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * URL para usar en href/src.
     *
     * Bloquea los esquemas ejecutables (javascript:, data:, vbscript:), que
     * htmlspecialchars deja pasar tal cual porque son texto perfectamente
     * valido. Si la URL no es de un esquema permitido, se devuelve '#'.
     */
    public static function url(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '#';
        }

        // Relativas y absolutas de la propia app: siempre seguras.
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return self::e($url);
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            return '#';
        }

        return self::e($url);
    }
}
