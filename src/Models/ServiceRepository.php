<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * CRUD de servicios.
 *
 * No hay metodo delete(). Es deliberado y estructural: appointments tiene
 * una clave foranea RESTRICT contra services, asi que un DELETE fisico
 * fallaria en cuanto el servicio tuviera una sola cita. Y si no fallara
 * seria peor: se perderia el historial. El "borrado" es is_active = 0.
 */
final class ServiceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Todos los servicios, activos e inactivos, para el panel.
     *
     * @return list<array<string,mixed>>
     */
    public function allForAdmin(int $businessId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM employee_service es WHERE es.service_id = s.id)
                        AS employee_count,
                    (SELECT COUNT(*) FROM appointments a
                      WHERE a.service_id = s.id AND a.status IN (\'pending\',\'confirmed\')
                        AND a.starts_at > UTC_TIMESTAMP())
                        AS upcoming_count
               FROM services s
              WHERE s.business_id = :business_id
              ORDER BY s.is_active DESC, s.sort_order, s.name'
        );
        $stmt->execute(['business_id' => $businessId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $businessId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM services WHERE id = :id AND business_id = :business_id'
        );
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);

        return $stmt->fetch() ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create(int $businessId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO services
                (business_id, name, description, duration_minutes, buffer_minutes,
                 price, color, sort_order, is_active)
             VALUES
                (:business_id, :name, :description, :duration_minutes, :buffer_minutes,
                 :price, :color, :sort_order, :is_active)'
        );

        $stmt->execute([
            'business_id'      => $businessId,
            'name'             => $data['name'],
            'description'      => $data['description'],
            'duration_minutes' => $data['duration_minutes'],
            'buffer_minutes'   => $data['buffer_minutes'],
            'price'            => $data['price'],
            'color'            => $data['color'],
            'sort_order'       => $data['sort_order'],
            'is_active'        => $data['is_active'] ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Actualiza un servicio.
     *
     * Cambiar duracion o buffer NO reescribe las citas ya creadas: cada una
     * guarda su propio snapshot. Las citas futuras conservan el bloqueo de
     * agenda con el que se reservaron, que es lo correcto: el cliente
     * aparto una hora concreta y no puede cambiarsele por debajo.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, int $businessId, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE services
                SET name = :name,
                    description = :description,
                    duration_minutes = :duration_minutes,
                    buffer_minutes = :buffer_minutes,
                    price = :price,
                    color = :color,
                    sort_order = :sort_order,
                    is_active = :is_active
              WHERE id = :id AND business_id = :business_id'
        );

        $stmt->execute([
            'name'             => $data['name'],
            'description'      => $data['description'],
            'duration_minutes' => $data['duration_minutes'],
            'buffer_minutes'   => $data['buffer_minutes'],
            'price'            => $data['price'],
            'color'            => $data['color'],
            'sort_order'       => $data['sort_order'],
            'is_active'        => $data['is_active'] ? 1 : 0,
            'id'               => $id,
            'business_id'      => $businessId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Soft delete / restauracion.
     *
     * Un servicio desactivado desaparece del formulario publico pero
     * conserva su historial de citas y sus reservas futuras. Esas citas
     * futuras siguen siendo validas: el negocio se comprometio a
     * atenderlas. Por eso el panel avisa de cuantas hay antes de desactivar
     * en lugar de impedirlo.
     */
    public function setActive(int $id, int $businessId, bool $active): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE services SET is_active = :is_active
              WHERE id = :id AND business_id = :business_id'
        );
        $stmt->execute([
            'is_active'   => $active ? 1 : 0,
            'id'          => $id,
            'business_id' => $businessId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** Citas futuras vivas de un servicio. Para avisar antes de desactivar. */
    public function upcomingCount(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM appointments
              WHERE service_id = :id
                AND status IN (\'pending\',\'confirmed\')
                AND starts_at > UTC_TIMESTAMP()'
        );
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn();
    }
}
