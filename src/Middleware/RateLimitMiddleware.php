<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Limita por IP las peticiones que escriben desde la parte publica.
 *
 * Solo actua sobre metodos que modifican estado. Navegar por el wizard es
 * gratis: limitar los GET romperia el uso normal (mirar varios dias, volver
 * atras, comparar profesionales) sin frenar a nadie, porque un bot que solo
 * lee no hace dano.
 *
 * Reutiliza la tabla rate_limits del login. El "bucket" separa los
 * contadores: gastar los cinco intentos de reserva no bloquea el login, ni
 * al reves.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $bucket,
        private readonly int $maxAttempts,
        private readonly int $windowMinutes,
        private readonly bool $trustProxy = false,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if (in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $ip = RateLimiter::clientIp($request, $this->trustProxy);

        if ($this->limiter->attempt($this->bucket, $ip, $this->maxAttempts, $this->windowMinutes)) {
            return $handler->handle($request);
        }

        return $this->tooMany($request);
    }

    private function tooMany(ServerRequestInterface $request): ResponseInterface
    {
        $mensaje = sprintf(
            'Has hecho demasiados intentos seguidos. Espera %d minutos y vuelve a probar.',
            $this->windowMinutes,
        );

        $response = (new Response(429))
            // Retry-After es la cabecera estandar para esto. Un cliente
            // bien hecho la respeta; el navegador la ignora, pero deja el
            // dato en los logs para diagnosticar.
            ->withHeader('Retry-After', (string) ($this->windowMinutes * 60));

        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $response->getBody()->write(json_encode(
                ['ok' => false, 'error' => $mensaje],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        // Pagina minima y autocontenida: no se pasa por el motor de
        // plantillas ni se toca la base. Quien llega aqui es, casi siempre,
        // un script, y no tiene sentido gastar recursos en atenderlo bien.
        $response->getBody()->write(
            '<!doctype html><html lang="es"><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Demasiados intentos</title>'
            . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem">'
            . '<h1 style="font-size:1.25rem">Demasiados intentos</h1>'
            . '<p>' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Si necesitas la cita con urgencia, llama al negocio por telefono.</p>'
            . '</body></html>'
        );

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
