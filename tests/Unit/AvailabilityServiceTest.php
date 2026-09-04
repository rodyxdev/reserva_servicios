<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AvailabilityService;
use App\Support\TimeRange;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests del nucleo de disponibilidad.
 *
 * computeSlots() es una funcion pura, asi que estos tests no necesitan base
 * de datos ni dobles de prueba: se le dan tramos e instantes ocupados, y se
 * comprueba que horarios ofrece.
 *
 * Todo se expresa en UTC. Para que los casos se lean como los leeria una
 * persona, los helpers construyen las horas sobre una fecha fija.
 */
#[CoversClass(AvailabilityService::class)]
final class AvailabilityServiceTest extends TestCase
{
    private const DAY = '2026-09-07';   // un lunes

    private static function at(string $hhmm): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DAY . ' ' . $hhmm . ':00', new DateTimeZone('UTC'));
    }

    /** Tramo trabajable de HH:MM a HH:MM. */
    private static function range(string $from, string $to): TimeRange
    {
        return new TimeRange(self::at($from), self::at($to));
    }

    /**
     * Convierte el resultado a una lista de 'HH:MM' para comparar comodo.
     *
     * @param  list<DateTimeImmutable> $slots
     * @return list<string>
     */
    private static function hhmm(array $slots): array
    {
        return array_map(static fn (DateTimeImmutable $s): string => $s->format('H:i'), $slots);
    }

    /**
     * Marca como ocupados todos los bloques de 5 minutos de un rango.
     *
     * Reproduce lo que appointment_slots contiene tras una reserva.
     *
     * @return array<string,true>
     */
    private static function busy(string $from, string $to): array
    {
        $busy   = [];
        $cursor = self::at($from);
        $end    = self::at($to);

        while ($cursor < $end) {
            $busy[$cursor->format('Y-m-d H:i:s')] = true;
            $cursor = $cursor->modify('+5 minutes');
        }

        return $busy;
    }

    // =================================================================
    //  1. Horario simple
    // =================================================================

    #[Test]
    public function horario_simple_sin_citas_ofrece_toda_la_jornada(): void
    {
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '14:00')],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 60,
        );

        // 13:00 entra porque termina exactamente a las 14:00.
        // 14:00 no, porque se saldria del tramo.
        self::assertSame(
            ['09:00', '10:00', '11:00', '12:00', '13:00'],
            self::hhmm($slots),
        );
    }

    #[Test]
    public function la_granularidad_controla_cada_cuanto_se_ofrece_un_inicio(): void
    {
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '11:00')],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 15,
        );

        self::assertSame(
            ['09:00', '09:15', '09:30', '09:45', '10:00'],
            self::hhmm($slots),
        );
    }

    // =================================================================
    //  2. Jornada partida (corte de comida)
    // =================================================================

    #[Test]
    public function la_jornada_partida_no_ofrece_horarios_durante_el_corte(): void
    {
        $slots = AvailabilityService::computeSlots(
            working: [
                self::range('09:00', '14:00'),
                self::range('16:00', '19:00'),
            ],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 60,
        );

        self::assertSame(
            ['09:00', '10:00', '11:00', '12:00', '13:00',   // manana
             '16:00', '17:00', '18:00'],                    // tarde
            self::hhmm($slots),
        );

        // Lo que de verdad se esta comprobando: nada entre las 14 y las 16.
        self::assertNotContains('14:00', self::hhmm($slots));
        self::assertNotContains('15:00', self::hhmm($slots));
    }

    #[Test]
    public function cada_tramo_ancla_su_propia_grilla(): void
    {
        // Con granularidad de 45 minutos, el turno de tarde debe empezar a
        // ofrecer en 16:00 en punto, no arrastrar el desfase de la manana.
        $slots = AvailabilityService::computeSlots(
            working: [
                self::range('09:00', '11:00'),
                self::range('16:00', '18:00'),
            ],
            busyBlocks:         [],
            durationMinutes:    45,
            bufferMinutes:      0,
            granularityMinutes: 45,
        );

        // Tramo 1: 09:00 y 09:45. El siguiente seria 10:30, que terminaria
        // a las 11:15 y se sale del tramo.
        // Tramo 2: 16:00 y 16:45, con el mismo razonamiento.
        self::assertSame(
            ['09:00', '09:45', '16:00', '16:45'],
            self::hhmm($slots),
            'El segundo tramo debe reiniciar la grilla en su propio inicio',
        );
    }

    // =================================================================
    //  3. Dia cerrado por excepcion de calendario
    // =================================================================

    #[Test]
    public function un_dia_cerrado_no_ofrece_ningun_horario(): void
    {
        // Una excepcion de cierre hace que ScheduleRepository devuelva una
        // lista vacia de tramos. Desde ahi, no hay nada que ofrecer.
        $slots = AvailabilityService::computeSlots(
            working:            [],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 30,
        );

        self::assertSame([], $slots);
    }

    #[Test]
    public function un_horario_especial_recortado_limita_la_oferta(): void
    {
        // Excepcion con is_closed = 0: el dia abre, pero solo 09:00-13:00.
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '13:00')],
            busyBlocks:         [],
            durationMinutes:    90,
            bufferMinutes:      0,
            granularityMinutes: 30,
        );

        self::assertSame(
            ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30'],
            self::hhmm($slots),
        );
        self::assertNotContains('12:00', self::hhmm($slots), 'Terminaria a las 13:30, fuera del horario');
    }

    // =================================================================
    //  4. Buffer entre citas
    // =================================================================

    #[Test]
    public function el_buffer_bloquea_agenda_aunque_no_se_cobre(): void
    {
        // Una cita de 10:00 a 11:00 con 15 de buffer ocupa hasta las 11:15.
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '14:00')],
            busyBlocks:         self::busy('10:00', '11:15'),
            durationMinutes:    60,
            bufferMinutes:      15,
            granularityMinutes: 15,
        );

        $hhmm = self::hhmm($slots);

        // No se puede empezar dentro del buffer ajeno.
        self::assertNotContains('11:00', $hhmm);
        // 11:15 si: el buffer de la cita anterior ya termino.
        self::assertContains('11:15', $hhmm);
        // Tampoco antes: el servicio propio invadiria la cita existente.
        self::assertNotContains('09:15', $hhmm, '09:15 + 75min = 10:30, choca');
        self::assertNotContains('09:00', $hhmm, '09:00 + 75min = 10:15, choca');

        // La manana entera queda inutilizable: no hay 75 minutos libres
        // antes de las 10:00. Todo lo ofrecido empieza tras el buffer.
        self::assertSame(
            ['11:15', '11:30', '11:45', '12:00', '12:15', '12:30', '12:45'],
            $hhmm,
        );
    }

    #[Test]
    public function el_buffer_propio_debe_caber_dentro_del_horario(): void
    {
        // Servicio de 60 + 15 de buffer = 75 minutos que deben caber antes
        // del cierre. El ultimo inicio posible en 09:00-14:00 es 12:45.
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '14:00')],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      15,
            granularityMinutes: 15,
        );

        $hhmm = self::hhmm($slots);

        self::assertSame('12:45', end($hhmm));
        self::assertNotContains('13:00', $hhmm, '13:00 + 75min = 14:15, se pasa del cierre');
    }

    // =================================================================
    //  5. Casos limite en el cierre
    // =================================================================

    #[Test]
    public function una_cita_que_termina_justo_al_cierre_es_valida(): void
    {
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '10:00')],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 15,
        );

        // Solo cabe una: la que va de 09:00 a 10:00 exactamente.
        self::assertSame(['09:00'], self::hhmm($slots));
    }

    #[Test]
    public function una_cita_que_se_pasa_un_minuto_del_cierre_se_rechaza(): void
    {
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '09:59')],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 15,
        );

        self::assertSame([], $slots, 'No cabe un servicio de 60 min en 59 minutos');
    }

    #[Test]
    public function una_cita_puede_empezar_justo_cuando_termina_otra(): void
    {
        // Sin buffer, dos citas consecutivas se tocan pero no se solapan:
        // el intervalo es semiabierto.
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '12:00')],
            busyBlocks:         self::busy('09:00', '10:00'),
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 60,
        );

        self::assertSame(['10:00', '11:00'], self::hhmm($slots));
    }

    // =================================================================
    //  6. Ocupacion
    // =================================================================

    #[Test]
    public function un_bloque_ocupado_en_medio_invalida_el_horario_completo(): void
    {
        // El truco: los extremos estan libres, pero hay 5 minutos tomados
        // en el centro. Un motor que solo mirara inicio y fin ofreceria
        // este horario y provocaria una doble reserva.
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '11:00')],
            busyBlocks:         self::busy('09:30', '09:35'),
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 60,
        );

        self::assertNotContains('09:00', self::hhmm($slots));
        self::assertSame(['10:00'], self::hhmm($slots));
    }

    // =================================================================
    //  7. Politicas de anticipacion
    // =================================================================

    #[Test]
    public function no_se_ofrece_nada_antes_de_la_anticipacion_minima(): void
    {
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '14:00')],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 60,
            blockMinutes:       5,
            notBefore:          self::at('11:30'),
        );

        self::assertSame(['12:00', '13:00'], self::hhmm($slots));
    }

    #[Test]
    public function no_se_ofrece_nada_mas_alla_de_la_ventana_maxima(): void
    {
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '14:00')],
            busyBlocks:         [],
            durationMinutes:    60,
            bufferMinutes:      0,
            granularityMinutes: 60,
            blockMinutes:       5,
            notBefore:          null,
            notAfter:           self::at('10:30'),
        );

        self::assertSame(['09:00', '10:00'], self::hhmm($slots));
    }

    // =================================================================
    //  8. Entradas degeneradas
    // =================================================================

    /** @return iterable<string,array{int,int}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'duracion cero'        => [0, 30];
        yield 'duracion negativa'    => [-30, 30];
        yield 'granularidad cero'    => [60, 0];
    }

    #[Test]
    #[DataProvider('entradasInvalidas')]
    public function las_entradas_absurdas_devuelven_lista_vacia_sin_colgarse(
        int $duration,
        int $granularity,
    ): void {
        $slots = AvailabilityService::computeSlots(
            working:            [self::range('09:00', '18:00')],
            busyBlocks:         [],
            durationMinutes:    $duration,
            bufferMinutes:      0,
            granularityMinutes: $granularity,
        );

        self::assertSame([], $slots);
    }
}
