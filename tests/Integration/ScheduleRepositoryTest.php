<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\ScheduleRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests de ScheduleRepository contra una base real.
 *
 * La resolucion de horarios (herencia, interseccion, excepciones) es SQL
 * mas logica: probarla con dobles de prueba solo verificaria que los mocks
 * devuelven lo que se les dijo. Aqui se ejecuta contra MySQL/MariaDB de
 * verdad, sobre el negocio del seed.
 *
 * Si no hay base configurada, la suite se omite en vez de fallar: el
 * proyecto se puede clonar y correr los tests unitarios sin montar nada.
 */
#[CoversClass(ScheduleRepository::class)]
final class ScheduleRepositoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private ScheduleRepository $repo;
    private DateTimeZone $tz;

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

        $this->repo = new ScheduleRepository(self::$pdo);
        $this->tz   = new DateTimeZone('America/Mexico_City');
    }

    /**
     * El proximo lunes, calculado igual que en seed.sql.
     *
     * Se parte de la fecha UTC, no de la local, porque el seed usa CURDATE()
     * con la sesion en UTC. Entre las 18:00 y la medianoche en Mexico las
     * dos fechas difieren, y tomar la local haria fallar estos tests solo a
     * ciertas horas del dia, que es la peor clase de test inestable.
     */
    private function proximoLunes(): DateTimeImmutable
    {
        $hoyUtc = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $lunes  = $hoyUtc->modify('+' . (7 - ((int) $hoyUtc->format('N') - 1)) . ' days');

        return new DateTimeImmutable($lunes->format('Y-m-d') . ' 00:00:00', $this->tz);
    }

    // -----------------------------------------------------------------

    #[Test]
    public function empleado_sin_horario_propio_hereda_el_del_negocio(): void
    {
        // Valeria (id 1) no tiene filas en employee_hours.
        // El negocio abre lunes 09:00-14:00 y 16:00-19:00 = dos tramos.
        $ranges = $this->repo->workingRanges(1, 1, $this->proximoLunes(), $this->tz);

        self::assertCount(2, $ranges, 'Debe heredar los dos tramos del negocio');

        // 09:00 local = 15:00 UTC (America/Mexico_City es UTC-6).
        self::assertSame('15:00', $ranges[0]->start->format('H:i'));
        self::assertSame('20:00', $ranges[0]->end->format('H:i'));
        self::assertSame('22:00', $ranges[1]->start->format('H:i'));
        self::assertSame('01:00', $ranges[1]->end->format('H:i'), 'Cruza medianoche en UTC');
    }

    #[Test]
    public function el_horario_del_empleado_se_intersecta_con_el_del_negocio(): void
    {
        // Daniela (id 2) trabaja lunes 16:00-19:00. El negocio abre en dos
        // tramos, pero solo el de la tarde coincide con el suyo.
        $ranges = $this->repo->workingRanges(1, 2, $this->proximoLunes(), $this->tz);

        self::assertCount(1, $ranges, 'Solo debe quedar el tramo de la tarde');
        self::assertSame('22:00', $ranges[0]->start->format('H:i'));  // 16:00 local
        self::assertSame('01:00', $ranges[0]->end->format('H:i'));    // 19:00 local
    }

    #[Test]
    public function una_excepcion_de_cierre_del_negocio_vacia_el_dia(): void
    {
        // El seed cierra el jueves siguiente por capacitacion interna.
        $jueves = $this->proximoLunes()->modify('+3 days');

        self::assertSame(
            [],
            $this->repo->workingRanges(1, 1, $jueves, $this->tz),
            'Un dia cerrado no debe ofrecer ningun tramo',
        );
    }

    #[Test]
    public function una_excepcion_con_horario_alterno_sustituye_al_normal(): void
    {
        // El viernes tiene horario reducido 09:00-13:00 en vez de la
        // jornada partida habitual.
        $viernes = $this->proximoLunes()->modify('+4 days');
        $ranges  = $this->repo->workingRanges(1, 1, $viernes, $this->tz);

        self::assertCount(1, $ranges, 'El horario alterno reemplaza los dos tramos');
        self::assertSame('15:00', $ranges[0]->start->format('H:i'));  // 09:00 local
        self::assertSame('19:00', $ranges[0]->end->format('H:i'));    // 13:00 local
    }

    #[Test]
    public function una_excepcion_del_empleado_no_afecta_a_sus_companeros(): void
    {
        // Marco (id 3) esta de vacaciones el martes de la semana siguiente.
        $martesSiguiente = $this->proximoLunes()->modify('+8 days');

        self::assertSame(
            [],
            $this->repo->workingRanges(1, 3, $martesSiguiente, $this->tz),
            'Marco esta de vacaciones',
        );

        self::assertNotSame(
            [],
            $this->repo->workingRanges(1, 1, $martesSiguiente, $this->tz),
            'Valeria trabaja con normalidad',
        );
    }

    #[Test]
    public function un_dia_sin_horario_definido_no_ofrece_nada(): void
    {
        // El negocio no abre domingos: no hay filas en business_hours.
        $domingo = $this->proximoLunes()->modify('+6 days');

        self::assertSame([], $this->repo->workingRanges(1, 1, $domingo, $this->tz));
    }

    #[Test]
    public function empleado_con_horario_propio_no_trabaja_los_dias_que_no_declara(): void
    {
        // Marco declara horario de lunes a viernes. El sabado el negocio
        // abre, pero el no: tener filas propias significa que su horario es
        // exhaustivo, no que se complete con el del negocio.
        $sabado = $this->proximoLunes()->modify('+5 days');

        self::assertSame([], $this->repo->workingRanges(1, 3, $sabado, $this->tz));

        // Daniela si declara el sabado, y Valeria lo hereda.
        self::assertNotSame([], $this->repo->workingRanges(1, 2, $sabado, $this->tz));
        self::assertNotSame([], $this->repo->workingRanges(1, 1, $sabado, $this->tz));
    }
}
