<?php
declare(strict_types=1);

namespace App\Domain\Approval;

use App\Database\Connection;
use App\Support\Settings;
use DateTimeImmutable;

/** Todo o SQL da tabela approval_requests. As datas chegam sempre de fora (:now). */
final class ApprovalRepository
{
    private const DATE = 'Y-m-d H:i:s';

    private readonly string $queue;
    private readonly string $out;

    public function __construct(private readonly Connection $db, Settings $settings)
    {
        $this->queue = '`' . $settings->get('tables.sms_queue') . '`';
        $this->out = '`' . $settings->get('tables.sms_out') . '`';
    }

    /** Já existe um pedido pendente com este código? (o código tem de ser único entre os pendentes) */
    public function pendingCodeExists(string $code): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM approval_requests WHERE code = :code AND status = 'pending' LIMIT 1"
        );
        $stmt->execute(['code' => $code]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Cria um pedido pendente e devolve o id.
     *
     * @param array{code:string,approval_type_id:int,manager_id:int,description:string,reference:?string,requested_by:?string,callback_url:?string,api_client:?string} $data
     */
    public function insert(array $data, DateTimeImmutable $now, DateTimeImmutable $expiresAt): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO approval_requests
                (code, approval_type_id, manager_id, description, reference, requested_by,
                 status, callback_url, created_at, expires_at, api_client)
             VALUES
                (:code, :type, :manager, :description, :reference, :requested_by,
                 'pending', :callback_url, :now, :expires, :client)"
        );
        $stmt->execute([
            'code'         => $data['code'],
            'type'         => $data['approval_type_id'],
            'manager'      => $data['manager_id'],
            'description'  => $data['description'],
            'reference'    => $data['reference'],
            'requested_by' => $data['requested_by'],
            'callback_url' => $data['callback_url'],
            'now'          => $now->format(self::DATE),
            'expires'      => $expiresAt->format(self::DATE),
            'client'       => $data['api_client'],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Guarda o id do SMS (sms_queue) enviado ao gestor. */
    public function setQueueId(int $id, int $queueId): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE approval_requests SET queue_id = :queue WHERE id = :id');
        $stmt->execute(['queue' => $queueId, 'id' => $id]);
    }

    /**
     * Pedido com o tipo, o gestor e o estado do SMS enviado ao gestor.
     * (sms_out: só SELECT, para saber se o envio falhou.)
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ar.id, ar.code, ar.description, ar.reference, ar.requested_by, ar.status,
                    ar.created_at, ar.expires_at, ar.responded_at, ar.response_text,
                    t.code AS type_code, t.label AS type_label,
                    m.id AS manager_id, m.name AS manager_name,
                    q.status AS queue_status, o.msg_status AS send_status
               FROM approval_requests ar
               JOIN approval_types t ON t.id = ar.approval_type_id
               JOIN managers m ON m.id = ar.manager_id
               LEFT JOIN {$this->queue} q ON q.id = ar.queue_id
               LEFT JOIN {$this->out} o ON o.id = q.send_id
              WHERE ar.id = :id"
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** Passa a "expired" todos os pendentes cujo prazo acabou. Devolve quantos. */
    public function expireOverdue(DateTimeImmutable $now): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE approval_requests SET status = 'expired' WHERE status = 'pending' AND expires_at <= :now"
        );
        $stmt->execute(['now' => $now->format(self::DATE)]);

        return $stmt->rowCount();
    }

    /**
     * Pedido pendente (e dentro do prazo) deste gestor com este código.
     *
     * @return array{id:int}|null
     */
    public function findOpenByCode(int $managerId, string $code, DateTimeImmutable $now): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM approval_requests
              WHERE manager_id = :manager AND code = :code AND status = 'pending' AND expires_at > :now
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['manager' => $managerId, 'code' => $code, 'now' => $now->format(self::DATE)]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id']];
    }

    /**
     * Pedido mais recente deste gestor com este código, em qualquer estado
     * (para dizer ao gestor "já estava aprovado/expirado...").
     *
     * @return array{id:int,status:string,expires_at:string}|null
     */
    public function findLatestByCode(int $managerId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, expires_at FROM approval_requests
              WHERE manager_id = :manager AND code = :code
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['manager' => $managerId, 'code' => $code]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Regista a resposta do gestor, mas só se o pedido ainda estiver pendente.
     *
     * @return bool false se entretanto deixou de estar pendente
     */
    public function respond(int $id, ApprovalStatus $status, DateTimeImmutable $now, string $text, int $smsId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE approval_requests
                SET status = :status, responded_at = :now, response_text = :text, response_sms_id = :sms
              WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([
            'status' => $status->value,
            'now'    => $now->format(self::DATE),
            'text'   => $text,
            'sms'    => $smsId,
            'id'     => $id,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Cancela, mas só se ainda estiver pendente e dentro do prazo.
     *
     * @return bool false se entretanto deixou de estar pendente
     */
    public function cancel(int $id, DateTimeImmutable $now): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE approval_requests SET status = 'cancelled'
              WHERE id = :id AND status = 'pending' AND expires_at > :now"
        );
        $stmt->execute(['id' => $id, 'now' => $now->format(self::DATE)]);

        return $stmt->rowCount() === 1;
    }
}
