<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cabeceras de seguridad en todas las respuestas.
 *
 * Se aplican en el middleware y no en cada controlador por la razon de
 * siempre: lo que hay que recordar poner, tarde o temprano se olvida.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $httpsEnabled = false)
    {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);

        $headers = [
            // Impide el "MIME sniffing": sin esto, un navegador que recibe
            // un archivo declarado text/plain pero con pinta de HTML puede
            // decidir ejecutarlo como HTML. Convierte una subida de archivo
            // inocente en un XSS almacenado.
            'X-Content-Type-Options' => 'nosniff',

            // Nadie puede meter el panel en un <iframe>. Cierra el
            // clickjacking: superponer un iframe invisible del panel sobre
            // una pagina cebo para que el administrador pulse "Eliminar"
            // creyendo que pulsa otra cosa.
            'X-Frame-Options' => 'DENY',

            // No se filtra la URL completa (que puede llevar el token
            // publico de una cita) al navegar a un dominio externo.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            // Se renuncia explicitamente a APIs que esta aplicacion no usa.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];

        // Content-Security-Policy.
        //
        // 'unsafe-inline' en style-src es una concesion consciente a
        // Bootstrap y FullCalendar, que inyectan estilos en linea; quitarlo
        // exigiria nonces en cada estilo generado por esas librerias.
        // En script-src NO se concede: los scripts propios viven en
        // archivos de public/assets/js, nunca en atributos onclick.
        $headers['Content-Security-Policy'] = implode('; ', [
            "default-src 'self'",
            "script-src 'self' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "font-src 'self' https://cdn.jsdelivr.net data:",
            "img-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
        ]);

        // HSTS solo con HTTPS activo. Mandarlo por HTTP no hace nada, y
        // mandarlo en desarrollo deja el navegador del usuario forzando
        // https://localhost durante meses, lo que es un incordio serio.
        if ($this->httpsEnabled) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
