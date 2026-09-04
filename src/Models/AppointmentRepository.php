<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use PDO;

/**
 * Acceso a citas y a la malla de bloqueo.
 *
 * Toda consulta usa sentencias preparadas con parametros con nombre. En
 * este archivo no hay una sola concatenacion de valores en SQL.
 */
final class AppointmentRepository
{
    /** Estados que siguen ocupando la agenda. */
    public const LIVE_STATUSES = ['pending', 'confirmed', 'completed', 'no_show'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Bloques ocupados de un empleado en una ventana de tiempo.
     *
     * Devuelve un set (clave => true) en vez de una lista para que la
     * comprobacion en el motor sea O(1) por bloque. Con una lista habria
     * que recorrerla por cada horario candidato, y el coste se multiplica.
     *
     * Se lee de appointment_slots y no de appointments porque la malla ya
     * tiene el buffer aplicado y el indice (employee_id, slot_at) resuelve
     * el rango sin tocar la tabla de citas.
     *
     * @return array<string,true>
     */
    public function busyBlocks(int $employeeId, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slot_at
               FROM appointment_slots
              WHERE employee_id = :employee_id
                AND slot_at >= :from_at
                AND slot_at <  :to_at'
        );

        $stmt->execute([
            'employee_id' => $employeeId,
            'from_at'     => $fromUtc->format('Y-m-d H:i:s'),
            'to_at'       => $toUtc->format('Y-m-d H:i:s'),
        ]);

        $busy = [];

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slotAt) {
            $busy[(string) $slotAt] = true;
        }

        return $busy;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id, int $businessId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, c.name AS customer_name, c.email AS customer_email,
                    c.phone AS customer_phone, s.name AS service_name,
                    e.name AS employee_name
               FROM appointments a
               JOIN customers c ON c.id = a.customer_id
               JOIN services  s ON s.id = a.service_id
               JOIN employees e ON e.id = a.employee_id
              WHERE a.id = :id AND a.business_id = :business_id'
        );
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);

        return $stmt->fetch() ?: null;
    }

    /** Busqueda por el token publico que viaja en los correos. */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, c.name AS customer_name, c.email AS customer_email,
                    c.phone AS customer_phone,
                    s.name AS service_name, s.description AS service_description,
                    e.name AS employee_name, e.role_title AS employee_role,
                    b.name AS business_name, b.timezone AS business_timezone,
                    b.currency, b.phone AS business_phone, b.email AS business_email,
                    -- Lo usa la pagina publica para decidir si el cliente
                    -- aun esta a tiempo de cancelar por su cuenta.
                    b.min_advance_minutes
               FROM appointments a
               JOIN customers  c ON c.id = a.customer_id
               JOIN services   s ON s.id = a.service_id
               JOIN employees  e ON e.id = a.employee_id
               JOIN businesses b ON b.id = a.business_id
              WHERE a.public_token = :token'
        );
        $stmt->execute(['token' => $token]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Citas dentro de un rango, para el calendario del panel.
     *
     * @return list<array<string,mixed>>
     */
    public function betweenDates(
        int $businessId,
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtc,
        ?int $employeeId = null,
    ): array {
        $sql = 'SELECT a.id, a.starts_at, a.ends_at, a.blocked_until, a.status,
                       a.employee_id, a.service_id, a.price,
                       c.name AS customer_name, c.phone AS customer_phone,
                       s.name AS service_name, s.color,
                       e.name AS employee_name
                  FROM appointments a
                  JOIN customers c ON c.id = a.customer_id
                  JOIN services  s ON s.id = a.service_id
                  JOIN employees e ON e.id = a.employee_id
                 WHERE a.business_id = :business_id
                   AND a.starts_at >= :from_at
                   AND a.starts_at <  :to_at';

        $params = [
            'business_id' => $businessId,
            'from_at'     => $fromUtc->format('Y-m-d H:i:s'),
            'to_at'       => $toUtc->format('Y-m-d H:i:s'),
        ];

        if ($employeeId !== null) {
            $sql .= ' AND a.employee_id = :employee_id';
            $params['employee_id'] = $employeeId;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY a.starts_at');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Cambia el estado de una cita y deja rastro en la auditoria.
     *
     * Cuando el nuevo estado es 'cancelled' se liberan sus bloques: el
     * horario vuelve a estar disponible de inmediato, que es exactamente
     * lo que significa cancelar. Los demas estados (completed, no_show)
     * conservan la malla, porque ese tiempo si se consumio.
     */
    public function changeStatus(
        int $appointmentId,
        string $newStatus,
        ?int $userId = null,
        ?string $note = null,
        ?string $cancelledBy = null,
    ): bool {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT status FROM appointments WHERE id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $appointmentId]);
            $current = $stmt->fetchColumn();

            if ($current === false || $current === $newStatus) {
                $this->pdo->rollBack();

                return false;
            }

            if ($newStatus === 'cancelled') {
                $stmt = $this->pdo->prepare(
                    'UPDATE appointments
                        SET status = :status, cancelled_at = UTC_TIMESTAMP(),
                            cancelled_by = :cancelled_by, cancel_reason = :reason
                      WHERE id = :id'
                );
                $stmt->execute([
                    'status'       => $newStatus,
                    'cancelled_by' => $cancelledBy ?? 'staff',
                    'reason'       => $note,
                    'id'           => $appointmentId,
                ]);

                $this->pdo
                    ->prepare('DELETE FROM appointment_slots WHERE appointment_id = :id')
                    ->execute(['id' => $appointmentId]);
            } else {
                $stmt = $this->pdo->prepare(
                    'UPDATE appointments SET status = :status WHERE id = :id'
                );
                $stmt->execute(['status' => $newStatus, 'id' => $appointmentId]);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO appointment_status_log
                    (appointment_id, from_status, to_status, changed_by_user_id, note)
                 VALUES (:appointment_id, :from_status, :to_status, :user_id, :note)'
            );
            $stmt->execute([
                'appointment_id' => $appointmentId,
                'from_status'    => $current,
                'to_status'      => $newStatus,
                'user_id'        => $userId,
                'note'           => $note,
            ]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Citas que necesitan recordatorio y aun no lo tienen encolado.
     *
     * La usa scripts/send-reminders.php. La idempotencia real la da el
     * UNIQUE de appointment_reminders; este filtro solo evita trabajo.
     *
     * @return list<array<string,mixed>>
     */
    public function needingReminder(int $hoursAhead = 24, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.starts_at, a.public_token,
                    c.name AS customer_name, c.email AS customer_email,
                    s.name AS service_name, e.name AS employee_name,
                    b.name AS business_name, b.timezone AS business_timezone
               FROM appointments a
               JOIN customers  c ON c.id = a.customer_id
               JOIN services   s ON s.id = a.service_id
               JOIN employees  e ON e.id = a.employee_id
               JOIN businesses b ON b.id = a.business_id
          LEFT JOIN appointment_reminders r
                 ON r.appointment_id = a.id
                AND r.kind = :kind
                AND r.channel = :channel
              WHERE a.status IN (\'pending\', \'confirmed\')
                AND a.starts_at >  UTC_TIMESTAMP()
                AND a.starts_at <= UTC_TIMESTAMP() + INTERVAL :hours HOUR
                AND r.id IS NULL
              ORDER BY a.starts_at
              LIMIT :max_rows'
        );

        // bindValue explicito: LIMIT no acepta una cadena, y con
        // EMULATE_PREPARES en false PDO no castea por su cuenta.
        $stmt->bindValue('kind', 'reminder_24h', PDO::PARAM_STR);
        $stmt->bindValue('channel', 'email', PDO::PARAM_STR);
        $stmt->bindValue('hours', $hoursAhead, PDO::PARAM_INT);
        $stmt->bindValue('max_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
