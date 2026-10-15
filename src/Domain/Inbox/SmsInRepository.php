<?php
declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Database\Connection;
use App\Support\Settings;

/**
 * sms_in é do programa de SMS (grava aqui as mensagens recebidas): só SELECT.
 */
final class SmsInRepository
{
    private readonly string $table;

    public function __construct(private readonly Connection $db, Settings $settings)
    {
        $this->table = '`' . $settings->get('tables.sms_in') . '`';
    }

    /** Maior id da tabela (0 se estiver vazia). */
    public function maxId(): int
    {
        return (int) $this->db->pdo()->query("SELECT COALESCE(MAX(id), 0) FROM {$this->table}")->fetchColumn();
    }

    /**
     * Mensagens recebidas depois do id indicado, da mais antiga para a mais recente.
     *
     * @return list<array{id:int,sms_text:string,sender_number:string,sent_dt:?string}>
     */
    public function fetchAfter(int $lastId, int $limit): array
    {
        $limit = max(1, $limit);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, sms_text, sender_number, sent_dt
               FROM {$this->table}
              WHERE id > :last
              ORDER BY id
              LIMIT {$limit}"
        );
        $stmt->execute(['last' => $lastId]);

        return $stmt->fetchAll();
    }
}
