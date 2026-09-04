<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Proteccion contra Cross-Site Request Forgery para los formularios del panel.
 *
 * El ataque: un administrador con sesion abierta visita una pagina
 * cualquiera que contiene <form action="https://tu-panel/servicios/9/eliminar"
 * method="post"> con auto-submit. El navegador manda la peticion con la
 * cookie de sesion adjunta, y el servidor la ve como legitima.
 *
 * La defensa: cada formulario lleva un token impredecible que solo esta en
 * la sesion. El sitio atacante no puede leerlo (lo impide la politica del
 * mismo origen), asi que no puede fabricar una peticion valida.
 *
 * Se usa UN token por sesion en vez de uno por formulario. Con tokens de un
 * solo uso, abrir dos pestanas del panel invalida la primera, y el usuario
 * pierde el trabajo sin entender por que. Para un panel de administracion,
 * ese coste no compensa la mejora marginal.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    public const FIELD        = '_token';
    public const HEADER       = 'X-CSRF-Token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            // random_bytes usa el generador criptografico del sistema.
            // Nunca rand() ni uniqid(): son predecibles y aqui eso es el
            // agujero entero.
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /** Campo oculto listo para pegar dentro de un <form>. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            Html::e(self::token()),
        );
    }

    /**
     * Compara el token recibido con el de la sesion.
     *
     * hash_equals y no ===: compara en tiempo constante, sin cortocircuitar
     * en el primer byte distinto. Una comparacion normal filtra, por el
     * tiempo que tarda, cuantos caracteres iniciales acerto el atacante.
     */
    public static function isValid(?string $submitted): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '' || !is_string($submitted)) {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /** Invalida el token actual. Se llama al cerrar sesion. */
    public static function clear(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
