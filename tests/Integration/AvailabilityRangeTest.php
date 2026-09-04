<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\AppointmentRepository;
use App\Models\CatalogRepository;
use App\Models\ScheduleRepository;
use App\Services\AvailabilityService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la consulta de disponibilidad por rango, que es lo que alimenta
 * el paso 3 del wizard publico.
 *
 * slotsForRange() no es slotsFor() en un bucle: trae los bloques ocupados
 * de toda la ventana en una sola consulta y los reutiliza. Esa optimizacion
 * es exactamente la clase de codigo que se rompe en silencio, devolviendo
 * huecos que en realidad estan tomados, asi que se comprueba contra la base
 * real que el resultado coincide dia a dia con el metodo simple.
 */
#[CoversClass(AvailabilityService::class)]
final class AvailabilityRangeTest extends TestCase
{
    private static ?PDO $pdo = null;
    private AvailabilityService $availability;
    private CatalogRepository $catalog;
    private DateTimeZone $tz;
    private array $business;

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

        $this->catalog      = new CatalogRepository(self::$pdo);
        $this->availability = new AvailabilityService(
            new ScheduleRepository(self::$pdo),
            new AppointmentRepository(self::$pdo),
            5,
        );

        $this->business = $this->catalog->business(1)
            ?? self::markTestSkipped('El seed no esta cargado.');

        $this->tz = new DateTimeZone((string) $this->business['timezone']);
    }

    /** El proximo lunes, igual que lo calcula seed.sql. */
    private function proximoLunes(): DateTimeImmutable
    {
        $hoyUtc = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $lunes  = $hoyUtc->modify('+' . (7 - ((int) $hoyUtc->format('N') - 1)) . ' days');

        return new DateTimeImmutable($lunes->format('Y-m-d') . ' 00:00:00', $this->tz);
    }

    // -----------------------------------------------------------------

    #[Test]
    public function el_rango_coincide_dia_a_dia_con_la_consulta_simple(): void
    {
        $servicio = $this->catalog->service(1, 1);   // Masaje relajante, 60 + 15
        $desde    = $this->proximoLunes();

        // Se congela el "ahora" para que las dos rutas apliquen la misma
        // ventana de anticipacion minima. Sin esto, un segundo de
        // diferencia entre ambas llamadas podria eliminar un hueco de una
        // y no de la otra, y el test fallaria de vez en cuando.
        $ahora = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $porRango = $this->availability->slotsForRange(
            $this->business, 1, $servicio, $desde, 14, $ahora,
        );

        for ($i = 0; $i < 14; $i++) {
            $dia    = $desde->modify(sprintf('+%d days', $i));
            $clave  = $dia->format('Y-m-d');

            $simple = $this->availability->slotsFor(
                $this->business, 1, $servicio, $dia, $ahora,
            );

            $esperado = array_map(
                static fn (DateTimeImmutable $s): string => $s->format('Y-m-d H:i:s'),
                $simple,
            );

            $obtenido = array_map(
                static fn (DateTimeImmutable $s): string => $s->format('Y-m-d H:i:s'),
                $porRango[$clave] ?? [],
            );

            self::assertSame(
                $esperado,
                $obtenido,
                "La consulta por rango difiere de la simple en {$clave}",
            );
        }
    }

    #[Test]
    public function los_dias_cerrados_por_excepcion_no_aparecen_en_el_rango(): void
    {
        $servicio = $this->catalog->service(1, 1);
        $lunes    = $this->proximoLunes();

        $porDia = $this->availability->slotsForRange(
            $this->business, 1, $servicio, $lunes, 14,
        );

        // El seed cierra el jueves siguiente por capacitacion interna.
        $jueves = $lunes->modify('+3 days')->format('Y-m-d');
        self::assertArrayNotHasKey($jueves, $porDia, 'El dia cerrado no debe ofrecerse');

        // Y el domingo el negocio no abre: no hay filas en business_hours.
        $domingo = $lunes->modify('+6 days')->format('Y-m-d');
        self::assertArrayNotHasKey($domingo, $porDia, 'El domingo no se trabaja');

        // El resto de la semana si.
        self::assertArrayHasKey($lunes->format('Y-m-d'), $porDia);
    }

    #[Test]
    public function sin_preferencia_es_la_union_y_no_la_suma(): void
    {
        // Masaje relajante lo prestan Valeria (1) y Marco (3), y sus
        // horarios se solapan por la manana. La union tiene que ser menor
        // que la suma: las horas coincidentes cuentan una sola vez.
        $servicio = $this->catalog->service(1, 1);
        $desde    = $this->proximoLunes();
        $ahora    = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $contar = static function (array $porDia): int {
            $n = 0;
            foreach ($porDia as $slots) {
                $n += count($slots);
            }

            return $n;
        };

        $valeria = $contar($this->availability->slotsForRange($this->business, 1, $servicio, $desde, 14, $ahora));
        $marco   = $contar($this->availability->slotsForRange($this->business, 3, $servicio, $desde, 14, $ahora));

        $union = $this->availability->slotsForRangeAnyEmployee(
            [1, 3], $this->business, $servicio, $desde, 14, $ahora,
        );

        self::assertGreaterThan(0, $valeria);
        self::assertGreaterThan(0, $marco);
        self::assertLessThan(
            $valeria + $marco,
            $contar($union),
            'Con horarios solapados, la union debe fusionar horas repetidas',
        );
        self::assertGreaterThanOrEqual(
            max($valeria, $marco),
            $contar($union),
            'La union no puede ofrecer menos que el mejor de los dos por separado',
        );
    }

    #[Test]
    public function la_union_no_repite_la_misma_hora_dos_veces(): void
    {
        $servicio = $this->catalog->service(1, 1);

        $union = $this->availability->slotsForRangeAnyEmployee(
            [1, 3], $this->business, $servicio, $this->proximoLunes(), 7,
        );

        foreach ($union as $dia => $slots) {
            $horas = array_map(
                static fn (DateTimeImmutable $s): string => $s->format('H:i'),
                $slots,
            );

            self::assertSame(
                array_values(array_unique($horas)),
                $horas,
                "El dia {$dia} tiene horas duplicadas",
            );
        }
    }

    #[Test]
    public function no_se_ofrecen_dias_mas_alla_de_la_ventana_maxima(): void
    {
        $servicio = $this->catalog->service(1, 1);

        // Se arranca justo en el limite de max_advance_days.
        $lejos = (new DateTimeImmutable('today', $this->tz))
            ->modify(sprintf('+%d days', (int) $this->business['max_advance_days'] - 2));

        $porDia = $this->availability->slotsForRange($this->business, 1, $servicio, $lejos, 14);

        $limite = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('+%d days', (int) $this->business['max_advance_days']));

        foreach ($porDia as $slots) {
            foreach ($slots as $slot) {
                self::assertLessThanOrEqual(
                    $limite->getTimestamp(),
                    $slot->getTimestamp(),
                    'Se ofrecio un hueco fuera de la ventana maxima de reserva',
                );
            }
        }
    }
}
