<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Catalogo: negocio, servicios, empleados y quien presta que.
 *
 * Son consultas de solo lectura muy relacionadas entre si (el flujo publico
 * las encadena: negocio -> servicios -> empleados del servicio), por eso
 * viven juntas en vez de en tres clases que se llamarian siempre a la vez.
 * El CRUD de escritura del panel ira en repositorios propios en la fase 3.
 */
final class CatalogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function business(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM businesses WHERE id = :id AND is_active = 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function businessBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM businesses WHERE slug = :slug AND is_active = 1'
        );
        $stmt->execute(['slug' => $slug]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Servicios ofrecidos al publico.
     *
     * Filtra por is_active: los servicios descontinuados siguen existiendo
     * para no romper el historial de citas, pero no se ofrecen.
     *
     * @return list<array<string,mixed>>
     */
    public function activeServices(int $businessId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, duration_minutes, buffer_minutes,
                    price, color, sort_order
               FROM services
              WHERE business_id = :business_id AND is_active = 1
              ORDER BY sort_order, name'
        );
        $stmt->execute(['business_id' => $businessId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function service(int $serviceId, int $businessId, bool $onlyActive = true): ?array
    {
        $sql = 'SELECT * FROM services WHERE id = :id AND business_id = :business_id';

        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $serviceId, 'business_id' => $businessId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Empleados activos que prestan un servicio concreto.
     *
     * El JOIN con employee_service es lo que impide ofrecer a la
     * cosmetologa para un masaje descontracturante.
     *
     * @return list<array<string,mixed>>
     */
    public function employeesForService(int $serviceId, int $businessId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.name, e.role_title
               FROM employees e
               JOIN employee_service es ON es.employee_id = e.id
              WHERE es.service_id = :service_id
                AND e.business_id = :business_id
                AND e.is_active = 1
              ORDER BY e.name'
        );
        $stmt->execute(['service_id' => $serviceId, 'business_id' => $businessId]);

        return $stmt->fetchAll();
    }

    /** Verifica que un empleado concreto pueda prestar un servicio concreto. */
    public function employeeOffersService(int $employeeId, int $serviceId, int $businessId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
               FROM employee_service es
               JOIN employees e ON e.id = es.employee_id
              WHERE es.employee_id = :employee_id
                AND es.service_id  = :service_id
                AND e.business_id  = :business_id
                AND e.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([
            'employee_id' => $employeeId,
            'service_id'  => $serviceId,
            'business_id' => $businessId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function activeEmployees(int $businessId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, phone, role_title
               FROM employees
              WHERE business_id = :business_id AND is_active = 1
              ORDER BY name'
        );
        $stmt->execute(['business_id' => $businessId]);

        return $stmt->fetchAll();
    }
}
