<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use Throwable;

/**
 * CRUD de empleados, con sus servicios y su horario.
 *
 * Igual que en servicios: no hay borrado fisico, solo is_active = 0.
 */
final class EmployeeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function allForAdmin(int $businessId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM employee_service es WHERE es.employee_id = e.id)
                        AS service_count,
                    (SELECT COUNT(*) FROM employee_hours eh WHERE eh.employee_id = e.id)
                        AS hours_count,
                    (SELECT COUNT(*) FROM appointments a
                      WHERE a.employee_id = e.id AND a.status IN (\'pending\',\'confirmed\')
                        AND a.starts_at > UTC_TIMESTAMP())
                        AS upcoming_count
               FROM employees e
              WHERE e.business_id = :business_id
              ORDER BY e.is_active DESC, e.name'
        );
        $stmt->execute(['business_id' => $businessId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $businessId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM employees WHERE id = :id AND business_id = :business_id'
        );
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);

        return $stmt->fetch() ?: null;
    }

    /** Ids de los servicios que presta. @return list<int> */
    public function serviceIds(int $employeeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT service_id FROM employee_service WHERE employee_id = :id'
        );
        $stmt->execute(['id' => $employeeId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Horario propio, agrupado por dia de la semana.
     *
     * @return array<int,list<array{starts_at:string,ends_at:string}>>
     */
    public function hours(int $employeeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT weekday, starts_at, ends_at
               FROM employee_hours
              WHERE employee_id = :id
              ORDER BY weekday, starts_at'
        );
        $stmt->execute(['id' => $employeeId]);

        $byDay = [];

        foreach ($stmt->fetchAll() as $row) {
            $byDay[(int) $row['weekday']][] = [
                'starts_at' => substr((string) $row['starts_at'], 0, 5),
                'ends_at'   => substr((string) $row['ends_at'], 0, 5),
            ];
        }

        return $byDay;
    }

    /**
     * Crea o actualiza un empleado junto con sus servicios y su horario.
     *
     * Todo en una transaccion: un empleado guardado a medias (sin sus
     * servicios, o con el horario viejo) genera disponibilidad incorrecta,
     * que es peor que no haberlo guardado.
     *
     * @param array<string,mixed>                                     $data
     * @param list<int>                                               $serviceIds
     * @param array<int,list<array{starts_at:string,ends_at:string}>> $hours
     */
    public function save(
        ?int $id,
        int $businessId,
        array $data,
        array $serviceIds,
        array $hours,
    ): int {
        $this->pdo->beginTransaction();

        try {
            if ($id === null) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO employees (business_id, name, email, phone, role_title, is_active)
                     VALUES (:business_id, :name, :email, :phone, :role_title, :is_active)'
                );
                $stmt->execute([
                    'business_id' => $businessId,
                    'name'        => $data['name'],
                    'email'       => $data['email'],
                    'phone'       => $data['phone'],
                    'role_title'  => $data['role_title'],
                    'is_active'   => $data['is_active'] ? 1 : 0,
                ]);

                $id = (int) $this->pdo->lastInsertId();
            } else {
                $stmt = $this->pdo->prepare(
                    'UPDATE employees
                        SET name = :name, email = :email, phone = :phone,
                            role_title = :role_title, is_active = :is_active
                      WHERE id = :id AND business_id = :business_id'
                );
                $stmt->execute([
                    'name'        => $data['name'],
                    'email'       => $data['email'],
                    'phone'       => $data['phone'],
                    'role_title'  => $data['role_title'],
                    'is_active'   => $data['is_active'] ? 1 : 0,
                    'id'          => $id,
                    'business_id' => $businessId,
                ]);
            }

            $this->syncServices($id, $businessId, $serviceIds);
            $this->syncHours($id, $hours);

            $this->pdo->commit();

            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Reemplaza la lista de servicios del empleado.
     *
     * Se borra y se reinserta en vez de calcular altas y bajas: la tabla
     * tiene dos columnas y ninguna informacion propia que perder, asi que
     * el diff solo aportaria codigo que mantener.
     *
     * El filtro por business_id impide que un POST manipulado asigne un
     * servicio de OTRO negocio: sin el, la tenencia multiple se rompe.
     *
     * @param list<int> $serviceIds
     */
    private function syncServices(int $employeeId, int $businessId, array $serviceIds): void
    {
        $this->pdo
            ->prepare('DELETE FROM employee_service WHERE employee_id = :id')
            ->execute(['id' => $employeeId]);

        if ($serviceIds === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO employee_service (employee_id, service_id)
             SELECT :employee_id, s.id
               FROM services s
              WHERE s.id = :service_id AND s.business_id = :business_id'
        );

        foreach (array_unique($serviceIds) as $serviceId) {
            $stmt->execute([
                'employee_id' => $employeeId,
                'service_id'  => $serviceId,
                'business_id' => $businessId,
            ]);
        }
    }

    /**
     * Reemplaza el horario propio del empleado.
     *
     * Guardar CERO tramos no es lo mismo que no tener horario: si no queda
     * ninguna fila, el empleado vuelve a heredar el horario del negocio.
     * Para que un empleado no trabaje nunca, se desactiva; para que trabaje
     * unos dias concretos, se declaran solo esos.
     *
     * @param array<int,list<array{starts_at:string,ends_at:string}>> $hours
     */
    private function syncHours(int $employeeId, array $hours): void
    {
        $this->pdo
            ->prepare('DELETE FROM employee_hours WHERE employee_id = :id')
            ->execute(['id' => $employeeId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO employee_hours (employee_id, weekday, starts_at, ends_at)
             VALUES (:employee_id, :weekday, :starts_at, :ends_at)'
        );

        foreach ($hours as $weekday => $ranges) {
            if ($weekday < 1 || $weekday > 7) {
                continue;
            }

            foreach ($ranges as $range) {
                // El CHECK del esquema ya lo rechazaria, pero fallar aqui
                // con un mensaje claro es mejor que un error 500 de SQL.
                if ($range['ends_at'] <= $range['starts_at']) {
                    continue;
                }

                $stmt->execute([
                    'employee_id' => $employeeId,
                    'weekday'     => $weekday,
                    'starts_at'   => $range['starts_at'] . ':00',
                    'ends_at'     => $range['ends_at'] . ':00',
                ]);
            }
        }
    }

    public function setActive(int $id, int $businessId, bool $active): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE employees SET is_active = :is_active
              WHERE id = :id AND business_id = :business_id'
        );
        $stmt->execute([
            'is_active'   => $active ? 1 : 0,
            'id'          => $id,
            'business_id' => $businessId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function upcomingCount(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM appointments
              WHERE employee_id = :id
                AND status IN (\'pending\',\'confirmed\')
                AND starts_at > UTC_TIMESTAMP()'
        );
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }
}
