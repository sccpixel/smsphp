<?php
declare(strict_types=1);

namespace App\Domain\Sms;

use App\Database\Connection;
use App\Support\Settings;

/**
 * sms_out é do programa de SMS: aqui só se faz INSERT.
 * Nunca se altera a estrutura nem se faz UPDATE/DELETE.
 */
final class SmsOutRepository
{
    private readonly string $out;

    public function __construct(private readonly Connection $db, Settings $settings)
    {
        $this->out = '`' . $settings->get('tables.sms_out') . '`';
    }

    /** Insere um SMS por enviar (msg_status 0) e devolve o id criado. */
    public function insert(string $to, string $text, ?string $instanceName): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$this->out} (recipient_number, sms_text, msg_status, instance_name, sent_dt)
             VALUES (:to, :text, 0, :instance, NULL)"
        );
        $stmt->execute(['to' => $to, 'text' => $text, 'instance' => $instanceName]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
