<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Limitador de peticiones por IP, con ventana deslizante.
 *
 * Se registra una fila por intento y se cuentan los de los ultimos N
 * minutos. Es mas preciso que una ventana fija: con ventanas fijas de 10
 * minutos, alguien puede hacer 5 intentos al minuto 9 y otros 5 al 11,
 * disparando 10 en dos minutos reales.
 *
 * Complementa, no sustituye, al bloqueo por cuenta de AuthService: aquel
 * protege una cuenta concreta de un ataque de fuerza bruta; este protege al
 * servidor de una IP que aporrea el formulario con usuarios distintos.
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Comprueba si la IP puede realizar la accion, y registra el intento.
     *
     * Devuelve true si se permite. La escritura ocurre SIEMPRE, incluso
     * cuando se rechaza: si no, quien ya esta bloqueado podria mantener la
     * ventana vacia y recuperar el acceso antes de tiempo.
     */
    public function attempt(string $bucket, string $ip, int $maxAttempts, int $windowMinutes): bool
    {
        $packed = self::packIp($ip);

        $this->record($bucket, $packed);

        return $this->countRecent($bucket, $packed, $windowMinutes) <= $maxAttempts;
    }

    /** Intentos restantes antes de bloquear. Para la cabecera Retry-After. */
    public function remaining(string $bucket, string $ip, int $maxAttempts, int $windowMinutes): int
    {
        $used = $this->countRecent($bucket, self::packIp($ip), $windowMinutes);

        return max(0, $maxAttempts - $used);
    }

    /** Limpia el historial de una IP. Se llama tras un login correcto. */
    public function clear(string $bucket, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rate_limits WHERE bucket = :bucket AND identifier = :identifier'
        );
        $stmt->bindValue('bucket', $bucket);
        $stmt->bindValue('identifier', self::packIp($ip), PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * Borra los registros antiguos. Lo llama el cron.
     *
     * Sin esto la tabla crece sin limite: en hosting compartido, con cuota
     * de disco apretada, eso acaba tumbando el sitio entero.
     */
    public function prune(int $olderThanHours = 24): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rate_limits WHERE created_at < UTC_TIMESTAMP() - INTERVAL :hours HOUR'
        );
        $stmt->bindValue('hours', $olderThanHours, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function record(string $bucket, string $packedIp): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, identifier, created_at)
             VALUES (:bucket, :identifier, :created_at)'
        );
        $stmt->bindValue('bucket', $bucket);
        $stmt->bindValue('identifier', $packedIp, PDO::PARAM_LOB);
        $stmt->bindValue(
            'created_at',
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        );
        $stmt->execute();
    }

    private function countRecent(string $bucket, string $packedIp, int $windowMinutes): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM rate_limits
              WHERE bucket = :bucket
                AND identifier = :identifier
                AND created_at >= UTC_TIMESTAMP() - INTERVAL :minutes MINUTE'
        );
        $stmt->bindValue('bucket', $bucket);
        $stmt->bindValue('identifier', $packedIp, PDO::PARAM_LOB);
        $stmt->bindValue('minutes', $windowMinutes, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Normaliza la IP a su forma binaria.
     *
     * inet_pton da 4 bytes para IPv4 y 16 para IPv6, y hace que
     * "192.168.001.1" y "192.168.1.1" sean la MISMA clave. Guardar la
     * cadena tal cual permitiria multiplicar los intentos permitidos
     * variando la representacion.
     */
    private static function packIp(string $ip): string
    {
        $packed = @inet_pton($ip);

        // Una IP ilegible se agrupa bajo una clave comun en vez de
        // ignorarse: es preferible limitar de mas que dejar un hueco.
        return $packed !== false ? $packed : str_repeat("\0", 16);
    }

    /**
     * IP del cliente.
     *
     * Solo se miran las cabeceras de proxy si la configuracion lo autoriza
     * explicitamente. Confiar en X-Forwarded-For sin un proxy delante es un
     * agujero: cualquiera manda una IP inventada distinta en cada peticion
     * y el limitador deja de existir.
     */
    public static function clientIp(ServerRequestInterface $request, bool $trustProxy = false): string
    {
        if ($trustProxy) {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');

            if ($forwarded !== '') {
                // El primer valor es el cliente original; el resto son los
                // proxies por los que paso.
                $first = trim(explode(',', $forwarded)[0]);

                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $server = $request->getServerParams();

        return (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
