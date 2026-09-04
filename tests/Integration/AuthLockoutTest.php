<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\UserRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests del bloqueo por intentos fallidos.
 *
 * Van contra una base real y no con dobles de prueba a proposito: el bug
 * que estos tests existen para evitar no estaba en PHP, sino en las
 * semanticas del UPDATE de MySQL. Un mock de UserRepository lo habria dado
 * por bueno.
 *
 * El bug original: en
 *
 *     SET failed_attempts = failed_attempts + 1,
 *         locked_until = CASE WHEN failed_attempts + 1 >= 5 THEN ... END
 *
 * el CASE ve failed_attempts YA incrementado (las asignaciones se evaluan
 * de izquierda a derecha), asi que sumaba uno de mas y bloqueaba al cuarto
 * intento en vez de al quinto.
 */
#[CoversClass(AuthService::class)]
#[CoversClass(UserRepository::class)]
final class AuthLockoutTest extends TestCase
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MIN  = 15;

    private static ?PDO $pdo = null;
    private UserRepository $users;
    private AuthService $auth;
    private int $userId;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('DB_TEST_NAME') ?: '';

        if ($name === '') {
            return;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_TEST_HOST') ?: '127.0.0.1',
            getenv('DB_TEST_PORT') ?: '3306',
            $name,
        );

        try {
            self::$pdo = new PDO($dsn, getenv('DB_TEST_USER') ?: 'root', getenv('DB_TEST_PASS') ?: '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        } catch (\PDOException) {
            self::$pdo = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('Sin base de datos de pruebas (define DB_TEST_NAME).');
        }

        $this->users = new UserRepository(self::$pdo);
        $this->auth  = new AuthService($this->users, self::MAX_ATTEMPTS, self::LOCKOUT_MIN);

        // Usuario propio del test: no se toca a los del seed, para que
        // ejecutar la suite no deje al usuario de la demo bloqueado.
        $hash = password_hash('ContrasenaDePrueba1!', PASSWORD_DEFAULT);

        self::$pdo->prepare('DELETE FROM users WHERE email = :email')
            ->execute(['email' => 'test-lockout@example.test']);

        $stmt = self::$pdo->prepare(
            'INSERT INTO users (business_id, name, email, password_hash, role, is_active)
             VALUES (1, :name, :email, :hash, \'staff\', 1)'
        );
        $stmt->execute([
            'name'  => 'Usuario De Prueba',
            'email' => 'test-lockout@example.test',
            'hash'  => $hash,
        ]);

        $this->userId = (int) self::$pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        self::$pdo?->prepare('DELETE FROM users WHERE id = :id')
            ->execute(['id' => $this->userId]);
    }

    private function state(): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT failed_attempts, locked_until FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $this->userId]);

        return $stmt->fetch();
    }

    // -----------------------------------------------------------------

    #[Test]
    public function el_bloqueo_salta_exactamente_en_el_intento_configurado(): void
    {
        // Los primeros cuatro fallos NO deben bloquear.
        for ($i = 1; $i < self::MAX_ATTEMPTS; $i++) {
            $result = $this->auth->attempt('test-lockout@example.test', 'incorrecta');

            self::assertFalse($result->success);
            self::assertNull(
                $result->retryAfterMinutes,
                "El intento {$i} no deberia bloquear todavia",
            );

            $state = $this->state();

            self::assertSame($i, (int) $state['failed_attempts']);
            self::assertNull(
                $state['locked_until'],
                "locked_until debe seguir vacio tras {$i} intentos (el limite es "
                . self::MAX_ATTEMPTS . ')',
            );
        }

        // El quinto si.
        $result = $this->auth->attempt('test-lockout@example.test', 'incorrecta');

        self::assertFalse($result->success);
        self::assertNotNull($result->retryAfterMinutes, 'El quinto intento debe bloquear');

        $state = $this->state();

        self::assertSame(self::MAX_ATTEMPTS, (int) $state['failed_attempts']);
        self::assertNotNull($state['locked_until']);
    }

    #[Test]
    public function la_contrasena_correcta_no_abre_una_cuenta_bloqueada(): void
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $this->auth->attempt('test-lockout@example.test', 'incorrecta');
        }

        $result = $this->auth->attempt('test-lockout@example.test', 'ContrasenaDePrueba1!');

        self::assertFalse($result->success, 'El bloqueo debe ganar sobre la contrasena correcta');
        self::assertNotNull($result->retryAfterMinutes);
        self::assertStringContainsString('bloqueada', $result->message);
    }

    #[Test]
    public function el_bloqueo_expira_y_deja_entrar_de_nuevo(): void
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $this->auth->attempt('test-lockout@example.test', 'incorrecta');
        }

        // Se mueve locked_until al pasado en vez de esperar 15 minutos.
        self::$pdo->prepare(
            'UPDATE users SET locked_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = :id'
        )->execute(['id' => $this->userId]);

        $result = $this->auth->attempt('test-lockout@example.test', 'ContrasenaDePrueba1!');

        self::assertTrue($result->success, 'Con el bloqueo expirado debe poder entrar');

        $state = $this->state();

        self::assertSame(0, (int) $state['failed_attempts'], 'El contador se reinicia al entrar');
        self::assertNull($state['locked_until']);
    }

    #[Test]
    public function un_login_correcto_limpia_los_fallos_acumulados(): void
    {
        $this->auth->attempt('test-lockout@example.test', 'incorrecta');
        $this->auth->attempt('test-lockout@example.test', 'incorrecta');

        self::assertSame(2, (int) $this->state()['failed_attempts']);

        $result = $this->auth->attempt('test-lockout@example.test', 'ContrasenaDePrueba1!');

        self::assertTrue($result->success);
        self::assertSame(0, (int) $this->state()['failed_attempts']);
    }

    #[Test]
    public function un_correo_inexistente_da_el_mismo_mensaje_que_una_contrasena_mala(): void
    {
        $inexistente = $this->auth->attempt('no-existe-jamas@example.test', 'loQueSea');
        $malaClave   = $this->auth->attempt('test-lockout@example.test', 'incorrecta');

        self::assertFalse($inexistente->success);
        self::assertFalse($malaClave->success);
        self::assertSame(
            $inexistente->message,
            $malaClave->message,
            'Mensajes distintos permitirian enumerar que cuentas existen',
        );
    }

    #[Test]
    public function una_cuenta_desactivada_no_puede_entrar(): void
    {
        self::$pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id')
            ->execute(['id' => $this->userId]);

        $result = $this->auth->attempt('test-lockout@example.test', 'ContrasenaDePrueba1!');

        self::assertFalse($result->success);
        self::assertStringContainsString('desactivada', $result->message);
    }
}
