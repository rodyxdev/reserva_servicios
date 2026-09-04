<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Envoltura fina sobre las sesiones nativas de PHP.
 *
 * No se usa un almacen propio: las sesiones de PHP bastan para un panel de
 * administracion y funcionan sin configuracion en cualquier hosting
 * compartido, que es justo el entorno de destino.
 */
final class Session
{
    public static function start(bool $secure = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,          // muere al cerrar el navegador
            'path'     => '/',
            'secure'   => $secure,    // solo por HTTPS cuando lo haya
            'httponly' => true,       // invisible para document.cookie: si hay
                                      // un XSS, al menos no se roba la sesion
            'samesite' => 'Lax',      // el navegador no manda la cookie en
                                      // POST desde otro sitio: es la segunda
                                      // linea de defensa tras el token CSRF
        ]);

        session_name('reservas_sess');
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Regenera el identificador de sesion conservando los datos.
     *
     * Se llama justo despues de un login correcto. Sin esto el sistema queda
     * expuesto a fijacion de sesion: un atacante planta un id conocido en el
     * navegador de la victima, esta se autentica, y el id que el atacante ya
     * tenia pasa a estar autenticado.
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }

        session_destroy();
    }

    // -----------------------------------------------------------------
    //  Mensajes flash: sobreviven exactamente una peticion
    // -----------------------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /** @return array<string,list<string>> */
    public static function takeFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flash;
    }
}
