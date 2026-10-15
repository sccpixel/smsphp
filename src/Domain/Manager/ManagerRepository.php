<?php
declare(strict_types=1);

namespace App\Domain\Manager;

use App\Database\Connection;

/** Todo o SQL da tabela managers. */
final class ManagerRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Gestor ativo com este id (inclui o telefone: é para onde vai o SMS do pedido).
     *
     * @return array{id:int,name:string,phone_number:string}|null
     */
    public function findActive(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, phone_number FROM managers WHERE id = :id AND active = 1'
        );
        $stmt->execute(['id' => $id]);

        return $this->normalize($stmt->fetch() ?: null);
    }

    /**
     * Gestor ativo marcado como por defeito (is_default = 1), usado quando a APP não indica um.
     *
     * @return array{id:int,name:string,phone_number:string}|null
     */
    public function findDefault(): ?array
    {
        $row = $this->db->pdo()
            ->query('SELECT id, name, phone_number FROM managers WHERE is_default = 1 AND active = 1 ORDER BY id LIMIT 1')
            ->fetch();

        return $this->normalize($row ?: null);
    }

    /**
     * Gestores ativos, sem o número de telefone (a API nunca o expõe).
     *
     * @return list<array{id:int,name:string,is_default:bool}>
     */
    public function listActive(): array
    {
        $rows = $this->db->pdo()
            ->query('SELECT id, name, is_default FROM managers WHERE active = 1 ORDER BY id')
            ->fetchAll();

        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'name'       => $r['name'],
            'is_default' => (bool) $r['is_default'],
        ], $rows);
    }

    /** @param array<string,mixed>|null $row */
    private function normalize(?array $row): ?array
    {
        return $row === null ? null : [
            'id'           => (int) $row['id'],
            'name'         => $row['name'],
            'phone_number' => $row['phone_number'],
        ];
    }
}
