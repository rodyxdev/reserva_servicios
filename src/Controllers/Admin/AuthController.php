<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuthService;
use App\Support\RateLimiter;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthService $auth,
        private readonly RateLimiter $limiter,
        private readonly array $settings,
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', $this->base() . '/admin')->withStatus(302);
        }

        $html = $this->view->render('admin/login', [
            'title'  => 'Iniciar sesion',
            'errors' => [],
            'email'  => '',
        ], 'partials/layout-blank');

        $response->getBody()->write($html);

        return $response;
    }

    public function login(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $v    = new Validator($body);

        $email    = $v->email('email');
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        // -----------------------------------------------------------------
        //  Limite por IP, ademas del bloqueo por cuenta
        // -----------------------------------------------------------------
        //  Son dos defensas distintas. El bloqueo de AuthService protege UNA
        //  cuenta de que le prueben contrasenas. Este limite protege al
        //  servidor de una IP que prueba MUCHAS cuentas con la contrasena
        //  "123456": ahi el contador por cuenta nunca llega a 5 y no salta.
        // -----------------------------------------------------------------
        [$max, $window] = $this->settings['security']['rate_limit']['login'];

        $ip = RateLimiter::clientIp(
            $request,
            (bool) $this->settings['security']['trust_proxy_headers'],
        );

        if (!$this->limiter->attempt('login', $ip, $max, $window)) {
            return $this->back($response, $email ?? '', [
                'email' => 'Demasiados intentos desde esta conexion. Espera unos minutos.',
            ], 429);
        }

        if ($email === null || $password === '') {
            return $this->back($response, (string) ($body['email'] ?? ''), [
                'email' => $v->error('email') ?? 'Escribe tu correo y tu contrasena.',
            ]);
        }

        $result = $this->auth->attempt($email, $password);

        if (!$result->success) {
            return $this->back($response, $email, ['email' => $result->message]);
        }

        $this->auth->login($result->user);

        // Sesion abierta: la IP deja de estar penalizada.
        $this->limiter->clear('login', $ip);

        Session::flash('success', 'Bienvenido de vuelta, ' . $result->user['name'] . '.');

        return $response->withHeader('Location', $this->base() . '/admin')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();

        // La sesion se destruyo, asi que el mensaje va en una nueva.
        Session::start();
        Session::flash('success', 'Sesion cerrada.');

        return $response->withHeader('Location', $this->base() . '/admin/login')->withStatus(302);
    }

    /** @param array<string,string> $errors */
    private function back(Response $response, string $email, array $errors, int $status = 422): Response
    {
        $html = $this->view->render('admin/login', [
            'title'  => 'Iniciar sesion',
            'errors' => $errors,
            'email'  => $email,
        ], 'partials/layout-blank');

        $response->getBody()->write($html);

        return $response->withStatus($status);
    }

    private function base(): string
    {
        return (string) ($this->settings['base_path'] ?? '');
    }
}
