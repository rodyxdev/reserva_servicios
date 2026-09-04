<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Validador de entrada del lado servidor.
 *
 * La validacion de JavaScript es una cortesia para el usuario, no una
 * medida de seguridad: cualquiera manda un POST con curl saltandose el
 * formulario entero. Todo lo que entra pasa por aqui.
 *
 * Uso:
 *   $v = new Validator($request->getParsedBody() ?? []);
 *   $nombre = $v->required('name')->string('name', max: 120);
 *   $email  = $v->required('email')->email('email');
 *   if ($v->fails()) { ... $v->errors() ... }
 *
 * Cada metodo devuelve el valor ya normalizado (recortado, casteado) o null
 * si no paso la validacion, y acumula el error en lugar de lanzar. Asi se
 * puede mostrar el formulario entero con todos sus errores de una vez, en
 * vez de uno por recarga.
 */
final class Validator
{
    /** @var array<string,string> campo => primer mensaje de error */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    // -----------------------------------------------------------------
    //  Estado
    // -----------------------------------------------------------------

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    public function addError(string $field, string $message): self
    {
        // Se conserva el primer error de cada campo: es el mas especifico,
        // los siguientes suelen ser consecuencia del primero.
        $this->errors[$field] ??= $message;

        return $this;
    }

    /** Valor crudo, sin validar. Para el repoblado de formularios. */
    public function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    // -----------------------------------------------------------------
    //  Reglas
    // -----------------------------------------------------------------

    public function required(string $field, string $label = null): self
    {
        $value = $this->data[$field] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '') || $value === []) {
            $this->addError($field, sprintf('%s es obligatorio.', $label ?? 'Este campo'));
        }

        return $this;
    }

    /**
     * Cadena de texto normalizada.
     *
     * Ademas de recortar, elimina los caracteres de control (incluidos \r y
     * \n cuando $singleLine): son la via clasica de inyeccion de cabeceras
     * cuando el valor acaba en un correo, y no aportan nada en un nombre.
     */
    public function string(
        string $field,
        int $min = 0,
        int $max = 255,
        bool $singleLine = true,
    ): ?string {
        $value = $this->data[$field] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        $pattern = $singleLine ? '/[\x00-\x1F\x7F]/u' : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';
        $value = (string) preg_replace($pattern, '', $value);

        if ($value === '') {
            return null;
        }

        // mb_strlen y no strlen: "José" son 4 caracteres, no 5 bytes.
        $length = mb_strlen($value, 'UTF-8');

        if ($length < $min) {
            $this->addError($field, sprintf('Debe tener al menos %d caracteres.', $min));

            return null;
        }

        if ($length > $max) {
            $this->addError($field, sprintf('No puede superar %d caracteres.', $max));

            return null;
        }

        return $value;
    }

    public function email(string $field, int $max = 190): ?string
    {
        $value = $this->string($field, max: $max);

        if ($value === null) {
            return null;
        }

        // FILTER_VALIDATE_EMAIL no acepta unicode en la parte local, que es
        // lo correcto para lo que luego va a tragar un servidor SMTP.
        $clean = filter_var($value, FILTER_VALIDATE_EMAIL);

        if ($clean === false) {
            $this->addError($field, 'El correo no tiene un formato valido.');

            return null;
        }

        return strtolower((string) $clean);
    }

    /**
     * Telefono.
     *
     * Deliberadamente permisivo: se conservan espacios, parentesis, guiones
     * y el prefijo +, porque los formatos nacionales varian demasiado y
     * rechazar un telefono valido cuesta una reserva. Solo se comprueba que
     * haya una cantidad razonable de digitos.
     */
    public function phone(string $field, int $minDigits = 8, int $maxDigits = 15): ?string
    {
        $value = $this->string($field, max: 30);

        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';
        $count  = strlen($digits);

        if ($count < $minDigits || $count > $maxDigits) {
            $this->addError($field, 'El telefono no parece valido.');

            return null;
        }

        return $value;
    }

    public function int(string $field, ?int $min = null, ?int $max = null): ?int
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value)) {
            $value = trim($value);
        }

        $clean = filter_var($value, FILTER_VALIDATE_INT);

        if ($clean === false) {
            $this->addError($field, 'Debe ser un numero entero.');

            return null;
        }

        if ($min !== null && $clean < $min) {
            $this->addError($field, sprintf('No puede ser menor que %d.', $min));

            return null;
        }

        if ($max !== null && $clean > $max) {
            $this->addError($field, sprintf('No puede ser mayor que %d.', $max));

            return null;
        }

        return $clean;
    }

    public function decimal(string $field, float $min = 0.0, float $max = 999999.99): ?float
    {
        $value = $this->data[$field] ?? null;

        if (is_string($value)) {
            // Acepta "1,250.00" y "1250,00": el usuario escribe como puede.
            $value = str_replace(',', '.', trim($value));
            // Si quedaron dos puntos ("1.250.00"), el primero era de miles.
            if (substr_count($value, '.') > 1) {
                $value = preg_replace('/\.(?=.*\.)/', '', $value);
            }
        }

        $clean = filter_var($value, FILTER_VALIDATE_FLOAT);

        if ($clean === false) {
            $this->addError($field, 'Debe ser un numero.');

            return null;
        }

        if ($clean < $min || $clean > $max) {
            $this->addError($field, 'El valor esta fuera del rango permitido.');

            return null;
        }

        return round($clean, 2);
    }

    /** @param list<string> $allowed */
    public function inList(string $field, array $allowed): ?string
    {
        $value = $this->data[$field] ?? null;

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->addError($field, 'Opcion no valida.');

            return null;
        }

        return $value;
    }

    public function boolean(string $field): bool
    {
        return filter_var(
            $this->data[$field] ?? false,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) === true;
    }

    /** Color hexadecimal de 7 caracteres, tal como lo manda <input type="color">. */
    public function hexColor(string $field): ?string
    {
        $value = $this->string($field, max: 7);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            $this->addError($field, 'El color debe tener el formato #rrggbb.');

            return null;
        }

        return strtolower($value);
    }

    /**
     * Fecha en formato Y-m-d, validada de verdad.
     *
     * strtotime() aceptaria "2026-02-30" y la correria a marzo en silencio.
     * createFromFormat + comprobacion del formateo inverso rechaza las
     * fechas que no existen.
     */
    public function date(string $field, string $format = 'Y-m-d', ?DateTimeZone $tz = null): ?DateTimeImmutable
    {
        $value = $this->string($field, max: 32);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $tz);

        if ($date === false || $date->format($format) !== $value) {
            $this->addError($field, 'La fecha no es valida.');

            return null;
        }

        return $date;
    }

    /** Fecha y hora combinadas, como llegan del formulario publico. */
    public function dateTime(
        string $field,
        string $format = 'Y-m-d H:i',
        ?DateTimeZone $tz = null,
    ): ?DateTimeImmutable {
        return $this->date($field, $format, $tz);
    }

    /**
     * Honeypot.
     *
     * El formulario publico lleva un campo oculto por CSS con un nombre
     * apetecible ("website"). Una persona nunca lo ve; los bots que rellenan
     * todo lo que encuentran, si.
     *
     * Devuelve true cuando el campo viene relleno, es decir, cuando el
     * remitente casi con seguridad es un bot. El controlador NO debe mostrar
     * un error: debe responder exactamente igual que en un envio correcto.
     * Si se le dice al bot que fue detectado, quien lo opera ajusta el
     * script y vuelve; si cree que funciono, sigue mandando basura a un
     * agujero negro.
     */
    public function isHoneypotFilled(string $field = 'website'): bool
    {
        $value = $this->data[$field] ?? '';

        return is_string($value) && trim($value) !== '';
    }
}
