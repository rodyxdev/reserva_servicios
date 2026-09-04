<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Intervalo de tiempo semiabierto [inicio, fin).
 *
 * Semiabierto a proposito: el fin NO pertenece al intervalo. Es lo que hace
 * que una cita que termina a las 11:00 y otra que empieza a las 11:00 no se
 * consideren solapadas, sin necesidad de restar un minuto en ningun sitio.
 * Toda la aritmetica de horarios del sistema usa este criterio.
 */
final readonly class TimeRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
    }

    public static function of(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        return new self($start, $end);
    }

    public function startTs(): int
    {
        return $this->start->getTimestamp();
    }

    public function endTs(): int
    {
        return $this->end->getTimestamp();
    }

    public function isEmpty(): bool
    {
        return $this->endTs() <= $this->startTs();
    }

    public function durationMinutes(): int
    {
        return intdiv($this->endTs() - $this->startTs(), 60);
    }

    /** Interseccion con otro intervalo, o null si no se tocan. */
    public function intersect(self $other): ?self
    {
        $start = max($this->startTs(), $other->startTs());
        $end   = min($this->endTs(), $other->endTs());

        if ($end <= $start) {
            return null;
        }

        return new self(
            (new DateTimeImmutable('@' . $start))->setTimezone($this->start->getTimezone()),
            (new DateTimeImmutable('@' . $end))->setTimezone($this->end->getTimezone()),
        );
    }

    /** Este intervalo contiene por completo al otro. */
    public function contains(self $other): bool
    {
        return $other->startTs() >= $this->startTs()
            && $other->endTs() <= $this->endTs();
    }

    /**
     * Interseccion de dos listas de intervalos.
     *
     * Es la operacion central del calculo de disponibilidad: cruzar el
     * horario del negocio con el del empleado, ambos posiblemente partidos
     * en varios tramos.
     *
     * @param  list<self> $a
     * @param  list<self> $b
     * @return list<self>
     */
    public static function intersectLists(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $rangeA) {
            foreach ($b as $rangeB) {
                $overlap = $rangeA->intersect($rangeB);

                if ($overlap !== null) {
                    $result[] = $overlap;
                }
            }
        }

        usort($result, static fn (self $x, self $y): int => $x->startTs() <=> $y->startTs());

        return $result;
    }

    public function __toString(): string
    {
        return $this->start->format('Y-m-d H:i') . ' - ' . $this->end->format('H:i');
    }
}
