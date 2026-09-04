<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Csrf;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Verifica el token CSRF en toda peticion que modifica estado.
 *
 * Se monta sobre el grupo /admin. La reserva publica NO lleva token: quien
 * la usa no tiene sesion y no hay privilegio que robar. Ese formulario se
 * protege con honeypot y rate limiting, que es lo que corresponde a su
 * modelo de amenaza (bots, no secuestro de sesion).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** Metodos que solo leen: no necesitan token. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();

        $submitted = is_array($body) && isset($body[Csrf::FIELD]) && is_string($body[Csrf::FIELD])
            ? $body[Csrf::FIELD]
            // Las peticiones de fetch() desde el calendario mandan el token
            // por cabecera, porque su cuerpo es JSON y no un formulario.
            : $request->getHeaderLine(Csrf::HEADER);

        if (!Csrf::isValid($submitted !== '' ? $submitted : null)) {
            return $this->reject($request);
        }

        return $handler->handle($request);
    }

    private function reject(ServerRequestInterface $request): ResponseInterface
    {
        // Una peticion AJAX necesita un codigo que su JavaScript pueda
        // interpretar; un formulario necesita volver a una pagina que el
        // usuario entienda.
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            // 419 "Authentication Timeout": no es estandar, pero es la
            // convencion que espera calendar.js para recargar la pagina.
            $response = new Response(419);

            $response->getBody()->write(json_encode([
                'ok'    => false,
                'error' => 'Tu sesion expiro. Recarga la pagina e intentalo de nuevo.',
            ], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        Session::flash(
            'error',
            'El formulario expiro por seguridad. Vuelve a enviarlo, por favor.',
        );

        // 303 y no 419: tras un POST rechazado hay que devolver al usuario a
        // una vista por GET. Una pagina de error con un numero raro no le
        // dice nada a quien solo queria guardar un servicio.
        return (new Response())
            ->withHeader('Location', (string) $request->getUri()->withQuery(''))
            ->withStatus(303);
    }
}
