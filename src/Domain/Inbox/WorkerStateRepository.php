<?php
declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Database\Connection;
use DateTimeImmutable;

/**
 * Tabela worker_state: guarda até que mensagem do sms_in o worker já tratou
 * (o "cursor"), para não processar a mesma resposta duas vezes.
 */
final class WorkerStateRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Último id tratado, ou null se o cursor ainda não existe. */
    public function lastId(string $name): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT last_id FROM worker_state WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** Como lastId(), mas bloqueia a linha até ao fim da transação (FOR UPDATE). */
    public function lockLastId(string $name): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT last_id FROM worker_state WHERE name = :name FOR UPDATE');
        $stmt->execute(['name' => $name]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    public function create(string $name, int $lastId, DateTimeImmutable $now): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO worker_state (name, last_id, updated_at) VALUES (:name, :last, :now)'
        );
        $stmt->execute(['name' => $name, 'last' => $lastId, 'now' => $now->format('Y-m-d H:i:s')]);
    }

    public function update(string $name, int $lastId, DateTimeImmutable $now): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE worker_state SET last_id = :last, updated_at = :now WHERE name = :name'
        );
        $stmt->execute(['name' => $name, 'last' => $lastId, 'now' => $now->format('Y-m-d H:i:s')]);
    }
}
