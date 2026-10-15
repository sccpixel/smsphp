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
}
