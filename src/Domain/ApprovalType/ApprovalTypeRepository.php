<?php
declare(strict_types=1);

namespace App\Domain\ApprovalType;

use App\Database\Connection;

/** Todo o SQL da tabela approval_types. */
final class ApprovalTypeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Tipo ativo com este código ('discount', 'stock_move', ...).
     *
     * @return array{id:int,code:string,label:string}|null
     */
    public function findActiveByCode(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label FROM approval_types WHERE code = :code AND active = 1'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'code' => $row['code'], 'label' => $row['label']];
    }

    /**
     * Tipos de pedido ativos.
     *
     * @return list<array{code:string,label:string}>
     */
    public function listActive(): array
    {
        return $this->db->pdo()
            ->query('SELECT code, label FROM approval_types WHERE active = 1 ORDER BY id')
            ->fetchAll();
    }
}
