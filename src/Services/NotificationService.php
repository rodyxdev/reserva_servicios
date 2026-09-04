<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Cola de notificaciones.
 *
 * Nada se envia "en el momento y a ver si sale". Todo pasa por
 * appointment_reminders:
 *
 *   1. Al reservar o cancelar se ENCOLA la fila (dentro de la transaccion
 *      que crea la cita: si la cita existe, su aviso existe).
 *   2. Se intenta enviar ENSEGUIDA, en la misma peticion web.
 *   3. Si eso falla (SMTP caido, timeout), la fila se queda en 'pending'
 *      y el cron la recoge mas tarde.
 *
 * El cliente nunca ve un error por un problema de correo: su cita ya esta
 * hecha, que es lo que le importa. Y ningun aviso se pierde en silencio.
 */
final class NotificationService
{
    /** Asuntos por tipo. El nombre del negocio se antepone al enviar. */
    private const SUBJECTS = [
        'confirmation' => 'Tu cita esta confirmada',
        'reminder_24h' => 'Recordatorio: tu cita es manana',
        'cancellation' => 'Tu cita fue cancelada',
    ];

    private const TEMPLATES = [
        'confirmation' => 'confirmation',
        'reminder_24h' => 'reminder_24h',
        'cancellation' => 'cancellation',
    ];

    /** Tras 3 fallos se deja de reintentar. */
    private const MAX_ATTEMPTS = 3;

    /** Resultados posibles de deliver(). */
    public const RESULT_SENT         = 'sent';
    public const RESULT_FAILED       = 'failed';
    public const RESULT_SKIPPED      = 'skipped';
    public const RESULT_RATE_LIMITED = 'rate_limited';

    public function __construct(
        private readonly PDO $pdo,
        private readonly MailService $mail,
        private readonly array $settings,
    ) {
    }

    // =================================================================
    //  Lectura de la cola
    // =================================================================

    /**
     * Reclama un lote de avisos vencidos para procesarlos.
     *
     * ------------------------------------------------------------------
     *  FOR UPDATE SKIP LOCKED, Y POR QUE HAY UN PLAN B
     * ------------------------------------------------------------------
     *  SKIP LOCKED hace que dos ejecuciones simultaneas del cron no se
     *  peleen: la segunda salta las filas que la primera ya tiene
     *  bloqueadas en lugar de esperar a que suelte. Sin el, un cron que
     *  tarda mas de su intervalo acaba con procesos apilados esperando, y
     *  con suerte, enviando el mismo correo dos veces.
     *
     *  Requiere MySQL 8.0+ o MariaDB 10.6+. En versiones anteriores es un
     *  error de sintaxis, no un aviso: la consulta entera falla. Como el
     *  objetivo incluye hosting compartido, donde uno no elige la version,
     *  se detecta en tiempo de ejecucion y se cae a un bloqueo optimista:
     *  un UPDATE que marca las filas como 'sending' y solo se queda con
     *  las que consiguio marcar.
     *
     *  El plan B es correcto igualmente (el UPDATE es atomico), solo mas
     *  costoso: una escritura extra por lote.
     * ------------------------------------------------------------------
     *
     * @return list<array<string,mixed>>
     */
    public function claimDue(int $limit = 50): array
    {
        return $this->supportsSkipLocked()
            ? $this->claimWithSkipLocked($limit)
            : $this->claimWithUpdate($limit);
    }

    /** @return list<array<string,mixed>> */
    private function claimWithSkipLocked(int $limit): array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                $this->dueQuery() . ' ORDER BY r.scheduled_for LIMIT :max_rows FOR UPDATE SKIP LOCKED'
            );
            $stmt->bindValue('max_rows', $limit, PDO::PARAM_INT);
            $stmt->bindValue('max_attempts', self::MAX_ATTEMPTS, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();

            // Se marcan como 'sending' dentro de la misma transaccion: al
            // hacer commit, otro proceso ya no las ve como pendientes.
            $this->markSending(array_column($rows, 'reminder_id'));

            $this->pdo->commit();

            return $rows;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    private function claimWithUpdate(int $limit): array
    {
        // Marca de este proceso: identifica que filas reclamo ESTA corrida.
        $marca = bin2hex(random_bytes(8));

        $stmt = $this->pdo->prepare(
            'UPDATE appointment_reminders r
                JOIN appointments a ON a.id = r.appointment_id
                SET r.status = \'sending\', r.last_error = :marca
              WHERE r.status = \'pending\'
                AND r.scheduled_for <= UTC_TIMESTAMP()
                AND r.attempts < :max_attempts
                AND a.status IN (\'pending\',\'confirmed\',\'cancelled\')
              ORDER BY r.scheduled_for
              LIMIT :max_rows'
        );
        $stmt->bindValue('marca', $marca);
        $stmt->bindValue('max_attempts', self::MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue('max_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            $this->dueQuery(claimed: true) . ' ORDER BY r.scheduled_for'
        );
        $stmt->bindValue('marca', $marca);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Consulta base de la cola.
     *
     * Solo se traen avisos de citas VIVAS: no tiene sentido recordarle a
     * nadie una cita que ya cancelo. El aviso de cancelacion es la
     * excepcion, porque justamente se manda cuando la cita esta cancelada.
     */
    private function dueQuery(bool $claimed = false): string
    {
        $filtro = $claimed
            ? "r.status = 'sending' AND r.last_error = :marca"
            : "r.status = 'pending'
                AND r.scheduled_for <= UTC_TIMESTAMP()
                AND r.attempts < :max_attempts
                AND (
                    (r.kind = 'cancellation' AND a.status = 'cancelled')
                    OR (r.kind <> 'cancellation'
                        AND a.status IN ('pending','confirmed')
                        AND a.starts_at > UTC_TIMESTAMP())
                )";

        return "SELECT r.id AS reminder_id, r.kind, r.attempts, r.scheduled_for,
                       a.id, a.starts_at, a.ends_at, a.duration_minutes, a.price,
                       a.status, a.public_token, a.customer_notes,
                       a.cancelled_by, a.cancel_reason,
                       c.name AS customer_name, c.email AS customer_email,
                       c.phone AS customer_phone,
                       s.name AS service_name,
                       e.name AS employee_name,
                       b.id AS business_id, b.name AS business_name,
                       b.email AS business_email, b.phone AS business_phone,
                       b.timezone AS business_timezone, b.currency
                  FROM appointment_reminders r
                  JOIN appointments a ON a.id = r.appointment_id
                  JOIN customers   c ON c.id = a.customer_id
                  JOIN services    s ON s.id = a.service_id
                  JOIN employees   e ON e.id = a.employee_id
                  JOIN businesses  b ON b.id = a.business_id
                 WHERE {$filtro}";
    }

    /** @param list<int|string> $ids */
    private function markSending(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $this->pdo
            ->prepare("UPDATE appointment_reminders SET status = 'sending' WHERE id IN ({$marcadores})")
            ->execute(array_map('intval', $ids));
    }

    // =================================================================
    //  Envio
    // =================================================================

    /**
     * Envia un aviso y actualiza su fila.
     *
     * Nunca lanza: un fallo de correo no debe tumbar el cron ni la
     * peticion web que reservo la cita. El error se guarda en la fila.
     *
     * @param array<string,mixed> $row Fila devuelta por claimDue()
     */
    public function deliver(array $row): string
    {
        $kind = (string) $row['kind'];

        if (!isset(self::TEMPLATES[$kind])) {
            $this->markSkipped((int) $row['reminder_id'], 'Tipo de aviso desconocido: ' . $kind);

            return self::RESULT_SKIPPED;
        }

        try {
            $this->mail->send(
                toEmail:  (string) $row['customer_email'],
                toName:   (string) $row['customer_name'],
                subject:  $row['business_name'] . ': ' . self::SUBJECTS[$kind],
                template: self::TEMPLATES[$kind],
                data:     $this->buildData($row),
            );

            $this->markSent((int) $row['reminder_id']);

            return self::RESULT_SENT;
        } catch (MailRateLimitException $e) {
            // -------------------------------------------------------------
            //  Limitacion de tasa: NO cuenta como intento
            // -------------------------------------------------------------
            //  El mensaje esta bien y el destinatario tambien; lo unico que
            //  pasa es que el servidor nos pide ir mas despacio. Sumar un
            //  intento aqui castigaria al aviso por un problema que no es
            //  suyo: tres recordatorios seguidos agotarian los tres
            //  reintentos de uno de ellos y acabaria en 'failed' definitivo,
            //  sin llegar nunca, por algo que se resolvia esperando.
            //
            //  Se devuelve a la cola con el contador intacto.
            // -------------------------------------------------------------
            $this->markRateLimited((int) $row['reminder_id'], $e->getMessage());

            return self::RESULT_RATE_LIMITED;
        } catch (Throwable $e) {
            $this->markFailed(
                (int) $row['reminder_id'],
                (int) $row['attempts'] + 1,
                $e->getMessage(),
            );

            return self::RESULT_FAILED;
        }
    }

    /**
     * Avisa al negocio de una reserva nueva.
     *
     * No pasa por la cola: es un aviso interno, y si se pierde no afecta al
     * cliente. Se manda directo y se traga cualquier error.
     */
    /**
     * Busca la cita y avisa al negocio.
     *
     * La consulta vive aqui y no en el controlador: quien envia el correo
     * es quien sabe que campos necesita su plantilla. Si manana el aviso
     * incluye, por ejemplo, cuantas veces ha venido ese cliente, se toca
     * un solo archivo.
     */
    public function notifyBusinessFor(int $appointmentId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.starts_at, a.ends_at, a.duration_minutes, a.price,
                    a.status, a.public_token, a.customer_notes,
                    a.cancelled_by, a.cancel_reason,
                    -- buildData() se apoya en "kind" para elegir el asunto.
                    -- Este aviso no sale de la cola, asi que se fija a mano.
                    :kind AS kind,
                    c.name AS customer_name, c.email AS customer_email,
                    c.phone AS customer_phone,
                    s.name AS service_name,
                    e.name AS employee_name,
                    b.id AS business_id, b.name AS business_name,
                    b.email AS business_email, b.phone AS business_phone,
                    b.timezone AS business_timezone, b.currency
               FROM appointments a
               JOIN customers   c ON c.id = a.customer_id
               JOIN services    s ON s.id = a.service_id
               JOIN employees   e ON e.id = a.employee_id
               JOIN businesses  b ON b.id = a.business_id
              WHERE a.id = :id'
        );
        $stmt->execute(['id' => $appointmentId, 'kind' => 'confirmation']);

        $row = $stmt->fetch();

        return $row === false ? false : $this->notifyBusiness($row);
    }

    public function notifyBusiness(array $row): bool
    {
        $destino = (string) ($this->settings['mail']['notify_to'] ?: $row['business_email']);

        if ($destino === '') {
            return false;
        }

        try {
            $this->mail->send(
                toEmail:  $destino,
                toName:   (string) $row['business_name'],
                subject:  sprintf('Nueva reserva: %s, %s', $row['customer_name'], $row['service_name']),
                template: 'business_notification',
                data:     $this->buildData($row) + [
                    'enlacePanel' => $this->settings['app']['url'] . '/admin/calendario',
                ],
            );

            return true;
        } catch (Throwable $e) {
            error_log('[notificacion negocio] ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Procesa los avisos pendientes de UNA cita, ahora mismo.
     *
     * La llaman los controladores tras reservar o cancelar. Best effort: si
     * falla, la fila sigue pendiente y el cron lo reintentara.
     */
    public function flushFor(int $appointmentId): int
    {
        $stmt = $this->pdo->prepare(
            $this->dueQuery() . ' AND r.appointment_id = :appointment_id ORDER BY r.scheduled_for'
        );
        $stmt->bindValue('max_attempts', self::MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue('appointment_id', $appointmentId, PDO::PARAM_INT);
        $stmt->execute();

        $enviados = 0;

        foreach ($stmt->fetchAll() as $row) {
            if ($this->deliver($row) === self::RESULT_SENT) {
                $enviados++;
            }
        }

        return $enviados;
    }

    /**
     * Encola el aviso de cancelacion.
     *
     * INSERT ... ON DUPLICATE KEY UPDATE porque el UNIQUE
     * (appointment_id, kind, channel) puede chocar si la cita ya se
     * cancelo y reabrio alguna vez. Se reactiva la fila existente en vez
     * de fallar.
     */
    public function queueCancellation(int $appointmentId): void
    {
        // Los avisos que ya no tienen sentido se cierran como 'skipped'.
        //
        // La consulta de la cola ya los excluye (no recuerda una cita
        // cancelada), asi que no llegarian a enviarse nunca. Pero se
        // quedarian en 'pending' de por vida, y una cola donde "pendiente"
        // no significa pendiente deja de servir para diagnosticar nada.
        // Se cierran con el motivo escrito.
        $this->pdo->prepare(
            'UPDATE appointment_reminders
                SET status = \'skipped\',
                    last_error = \'La cita se cancelo antes de enviarlo\'
              WHERE appointment_id = :id
                AND kind <> \'cancellation\'
                AND status IN (\'pending\', \'sending\')'
        )->execute(['id' => $appointmentId]);

        $this->pdo->prepare(
            'INSERT INTO appointment_reminders
                (appointment_id, kind, channel, scheduled_for, status, attempts)
             VALUES (:id, \'cancellation\', \'email\', UTC_TIMESTAMP(), \'pending\', 0)
             ON DUPLICATE KEY UPDATE
                status = \'pending\', attempts = 0, sent_at = NULL,
                last_error = NULL, scheduled_for = UTC_TIMESTAMP()'
        )->execute(['id' => $appointmentId]);
    }

    // =================================================================
    //  Datos de la plantilla
    // =================================================================

    /** @return array<string,mixed> */
    private function buildData(array $row): array
    {
        $tz  = new DateTimeZone((string) $row['business_timezone']);
        $utc = new DateTimeZone('UTC');

        $inicio = (new DateTimeImmutable((string) $row['starts_at'], $utc))->setTimezone($tz);
        $fin    = (new DateTimeImmutable((string) $row['ends_at'], $utc))->setTimezone($tz);

        $base = rtrim((string) $this->settings['app']['url'], '/');

        return [
            'cita'              => $row,
            'negocio'           => [
                'name'  => $row['business_name'],
                'phone' => $row['business_phone'],
            ],
            'subject'           => self::SUBJECTS[$row['kind']] ?? '',
            'fechaLarga'        => self::fechaLarga($inicio),
            'horaInicio'        => $inicio->format('H:i'),
            'horaFin'           => $fin->format('H:i'),
            'enlaceGestion'     => $base . '/cita/' . $row['public_token'],
            'enlaceReservar'    => $base . '/reservar',
            'margenCancelacion' => (int) $this->settings['security']['cancel_min_notice_minutes'],
        ];
    }

    private static function fechaLarga(DateTimeImmutable $d): string
    {
        static $dias  = ['Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];
        static $meses = ['','enero','febrero','marzo','abril','mayo','junio','julio',
                         'agosto','septiembre','octubre','noviembre','diciembre'];

        return sprintf(
            '%s %d de %s de %d',
            $dias[(int) $d->format('w')],
            (int) $d->format('j'),
            $meses[(int) $d->format('n')],
            (int) $d->format('Y'),
        );
    }

    // =================================================================
    //  Cambios de estado de la cola
    // =================================================================

    private function markSent(int $reminderId): void
    {
        $this->pdo->prepare(
            'UPDATE appointment_reminders
                SET status = \'sent\', sent_at = UTC_TIMESTAMP(),
                    attempts = attempts + 1, last_error = NULL
              WHERE id = :id'
        )->execute(['id' => $reminderId]);
    }

    private function markFailed(int $reminderId, int $attempts, string $error): void
    {
        // Al agotar los reintentos la fila queda en 'failed' definitivo.
        // Antes vuelve a 'pending' para que el cron la recoja otra vez.
        $estado = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

        $this->pdo->prepare(
            'UPDATE appointment_reminders
                SET status = :status, attempts = :attempts, last_error = :error
              WHERE id = :id'
        )->execute([
            'status'   => $estado,
            'attempts' => $attempts,
            // La columna admite 500 caracteres; los dialogos SMTP son largos.
            'error'    => mb_substr($error, 0, 500),
            'id'       => $reminderId,
        ]);
    }

    /**
     * Devuelve el aviso a la cola sin gastarle un intento.
     *
     * Se guarda el motivo en last_error igualmente: si el limite persiste,
     * quien mire la tabla ve por que no sale nada, en vez de encontrarse
     * filas pendientes sin explicacion.
     */
    private function markRateLimited(int $reminderId, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE appointment_reminders
                SET status = 'pending', last_error = :error
              WHERE id = :id"
        )->execute([
            'error' => mb_substr($error, 0, 500),
            'id'    => $reminderId,
        ]);
    }

    private function markSkipped(int $reminderId, string $motivo): void
    {
        $this->pdo->prepare(
            'UPDATE appointment_reminders
                SET status = \'skipped\', last_error = :error WHERE id = :id'
        )->execute(['error' => mb_substr($motivo, 0, 500), 'id' => $reminderId]);
    }

    /**
     * Devuelve las filas atascadas en 'sending' a la cola.
     *
     * Si el proceso muere entre reclamar y enviar (corte de luz, timeout
     * del hosting), esas filas se quedarian bloqueadas para siempre. El
     * cron las libera al arrancar.
     */
    public function requeueStale(int $minutes = 15): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE appointment_reminders
                SET status = \'pending\', last_error = NULL
              WHERE status = \'sending\'
                AND scheduled_for < UTC_TIMESTAMP() - INTERVAL :minutes MINUTE'
        );
        $stmt->bindValue('minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function supportsSkipLocked(): bool
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();

        if (stripos($version, 'mariadb') !== false) {
            // MariaDB lo soporta desde 10.6.
            preg_match('/^(\d+)\.(\d+)/', $version, $m);
            $cache = isset($m[1], $m[2])
                && ((int) $m[1] > 10 || ((int) $m[1] === 10 && (int) $m[2] >= 6));
        } else {
            // MySQL, desde 8.0.
            $cache = version_compare($version, '8.0.0', '>=');
        }

        return $cache;
    }

    public function engineInfo(): string
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();

        return sprintf(
            '%s (SKIP LOCKED: %s)',
            $version,
            $this->supportsSkipLocked() ? 'si' : 'no, se usa bloqueo por UPDATE',
        );
    }
}
