<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class CustomerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Busca al cliente por correo dentro del negocio, o lo crea.
     *
     * Sin registro ni contrasena: la identidad del cliente es su correo
     * dentro de ese negocio, respaldada por el UNIQUE
     * (business_id, email) del esquema.
     *
     * Si ya existe se actualizan nombre y telefono con lo ultimo que
     * escribio: es informacion mas fresca que la que hubiera. Las notas
     * internas NO se tocan nunca desde el formulario publico; las escribe
     * el negocio y el cliente jamas debe poder sobreescribirlas.
     *
     * Debe llamarse DENTRO de la transaccion de la reserva, para que un
     * fallo posterior no deje clientes huerfanos.
     */
    public function findOrCreate(int $businessId, string $name, string $email, string $phone): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM customers WHERE business_id = :business_id AND email = :email'
        );
        $stmt->execute(['business_id' => $businessId, 'email' => $email]);

        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $update = $this->pdo->prepare(
                'UPDATE customers SET name = :name, phone = :phone WHERE id = :id'
            );
            $update->execute(['name' => $name, 'phone' => $phone, 'id' => (int) $id]);

            return (int) $id;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO customers (business_id, name, email, phone)
             VALUES (:business_id, :name, :email, :phone)'
        );
        $insert->execute([
            'business_id' => $businessId,
            'name'        => $name,
            'email'       => $email,
            'phone'       => $phone,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $businessId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM customers WHERE id = :id AND business_id = :business_id'
        );
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);

        return $stmt->fetch() ?: null;
    }

    /** Historial de citas de un cliente, para la ficha del panel. */
    public function history(int $customerId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.starts_at, a.status, a.price,
                    s.name AS service_name, e.name AS employee_name
               FROM appointments a
               JOIN services  s ON s.id = a.service_id
               JOIN employees e ON e.id = a.employee_id
              WHERE a.customer_id = :customer_id
              ORDER BY a.starts_at DESC
              LIMIT :max_rows'
        );
        $stmt->bindValue('customer_id', $customerId, PDO::PARAM_INT);
        $stmt->bindValue('max_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
