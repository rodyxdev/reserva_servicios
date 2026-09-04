<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TimeRange;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Horarios laborales y excepciones de calendario.
 *
 * Resuelve la pregunta "que tramos puede trabajar este empleado este dia",
 * que es la entrada del motor de disponibilidad.
 */
final class ScheduleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Tramos trabajables efectivos, ya en UTC.
     *
     * Las reglas, en orden de aplicacion:
     *
     *  1. Se parte del horario del negocio para ese dia de la semana.
     *  2. Una excepcion del negocio (employee_id NULL) lo cierra por
     *     completo o lo sustituye por un horario alterno.
     *  3. El empleado usa su propio horario si lo tiene definido; si no
     *     tiene ninguna fila, HEREDA el del negocio.
     *  4. Una excepcion suya lo cierra o lo sustituye.
     *  5. El resultado es la interseccion de lo que queda: el empleado no
     *     puede trabajar cuando el negocio esta cerrado, aunque su horario
     *     personal diga lo contrario.
     *
     * @return list<TimeRange>
     */
    public function workingRanges(
        int $businessId,
        int $employeeId,
        DateTimeImmutable $localDate,
        DateTimeZone $tz,
    ): array {
        // 'N' devuelve 1=Lunes..7=Domingo, que es justo como se guarda
        // weekday en la base. No se usa 'w' (0=Domingo), que obligaria a
        // convertir en cada consulta y es fuente inagotable de errores.
        $weekday = (int) $localDate->format('N');
        $dateStr = $localDate->format('Y-m-d');

        // ---- 1 y 2: negocio -------------------------------------------
        $businessRanges = $this->businessHours($businessId, $weekday);
        $businessExc    = $this->exception($businessId, null, $dateStr);

        if ($businessExc !== null) {
            if ((bool) $businessExc['is_closed']) {
                return [];
            }

            $businessRanges = [[$businessExc['starts_at'], $businessExc['ends_at']]];
        }

        if ($businessRanges === []) {
            return [];
        }

        // ---- 3 y 4: empleado ------------------------------------------
        $employeeRanges = $this->employeeHours($employeeId, $weekday);

        // Sin filas propias = hereda. Es distinto de "tiene filas pero
        // ninguna este dia", que significa que ese dia NO trabaja.
        if ($employeeRanges === [] && !$this->hasOwnSchedule($employeeId)) {
            $employeeRanges = $businessRanges;
        }

        if ($employeeRanges === []) {
            return [];
        }

        $employeeExc = $this->exception($businessId, $employeeId, $dateStr);

        if ($employeeExc !== null) {
            if ((bool) $employeeExc['is_closed']) {
                return [];
            }

            $employeeRanges = [[$employeeExc['starts_at'], $employeeExc['ends_at']]];
        }

        // ---- 5: interseccion, ya convertida a UTC ----------------------
        return TimeRange::intersectLists(
            $this->toUtcRanges($businessRanges, $dateStr, $tz),
            $this->toUtcRanges($employeeRanges, $dateStr, $tz),
        );
    }

    /** @return list<array{0:string,1:string}> pares TIME locales */
    private function businessHours(int $businessId, int $weekday): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT opens_at, closes_at
               FROM business_hours
              WHERE business_id = :business_id AND weekday = :weekday
              ORDER BY opens_at'
        );
        $stmt->execute(['business_id' => $businessId, 'weekday' => $weekday]);

        return array_map(
            static fn (array $r): array => [$r['opens_at'], $r['closes_at']],
            $stmt->fetchAll(),
        );
    }

    /** @return list<array{0:string,1:string}> */
    private function employeeHours(int $employeeId, int $weekday): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT starts_at, ends_at
               FROM employee_hours
              WHERE employee_id = :employee_id AND weekday = :weekday
              ORDER BY starts_at'
        );
        $stmt->execute(['employee_id' => $employeeId, 'weekday' => $weekday]);

        return array_map(
            static fn (array $r): array => [$r['starts_at'], $r['ends_at']],
            $stmt->fetchAll(),
        );
    }

    private function hasOwnSchedule(int $employeeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM employee_hours WHERE employee_id = :employee_id LIMIT 1'
        );
        $stmt->execute(['employee_id' => $employeeId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Excepcion para una fecha. $employeeId null busca la del negocio.
     *
     * @return array{is_closed:int,starts_at:?string,ends_at:?string}|null
     */
    private function exception(int $businessId, ?int $employeeId, string $date): ?array
    {
        $sql = 'SELECT is_closed, starts_at, ends_at
                  FROM schedule_exceptions
                 WHERE business_id = :business_id
                   AND exc_date = :exc_date
                   AND ' . ($employeeId === null ? 'employee_id IS NULL' : 'employee_id = :employee_id') . '
                 ORDER BY id
                 LIMIT 1';

        $params = ['business_id' => $businessId, 'exc_date' => $date];

        if ($employeeId !== null) {
            $params['employee_id'] = $employeeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        // Una excepcion marcada como abierta pero sin horas es incoherente.
        // Se trata como cierre: es la interpretacion segura, porque el error
        // opuesto seria ofrecer citas en un dia que el negocio quiso cerrar.
        if (!$row['is_closed'] && ($row['starts_at'] === null || $row['ends_at'] === null)) {
            $row['is_closed'] = 1;
        }

        return $row;
    }

    /**
     * Convierte pares de TIME locales en intervalos absolutos UTC.
     *
     * Aqui es donde se cruza la frontera entre "las 10 de la manana en el
     * spa" y un instante en la linea del tiempo. Se construye la fecha en
     * la zona del negocio y se pasa a UTC: PHP aplica el desplazamiento que
     * corresponda a ESA fecha, incluido el horario de verano si la zona lo
     * tiene.
     *
     * @param  list<array{0:string,1:string}> $ranges
     * @return list<TimeRange>
     */
    private function toUtcRanges(array $ranges, string $date, DateTimeZone $tz): array
    {
        $utc = new DateTimeZone('UTC');
        $out = [];

        foreach ($ranges as [$from, $to]) {
            $start = new DateTimeImmutable($date . ' ' . $from, $tz);
            $end   = new DateTimeImmutable($date . ' ' . $to, $tz);

            // Un tramo que cruza medianoche (23:00 a 02:00) tendria fin
            // anterior al inicio: se le suma un dia.
            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }

            $out[] = new TimeRange($start->setTimezone($utc), $end->setTimezone($utc));
        }

        return $out;
    }
}
