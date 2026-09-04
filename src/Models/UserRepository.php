<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, b.name AS business_name, b.timezone AS business_timezone,
                    b.currency, b.slug AS business_slug
               FROM users u
               JOIN businesses b ON b.id = u.business_id
              WHERE u.email = :email'
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, b.name AS business_name, b.timezone AS business_timezone,
                    b.currency, b.slug AS business_slug
               FROM users u
               JOIN businesses b ON b.id = u.business_id
              WHERE u.id = :id AND u.is_active = 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Suma un intento fallido y bloquea la cuenta si se alcanza el limite.
     *
     * Las dos cosas en una sola sentencia, no en un SELECT seguido de un
     * UPDATE: si dos peticiones fallidas llegan a la vez, el patron
     * leer-modificar-escribir pierde uno de los incrementos y el atacante
     * gana intentos gratis. Aqui el incremento lo hace el motor sobre el
     * valor actual de la fila.
     */
    public function registerFailedAttempt(int $userId, int $maxAttempts, int $lockoutMinutes): void
    {
        // ---------------------------------------------------------------
        //  CUIDADO CON EL ORDEN DE LAS ASIGNACIONES
        // ---------------------------------------------------------------
        //  MySQL y MariaDB evaluan las asignaciones de un UPDATE de
        //  IZQUIERDA A DERECHA, y cada una ve el valor YA ACTUALIZADO de
        //  las anteriores. Como failed_attempts se incrementa primero, la
        //  referencia dentro del CASE es al valor NUEVO, no al viejo.
        //
        //  Por eso la comparacion es "failed_attempts >= :max" y no
        //  "failed_attempts + 1 >= :max": con el "+ 1" se sumaba dos veces
        //  y la cuenta se bloqueaba un intento antes de lo configurado
        //  (al 4 con el limite en 5). Verificado contra MariaDB 10.4 y
        //  cubierto por AuthLockoutTest, que comprueba el intento exacto
        //  en el que salta el bloqueo.
        // ---------------------------------------------------------------
        $stmt = $this->pdo->prepare(
            'UPDATE users
                SET failed_attempts = failed_attempts + 1,
                    locked_until = CASE
                        WHEN failed_attempts >= :max_attempts
                        THEN UTC_TIMESTAMP() + INTERVAL :lockout_minutes MINUTE
                        ELSE locked_until
                    END
              WHERE id = :id'
        );
        $stmt->bindValue('max_attempts', $maxAttempts, PDO::PARAM_INT);
        $stmt->bindValue('lockout_minutes', $lockoutMinutes, PDO::PARAM_INT);
        $stmt->bindValue('id', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Login correcto: se limpia el contador y se anota la fecha. */
    public function registerSuccessfulLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
                SET failed_attempts = 0,
                    locked_until = NULL,
                    last_login_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
    }

    /** Sustituye el hash tras un password_needs_rehash(). */
    public function updatePasswordHash(int $userId, string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash WHERE id = :id'
        );
        $stmt->execute(['hash' => $hash, 'id' => $userId]);
    }
}
