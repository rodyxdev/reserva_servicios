<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppointmentRepository;
use App\Models\ScheduleRepository;
use App\Support\TimeRange;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Calculo de disponibilidad real.
 *
 * La formula, en una linea:
 *
 *   (horario del negocio  x  horario del empleado)
 *      - excepciones de calendario
 *      - bloques ya ocupados (servicio + buffer de las citas existentes)
 *      - politicas de anticipacion minima y maxima
 *
 * Ninguna parte esta escrita a mano en el codigo: todo sale de la base.
 *
 * ------------------------------------------------------------------
 *  DOS GRANULARIDADES DISTINTAS, NO CONFUNDIRLAS
 * ------------------------------------------------------------------
 *  - $granularityMinutes (businesses.slot_granularity_minutes): cada
 *    cuanto se OFRECE un inicio de cita. Si es 15, el cliente ve 10:00,
 *    10:15, 10:30... Es una decision comercial.
 *
 *  - $blockMinutes (config 'slot_block_minutes', 5): el tamano del bloque
 *    que se materializa en appointment_slots. Es la resolucion del motor.
 *    Tiene que ser fino para que un servicio de 45 minutos con 10 de
 *    buffer se represente exacto.
 *
 *  La comprobacion de ocupacion se hace SIEMPRE en bloques, nunca en la
 *  granularidad comercial. Si se hiciera al reves, una cita de 45 minutos
 *  empezada a las 10:00 dejaria "libre" las 10:45 en una malla de 30, y el
 *  sistema ofreceria un horario imposible.
 * ------------------------------------------------------------------
 */
final class AvailabilityService
{
    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly AppointmentRepository $appointments,
        private readonly int $blockMinutes = 5,
    ) {
    }

    /**
     * Horarios de inicio disponibles para un empleado, un servicio y un dia.
     *
     * @param  array<string,mixed> $business Fila de businesses
     * @param  array<string,mixed> $service  Fila de services
     * @param  DateTimeImmutable   $localDate Dia a consultar, en hora LOCAL del negocio
     * @return list<DateTimeImmutable> Instantes de inicio, en UTC
     */
    public function slotsFor(
        array $business,
        int $employeeId,
        array $service,
        DateTimeImmutable $localDate,
        ?DateTimeImmutable $nowUtc = null,
    ): array {
        $tz    = new DateTimeZone((string) $business['timezone']);
        $nowUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // 1. Horario efectivo: negocio x empleado, ya sin excepciones.
        $working = $this->schedules->workingRanges(
            (int) $business['id'],
            $employeeId,
            $localDate,
            $tz,
        );

        if ($working === []) {
            return [];   // dia cerrado, o el empleado no trabaja ese dia
        }

        // 2. Bloques ya ocupados por citas vivas.
        //
        // Se pide un margen de un dia a cada lado porque una cita puede
        // haber empezado ayer en hora local y seguir ocupando hoy en UTC,
        // o al reves. Es barato: el indice (employee_id, slot_at) lo
        // resuelve con un rango.
        $first = $working[0]->start;
        $last  = $working[count($working) - 1]->end;

        $busy = $this->appointments->busyBlocks(
            $employeeId,
            $first->modify('-1 day'),
            $last->modify('+1 day'),
        );

        // 3. Ventana de politicas: ni demasiado pronto ni demasiado tarde.
        $notBefore = $nowUtc->modify(sprintf('+%d minutes', (int) $business['min_advance_minutes']));
        $notAfter  = $nowUtc->modify(sprintf('+%d days', (int) $business['max_advance_days']));

        $duration = (int) $service['duration_minutes'];
        $buffer   = (int) ($service['buffer_minutes'] ?? $business['default_buffer_minutes'] ?? 0);

        return self::computeSlots(
            working:            $working,
            busyBlocks:         $busy,
            durationMinutes:    $duration,
            bufferMinutes:      $buffer,
            granularityMinutes: (int) $business['slot_granularity_minutes'],
            blockMinutes:       $this->blockMinutes,
            notBefore:          $notBefore,
            notAfter:           $notAfter,
        );
    }

    /**
     * Disponibilidad de varios dias seguidos, para el paso 3 del wizard.
     *
     * No es azucar sintactico sobre slotsFor(): la diferencia esta en el
     * numero de consultas. Llamar a slotsFor() catorce veces hace catorce
     * consultas a appointment_slots, una por dia. Aqui los bloques ocupados
     * de toda la ventana se traen de una sola vez y se reutilizan para
     * todos los dias, porque el set esta indexado por instante absoluto y
     * no le importa a que dia pertenece cada clave.
     *
     * Con 14 dias eso baja de 28 consultas a 15 (una de ocupacion mas una
     * de horario por dia). En hosting compartido, donde cada ida y vuelta
     * a MySQL cuesta, la diferencia se nota.
     *
     * @param  array<string,mixed> $business
     * @param  array<string,mixed> $service
     * @return array<string,list<DateTimeImmutable>> 'Y-m-d' local => huecos en UTC
     */
    public function slotsForRange(
        array $business,
        int $employeeId,
        array $service,
        DateTimeImmutable $fromLocalDate,
        int $days = 14,
        ?DateTimeImmutable $nowUtc = null,
    ): array {
        $tz     = new DateTimeZone((string) $business['timezone']);
        $nowUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $days = max(1, min($days, 62));

        // Horario efectivo de cada dia. Se calcula primero porque de aqui
        // sale la ventana real que hay que consultar: si el negocio abre
        // solo tres de los catorce dias, no tiene sentido pedir la
        // ocupacion de los otros once.
        $working = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $fromLocalDate->modify(sprintf('+%d days', $i));
            $ranges = $this->schedules->workingRanges(
                (int) $business['id'],
                $employeeId,
                $date,
                $tz,
            );

            if ($ranges !== []) {
                $working[$date->format('Y-m-d')] = $ranges;
            }
        }

        if ($working === []) {
            return [];
        }

        // Una sola consulta de ocupacion para toda la ventana.
        $allRanges = array_merge(...array_values($working));
        $first     = $allRanges[0]->start;
        $last      = $allRanges[0]->end;

        foreach ($allRanges as $range) {
            if ($range->startTs() < $first->getTimestamp()) {
                $first = $range->start;
            }
            if ($range->endTs() > $last->getTimestamp()) {
                $last = $range->end;
            }
        }

        $busy = $this->appointments->busyBlocks(
            $employeeId,
            $first->modify('-1 day'),
            $last->modify('+1 day'),
        );

        $notBefore = $nowUtc->modify(sprintf('+%d minutes', (int) $business['min_advance_minutes']));
        $notAfter  = $nowUtc->modify(sprintf('+%d days', (int) $business['max_advance_days']));

        $duration = (int) $service['duration_minutes'];
        $buffer   = (int) ($service['buffer_minutes'] ?: $business['default_buffer_minutes'] ?: 0);

        $result = [];

        foreach ($working as $day => $ranges) {
            $slots = self::computeSlots(
                working:            $ranges,
                busyBlocks:         $busy,
                durationMinutes:    $duration,
                bufferMinutes:      $buffer,
                granularityMinutes: (int) $business['slot_granularity_minutes'],
                blockMinutes:       $this->blockMinutes,
                notBefore:          $notBefore,
                notAfter:           $notAfter,
            );

            if ($slots !== []) {
                $result[$day] = $slots;
            }
        }

        return $result;
    }

    /**
     * Union de la disponibilidad de varios empleados.
     *
     * Es lo que ve el cliente cuando elige "sin preferencia": un horario
     * esta libre si CUALQUIERA de los candidatos puede atenderlo. A que
     * persona le toca se decide al confirmar, no al mostrar, porque entre
     * que se pinta la pantalla y se pulsa el boton pueden pasar minutos y
     * el reparto habria quedado obsoleto.
     *
     * @param  list<int>           $employeeIds
     * @param  array<string,mixed> $business
     * @param  array<string,mixed> $service
     * @return array<string,list<DateTimeImmutable>>
     */
    public function slotsForRangeAnyEmployee(
        array $employeeIds,
        array $business,
        array $service,
        DateTimeImmutable $fromLocalDate,
        int $days = 14,
        ?DateTimeImmutable $nowUtc = null,
    ): array {
        /** @var array<string,array<string,DateTimeImmutable>> $merged */
        $merged = [];

        foreach ($employeeIds as $employeeId) {
            $perDay = $this->slotsForRange($business, $employeeId, $service, $fromLocalDate, $days, $nowUtc);

            foreach ($perDay as $day => $slots) {
                foreach ($slots as $slot) {
                    // Indexado por instante: si dos empleados ofrecen las
                    // 10:00, el cliente ve un solo boton de las 10:00.
                    $merged[$day][$slot->format('H:i')] = $slot;
                }
            }
        }

        $result = [];

        foreach ($merged as $day => $slots) {
            ksort($slots);
            $result[$day] = array_values($slots);
        }

        ksort($result);

        return $result;
    }

    /**
     * Nucleo del calculo. Funcion pura: mismos argumentos, mismo resultado.
     *
     * Se expone como estatica y sin dependencias a proposito, para poder
     * probarla sin base de datos ni dobles de prueba. Toda la logica
     * delicada del sistema vive aqui y esta cubierta por tests.
     *
     * @param  list<TimeRange>     $working     Tramos trabajables, en UTC, ya intersectados
     * @param  array<string,true>  $busyBlocks  Set de bloques ocupados, clave 'Y-m-d H:i:s'
     * @return list<DateTimeImmutable>
     */
    public static function computeSlots(
        array $working,
        array $busyBlocks,
        int $durationMinutes,
        int $bufferMinutes,
        int $granularityMinutes,
        int $blockMinutes = 5,
        ?DateTimeImmutable $notBefore = null,
        ?DateTimeImmutable $notAfter = null,
    ): array {
        if ($durationMinutes <= 0 || $granularityMinutes <= 0 || $blockMinutes <= 0) {
            return [];
        }

        // Lo que hay que mantener libre para poder aceptar la cita: el
        // servicio MAS su buffer. El cliente solo vera la duracion del
        // servicio, pero la agenda se bloquea entera.
        $needMinutes = $durationMinutes + $bufferMinutes;

        $slots = [];

        foreach ($working as $range) {
            // La grilla arranca en el inicio de CADA tramo, no a medianoche.
            // Con jornada partida 09:00-14:00 y 16:00-19:00 y granularidad
            // de 20 minutos, el turno de tarde debe ofrecer 16:00, 16:20...
            // y no heredar el desfase acumulado desde la manana.
            $cursor = $range->start;

            while (true) {
                $end = $cursor->modify(sprintf('+%d minutes', $needMinutes));

                // Caso limite que importa: la cita debe CABER ENTERA dentro
                // del tramo. Una cita que termina exactamente al cierre es
                // valida (el intervalo es semiabierto); una que se pasa un
                // solo minuto, no.
                if ($end->getTimestamp() > $range->endTs()) {
                    break;
                }

                if (
                    self::withinPolicy($cursor, $notBefore, $notAfter)
                    && self::isFree($cursor, $needMinutes, $blockMinutes, $busyBlocks)
                ) {
                    $slots[] = $cursor;
                }

                $cursor = $cursor->modify(sprintf('+%d minutes', $granularityMinutes));
            }
        }

        // Los tramos vienen ordenados, pero si dos se solaparan podria haber
        // duplicados. Se normaliza antes de devolver.
        $unique = [];

        foreach ($slots as $slot) {
            $unique[$slot->format('Y-m-d H:i:s')] = $slot;
        }

        ksort($unique);

        return array_values($unique);
    }

    /**
     * Comprueba bloque a bloque que el rango completo este libre.
     *
     * Se recorre en pasos de $blockMinutes porque asi es exactamente como
     * esta materializada la ocupacion en appointment_slots: si un solo
     * bloque intermedio esta tomado, el horario no sirve, aunque el primero
     * y el ultimo esten libres.
     *
     * @param array<string,true> $busyBlocks
     */
    private static function isFree(
        DateTimeImmutable $start,
        int $needMinutes,
        int $blockMinutes,
        array $busyBlocks,
    ): bool {
        // Redondeo hacia arriba: un servicio de 45 minutos con bloques de 5
        // ocupa 9 bloques; uno de 47 ocuparia 10. Nunca se redondea hacia
        // abajo, porque dejaria un trozo de cita sin proteger.
        $blocks = (int) ceil($needMinutes / $blockMinutes);

        for ($i = 0; $i < $blocks; $i++) {
            $at = $start->modify(sprintf('+%d minutes', $i * $blockMinutes));

            if (isset($busyBlocks[$at->format('Y-m-d H:i:s')])) {
                return false;
            }
        }

        return true;
    }

    private static function withinPolicy(
        DateTimeImmutable $start,
        ?DateTimeImmutable $notBefore,
        ?DateTimeImmutable $notAfter,
    ): bool {
        if ($notBefore !== null && $start->getTimestamp() < $notBefore->getTimestamp()) {
            return false;
        }

        if ($notAfter !== null && $start->getTimestamp() > $notAfter->getTimestamp()) {
            return false;
        }

        return true;
    }

    /**
     * Primer empleado con hueco para un horario concreto.
     *
     * Implementa la opcion "sin preferencia" del formulario publico: el
     * cliente elige servicio y hora, y el sistema resuelve quien atiende.
     *
     * Se recorren los candidatos EN ORDEN y se devuelve el primero libre.
     * Un reparto mas justo (round-robin, o el menos cargado de la semana)
     * seria mejor para el negocio, pero exige una politica que el negocio
     * debe decidir; queda anotado en "Extensiones futuras" del README.
     *
     * @param  list<int>           $employeeIds Candidatos, ya filtrados por servicio
     * @param  array<string,mixed> $business
     * @param  array<string,mixed> $service
     * @return int|null            Id del empleado, o null si ninguno puede
     */
    public function firstAvailableEmployee(
        array $employeeIds,
        array $business,
        array $service,
        DateTimeImmutable $startUtc,
        ?DateTimeImmutable $nowUtc = null,
    ): ?int {
        $tz        = new DateTimeZone((string) $business['timezone']);
        $localDate = $startUtc->setTimezone($tz)->setTime(0, 0);
        $target    = $startUtc->format('Y-m-d H:i:s');

        foreach ($employeeIds as $employeeId) {
            $slots = $this->slotsFor($business, $employeeId, $service, $localDate, $nowUtc);

            foreach ($slots as $slot) {
                if ($slot->format('Y-m-d H:i:s') === $target) {
                    return $employeeId;
                }
            }
        }

        return null;
    }
}
