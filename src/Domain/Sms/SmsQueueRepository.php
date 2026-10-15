<?php
declare(strict_types=1);

namespace App\Domain\Sms;

use App\Database\Connection;
use App\Support\Settings;
use DateTimeImmutable;

/**
 * Todo o SQL da tabela sms_queue (e a leitura do estado em sms_out, só SELECT).
 * As datas chegam sempre de fora (:now, calculado em PHP), nunca de NOW().
 */
final class SmsQueueRepository
{
    private readonly string $queue;
    private readonly string $out;

    public function __construct(private readonly Connection $db, Settings $settings)
    {
        // Os nomes já foram validados em config/settings.php
        $this->queue = '`' . $settings->get('tables.sms_queue') . '`';
        $this->out = '`' . $settings->get('tables.sms_out') . '`';
    }

    /** Põe uma mensagem na fila (status 0 = pendente) e devolve o id. */
    public function insert(
        string $to,
        string $text,
        ?string $instanceName,
        ?string $apiClient,
        DateTimeImmutable $now,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$this->queue} (recipient_number, sms_text, instance_name, status, api_client, created_at)
             VALUES (:to, :text, :instance, 0, :client, :now)"
        );
        $stmt->execute([
            'to'       => $to,
            'text'     => $text,
            'instance' => $instanceName,
            'client'   => $apiClient,
            'now'      => $now->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Uma mensagem com o estado do envio (send_status/sent_dt vêm de sms_out
     * através do send_id; ficam NULL enquanto não foi despachada).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT q.id, q.recipient_number, q.sms_text, q.instance_name, q.status, q.error_message,
                    q.created_at, q.dispatched_at, o.msg_status AS send_status, o.sent_dt AS sent_at
               FROM {$this->queue} q
               LEFT JOIN {$this->out} o ON o.id = q.send_id
              WHERE q.id = :id"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Mensagens pendentes mais antigas, bloqueadas (FOR UPDATE) até ao fim da transação.
     * Tem de ser chamado dentro de uma transação.
     *
     * @return list<array<string,mixed>>
     */
    public function lockPending(int $limit): array
    {
        $limit = max(1, $limit);   // (int) já garantido pelo tipo: seguro de inserir no SQL
        $stmt = $this->db->pdo()->query(
            "SELECT id, recipient_number, sms_text, instance_name
               FROM {$this->queue}
              WHERE status = 0
              ORDER BY id
              LIMIT {$limit}
                FOR UPDATE"
        );

        return $stmt->fetchAll();
    }

    /** Marca como despachada (status 1) e guarda o id da linha criada em sms_out. */
    public function markDispatched(int $id, int $sendId, DateTimeImmutable $now): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE {$this->queue}
                SET status = 1, send_id = :send_id, dispatched_at = :now
              WHERE id = :id"
        );
        $stmt->execute(['send_id' => $sendId, 'now' => $now->format('Y-m-d H:i:s'), 'id' => $id]);
    }
}
