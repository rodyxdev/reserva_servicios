<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Protege todas las rutas del panel.
 *
 * Se monta sobre el grupo /admin entero, no ruta por ruta. La diferencia
 * importa: con la comprobacion en cada controlador, la ruta que alguien
 * anada dentro de seis meses y olvide proteger queda abierta. Aqui lo
 * predeterminado es estar cerrado, y abrir es lo que hay que hacer a
 * proposito (la lista EXCEPT).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /** Rutas del panel accesibles sin sesion: el propio login. */
    private const PUBLIC_PATHS = ['/admin/login', '/admin/logout'];

    public function __construct(
        private readonly AuthService $auth,
        private readonly string $basePath = '',
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $path = $request->getUri()->getPath();

        // Se normaliza quitando el prefijo cuando la app no cuelga de la
        // raiz del dominio (subcarpeta en hosting compartido).
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        }

        $path = '/' . trim($path, '/');

        if (in_array($path, self::PUBLIC_PATHS, true)) {
            return $handler->handle($request);
        }

        $user = $this->auth->user();

        if ($user === null) {
            // La sesion pudo caducar, o el usuario pudo ser desactivado
            // mientras la tenia abierta: user() consulta la base y devuelve
            // null si is_active paso a 0. Una sesion viva no basta.
            $this->auth->logout();

            return $this->redirectToLogin($request);
        }

        // El usuario queda disponible en los controladores sin que tengan
        // que volver a consultarlo.
        return $handler->handle($request->withAttribute('auth_user', $user));
    }

    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $response = new Response(401);
            $response->getBody()->write(json_encode([
                'ok'    => false,
                'error' => 'Sesion expirada.',
            ], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        Session::flash('error', 'Inicia sesion para continuar.');

        return (new Response())
            ->withHeader('Location', $this->basePath . '/admin/login')
            ->withStatus(302);
    }
}
