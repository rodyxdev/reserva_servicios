<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppointmentRepository;
use App\Models\CatalogRepository;
use App\Models\CustomerRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Excepcion de negocio: la reserva no se pudo completar por una razon que
 * el cliente puede entender y, casi siempre, corregir.
 *
 * Se distingue de un error tecnico a proposito: estas se muestran, las
 * otras se registran y se responde con un mensaje generico.
 */
final class BookingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid',
    ) {
        parent::__construct($message);
    }
}

/**
 * Creacion de citas.
 *
 * Aqui vive la parte que no puede fallar: la transaccion que garantiza que
 * dos clientes no se lleven el mismo hueco.
 */
final class AppointmentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CatalogRepository $catalog,
        private readonly CustomerRepository $customers,
        private readonly AppointmentRepository $appointments,
        private readonly AvailabilityService $availability,
        private readonly int $blockMinutes = 5,
    ) {
    }

    /**
     * Reserva una cita.
     *
     * @param  int|null $employeeId null = "sin preferencia": lo resuelve el sistema
     * @return array{id:int, token:string, employee_id:int, starts_at:DateTimeImmutable}
     *
     * @throws BookingException cuando el cliente puede corregir el problema
     */
    public function book(
        int $businessId,
        int $serviceId,
        ?int $employeeId,
        DateTimeImmutable $startUtc,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
        ?string $notes = null,
        string $source = 'public',
        ?DateTimeImmutable $nowUtc = null,
    ): array {
        $nowUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // -----------------------------------------------------------------
        //  1. RELEER el servicio desde la base
        // -----------------------------------------------------------------
        //  Nunca se confia en la duracion, el buffer ni el precio que venga
        //  del formulario. Un POST manipulado podria pedir un masaje de 90
        //  minutos declarando que dura 5 y cuesta 0: bloquearia mal la
        //  agenda y falsearia el reporte de ingresos. La unica fuente de
        //  verdad es la fila de services.
        // -----------------------------------------------------------------
        $business = $this->catalog->business($businessId)
            ?? throw new BookingException('El negocio no esta disponible.', 'business_inactive');

        $service = $this->catalog->service($serviceId, $businessId)
            ?? throw new BookingException('El servicio seleccionado ya no esta disponible.', 'service_inactive');

        $duration = (int) $service['duration_minutes'];
        $buffer   = (int) ($service['buffer_minutes'] ?: $business['default_buffer_minutes']);
        $price    = (float) $service['price'];

        // -----------------------------------------------------------------
        //  2. Alinear el inicio a la malla de bloqueo
        // -----------------------------------------------------------------
        //  Si llegara un inicio a las 10:03, sus bloques no coincidirian
        //  con los de las citas existentes y dos citas podrian solaparse
        //  sin que ningun UNIQUE lo notara. La malla solo protege si todo
        //  el mundo esta alineado a ella.
        $startUtc = $this->alignToGrid($startUtc);

        // -----------------------------------------------------------------
        //  3. "Sin preferencia": elegir empleado
        // -----------------------------------------------------------------
        if ($employeeId === null) {
            $candidates = array_map(
                static fn (array $e): int => (int) $e['id'],
                $this->catalog->employeesForService($serviceId, $businessId),
            );

            if ($candidates === []) {
                throw new BookingException(
                    'No hay personal disponible para este servicio.',
                    'no_staff',
                );
            }

            $employeeId = $this->availability->firstAvailableEmployee(
                $candidates,
                $business,
                $service,
                $startUtc,
                $nowUtc,
            );

            if ($employeeId === null) {
                throw new BookingException(
                    'Ese horario ya no esta disponible. Elige otro, por favor.',
                    'slot_taken',
                );
            }
        } else {
            // Empleado explicito: hay que verificar que preste el servicio.
            // Sin esta comprobacion, un POST manipulado agenda a la
            // manicurista para un masaje descontracturante.
            if (!$this->catalog->employeeOffersService($employeeId, $serviceId, $businessId)) {
                throw new BookingException(
                    'Ese profesional no ofrece el servicio seleccionado.',
                    'employee_mismatch',
                );
            }
        }

        // -----------------------------------------------------------------
        //  4. Validar contra horario laboral y politicas
        // -----------------------------------------------------------------
        //  Esta comprobacion NO previene la carrera entre dos peticiones
        //  simultaneas: de eso se encarga el paso 6. Lo que hace es
        //  rechazar pronto y con un mensaje util lo que nunca fue valido
        //  (un domingo, las 3 de la madrugada, dentro del margen minimo de
        //  anticipacion), sin gastar una transaccion.
        $tz        = new DateTimeZone((string) $business['timezone']);
        $localDate = $startUtc->setTimezone($tz)->setTime(0, 0);

        $offered = $this->availability->slotsFor($business, $employeeId, $service, $localDate, $nowUtc);
        $target  = $startUtc->format('Y-m-d H:i:s');

        $isOffered = false;

        foreach ($offered as $slot) {
            if ($slot->format('Y-m-d H:i:s') === $target) {
                $isOffered = true;
                break;
            }
        }

        if (!$isOffered) {
            throw new BookingException(
                'Ese horario no esta disponible. Elige otro, por favor.',
                'slot_unavailable',
            );
        }

        // -----------------------------------------------------------------
        //  5 y 6. Transaccion: cita + malla de bloqueo, todo o nada
        // -----------------------------------------------------------------
        $endUtc     = $startUtc->modify(sprintf('+%d minutes', $duration));
        $blockedEnd = $endUtc->modify(sprintf('+%d minutes', $buffer));
        $token      = bin2hex(random_bytes(16));
        $status     = ((int) $business['auto_confirm'] === 1) ? 'confirmed' : 'pending';

        $this->pdo->beginTransaction();

        try {
            $customerId = $this->customers->findOrCreate(
                $businessId,
                $customerName,
                $customerEmail,
                $customerPhone,
            );

            // blocked_until NO se inserta: es columna generada.
            $stmt = $this->pdo->prepare(
                'INSERT INTO appointments
                    (business_id, customer_id, service_id, employee_id,
                     starts_at, ends_at, duration_minutes, buffer_minutes, price,
                     status, source, customer_notes, public_token)
                 VALUES
                    (:business_id, :customer_id, :service_id, :employee_id,
                     :starts_at, :ends_at, :duration_minutes, :buffer_minutes, :price,
                     :status, :source, :customer_notes, :public_token)'
            );

            $stmt->execute([
                'business_id'      => $businessId,
                'customer_id'      => $customerId,
                'service_id'       => $serviceId,
                'employee_id'      => $employeeId,
                'starts_at'        => $startUtc->format('Y-m-d H:i:s'),
                'ends_at'          => $endUtc->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration,
                'buffer_minutes'   => $buffer,
                'price'            => $price,
                'status'           => $status,
                'source'           => $source,
                'customer_notes'   => $notes,
                'public_token'     => $token,
            ]);

            $appointmentId = (int) $this->pdo->lastInsertId();

            // -------------------------------------------------------------
            //  EL CANDADO
            // -------------------------------------------------------------
            //  Se inserta una fila por cada bloque de 5 minutos entre el
            //  inicio y el fin del bloqueo (servicio + buffer). La PRIMARY
            //  KEY (employee_id, slot_at) hace el resto: si otra peticion
            //  se adelanto aunque fuera por milisegundos, uno de estos
            //  INSERT choca y MySQL devuelve el error 1062.
            //
            //  La garantia la da el motor, no este codigo. Da igual cuantos
            //  procesos PHP corran a la vez, si estan en servidores
            //  distintos o si el navegador mando el formulario dos veces:
            //  la base no puede aceptar dos citas solapadas.
            // -------------------------------------------------------------
            $slotStmt = $this->pdo->prepare(
                'INSERT INTO appointment_slots (employee_id, slot_at, appointment_id)
                 VALUES (:employee_id, :slot_at, :appointment_id)'
            );

            foreach ($this->gridBlocks($startUtc, $blockedEnd) as $block) {
                $slotStmt->execute([
                    'employee_id'    => $employeeId,
                    'slot_at'        => $block->format('Y-m-d H:i:s'),
                    'appointment_id' => $appointmentId,
                ]);
            }

            // Confirmacion y recordatorio de 24h quedan encolados en la
            // misma transaccion: si la cita existe, sus avisos existen.
            $this->queueNotifications($appointmentId, $startUtc);

            $this->pdo->commit();

            return [
                'id'          => $appointmentId,
                'token'       => $token,
                'employee_id' => $employeeId,
                'starts_at'   => $startUtc,
                'ends_at'     => $endUtc,
                'status'      => $status,
                'price'       => $price,
            ];
        } catch (PDOException $e) {
            $this->pdo->rollBack();

            // 23000 = violacion de integridad; 1062 = clave duplicada.
            // Aqui solo puede venir de appointment_slots: alguien gano la
            // carrera entre la comprobacion del paso 4 y este INSERT.
            //
            // Es la ruta que de verdad importa: NO es un error del sistema,
            // es el mecanismo funcionando. Se traduce a un mensaje que el
            // cliente entiende.
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), '1062')) {
                throw new BookingException(
                    'Ese horario acaba de ser reservado por otra persona. '
                    . 'Elige otro, por favor.',
                    'slot_taken',
                );
            }

            error_log('[booking] fallo al reservar: ' . $e->getMessage());

            throw new RuntimeException('No se pudo completar la reserva.', previous: $e);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Redondea hacia abajo al bloque de la malla mas cercano.
     *
     * Hacia abajo y no hacia arriba: mover el inicio hacia adelante podria
     * empujar el fin fuera del horario laboral y hacer fallar una reserva
     * que era valida.
     */
    private function alignToGrid(DateTimeImmutable $when): DateTimeImmutable
    {
        $minute  = (int) $when->format('i');
        $aligned = intdiv($minute, $this->blockMinutes) * $this->blockMinutes;

        return $when->setTime((int) $when->format('H'), $aligned, 0);
    }

    /**
     * Bloques de la malla que cubren [inicio, fin).
     *
     * @return list<DateTimeImmutable>
     */
    private function gridBlocks(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $blocks = [];
        $cursor = $from;

        // Comparacion estricta: el bloque que empieza justo en $to NO se
        // incluye. Es lo que permite que la siguiente cita arranque
        // exactamente cuando termina el buffer de esta.
        while ($cursor->getTimestamp() < $to->getTimestamp()) {
            $blocks[] = $cursor;
            $cursor   = $cursor->modify(sprintf('+%d minutes', $this->blockMinutes));
        }

        return $blocks;
    }

    /** Encola la confirmacion inmediata y el recordatorio de 24 horas. */
    private function queueNotifications(int $appointmentId, DateTimeImmutable $startUtc): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO appointment_reminders
                (appointment_id, kind, channel, scheduled_for, status)
             VALUES (:appointment_id, :kind, \'email\', :scheduled_for, \'pending\')'
        );

        $stmt->execute([
            'appointment_id' => $appointmentId,
            'kind'           => 'confirmation',
            'scheduled_for'  => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s'),
        ]);

        $remindAt = $startUtc->modify('-24 hours');

        // Si la cita es para dentro de menos de 24 horas, el recordatorio
        // no tiene sentido: nace como 'skipped' para que el cron no lo
        // arrastre eternamente y quede constancia de por que no se envio.
        $isPast = $remindAt->getTimestamp() <= time();

        $stmt->execute([
            'appointment_id' => $appointmentId,
            'kind'           => 'reminder_24h',
            'scheduled_for'  => $remindAt->format('Y-m-d H:i:s'),
        ]);

        if ($isPast) {
            $this->pdo
                ->prepare(
                    'UPDATE appointment_reminders SET status = \'skipped\',
                            last_error = \'La cita se creo con menos de 24h de antelacion\'
                      WHERE appointment_id = :id AND kind = \'reminder_24h\''
                )
                ->execute(['id' => $appointmentId]);
        }
    }
}
