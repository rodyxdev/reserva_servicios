<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserRepository;
use App\Support\Csrf;
use App\Support\Session;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Resultado de un intento de autenticacion.
 *
 * Se devuelve un objeto en vez de lanzar excepciones porque un login
 * fallido es el curso normal de la vida, no una condicion excepcional.
 */
final readonly class AuthResult
{
    private function __construct(
        public bool $success,
        public ?array $user = null,
        public string $message = '',
        public ?int $retryAfterMinutes = null,
    ) {
    }

    public static function ok(array $user): self
    {
        return new self(true, $user);
    }

    public static function fail(string $message): self
    {
        return new self(false, null, $message);
    }

    public static function locked(string $message, int $minutes): self
    {
        return new self(false, null, $message, $minutes);
    }
}

/**
 * Autenticacion del panel.
 *
 * Implementa el bloqueo por intentos fallidos usando failed_attempts y
 * locked_until, que ya estaban previstos en el esquema.
 */
final class AuthService
{
    public const SESSION_USER_ID = 'auth_user_id';

    /**
     * Hash de relleno para igualar el tiempo de respuesta cuando el correo
     * no existe.
     *
     * Tiene que ser un bcrypt VALIDO de coste 10, el mismo que usan los
     * usuarios reales. Con una cadena inventada, password_verify() detecta
     * el formato invalido y retorna en microsegundos sin calcular nada, que
     * es exactamente el desfase de tiempo que se pretende eliminar.
     *
     * Corresponde a 24 bytes aleatorios que no se guardaron en ninguna
     * parte: ninguna contrasena escribible por una persona lo satisface.
     */
    private const DUMMY_HASH = '$2y$10$q1oYvJMVRvtqkz9sVkvG7OGfEEyR2SpIu.ll1H2wOkiOCBl.n/4b6';

    public function __construct(
        private readonly UserRepository $users,
        private readonly int $maxAttempts = 5,
        private readonly int $lockoutMinutes = 15,
    ) {
    }

    public function attempt(string $email, string $password): AuthResult
    {
        $user = $this->users->findByEmail($email);

        // -----------------------------------------------------------------
        //  Usuario inexistente
        // -----------------------------------------------------------------
        //  Se responde con el MISMO mensaje que para una contrasena
        //  incorrecta. Distinguirlos convierte el formulario en un
        //  enumerador de cuentas: se prueban correos y los que respondan
        //  "contrasena incorrecta" existen.
        //
        //  Ademas se gasta el tiempo de un hash aunque no haya usuario. Sin
        //  esto, el caso "no existe" responde en microsegundos y el caso
        //  "existe" tarda lo que tarda bcrypt: la diferencia es medible
        //  desde fuera y delata las cuentas igual que el mensaje.
        // -----------------------------------------------------------------
        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);

            return AuthResult::fail('Correo o contrasena incorrectos.');
        }

        if ((int) $user['is_active'] !== 1) {
            return AuthResult::fail('Esta cuenta esta desactivada.');
        }

        // -----------------------------------------------------------------
        //  Cuenta bloqueada
        // -----------------------------------------------------------------
        //  Se comprueba ANTES de verificar la contrasena: durante el
        //  bloqueo no se gasta CPU en bcrypt, que es precisamente lo que un
        //  atacante querria forzar.
        // -----------------------------------------------------------------
        $remaining = $this->lockRemainingMinutes($user['locked_until']);

        if ($remaining !== null) {
            return AuthResult::locked(
                sprintf(
                    'Cuenta bloqueada temporalmente por intentos fallidos. '
                    . 'Vuelve a intentarlo en %d minuto%s.',
                    $remaining,
                    $remaining === 1 ? '' : 's',
                ),
                $remaining,
            );
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->users->registerFailedAttempt(
                (int) $user['id'],
                $this->maxAttempts,
                $this->lockoutMinutes,
            );

            $used = (int) $user['failed_attempts'] + 1;
            $left = $this->maxAttempts - $used;

            // Se avisa cuando quedan pocos intentos. Es informacion que el
            // atacante ya puede deducir contando, y le ahorra un susto al
            // usuario legitimo que escribio mal la contrasena.
            if ($left <= 0) {
                return AuthResult::locked(
                    sprintf(
                        'Demasiados intentos fallidos. La cuenta queda bloqueada %d minutos.',
                        $this->lockoutMinutes,
                    ),
                    $this->lockoutMinutes,
                );
            }

            return AuthResult::fail(
                $left <= 2
                    ? sprintf(
                        'Correo o contrasena incorrectos. Te %s %d intento%s.',
                        $left === 1 ? 'queda' : 'quedan',
                        $left,
                        $left === 1 ? '' : 's',
                    )
                    : 'Correo o contrasena incorrectos.',
            );
        }

        // -----------------------------------------------------------------
        //  Correcto
        // -----------------------------------------------------------------
        $this->users->registerSuccessfulLogin((int) $user['id']);

        // Si el coste de bcrypt subio (o PHP cambio el algoritmo por
        // defecto), se aprovecha que la contrasena en claro esta aqui, y
        // solo aqui, para regenerar el hash.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash(
                (int) $user['id'],
                password_hash($password, PASSWORD_DEFAULT),
            );
        }

        return AuthResult::ok($user);
    }

    /** Abre la sesion del usuario autenticado. */
    public function login(array $user): void
    {
        // Contra la fijacion de sesion: el identificador con el que el
        // navegador llego al formulario deja de ser valido.
        Session::regenerate();

        Session::set(self::SESSION_USER_ID, (int) $user['id']);
        Session::set('auth_user_name', (string) $user['name']);
        Session::set('auth_user_role', (string) $user['role']);
        Session::set('auth_business_id', (int) $user['business_id']);
    }

    public function logout(): void
    {
        Csrf::clear();
        Session::destroy();
    }

    public function userId(): ?int
    {
        $id = Session::get(self::SESSION_USER_ID);

        return is_int($id) ? $id : null;
    }

    public function check(): bool
    {
        return $this->userId() !== null;
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        $id = $this->userId();

        return $id === null ? null : $this->users->findById($id);
    }

    /**
     * Minutos que restan de bloqueo, o null si no esta bloqueada.
     *
     * locked_until se guarda en UTC, asi que se compara contra UTC. Es
     * justo el punto donde el desfase de zona horaria que se evito en
     * Database habria causado bloqueos que expiran seis horas antes o
     * despues de lo debido.
     */
    private function lockRemainingMinutes(?string $lockedUntil): ?int
    {
        if ($lockedUntil === null) {
            return null;
        }

        $utc    = new DateTimeZone('UTC');
        $until  = new DateTimeImmutable($lockedUntil, $utc);
        $now    = new DateTimeImmutable('now', $utc);

        if ($until <= $now) {
            return null;
        }

        return max(1, (int) ceil(($until->getTimestamp() - $now->getTimestamp()) / 60));
    }
}
