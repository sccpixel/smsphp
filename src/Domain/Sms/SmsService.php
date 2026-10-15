<?php
declare(strict_types=1);

namespace App\Domain\Sms;

use App\Database\Connection;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Support\Clock;
use App\Support\Phone;
use App\Support\Settings;
use App\Support\Validator;
use Throwable;

/**
 * Regras de negócio das mensagens: validar, pôr na fila e consultar o estado.
 * Não sabe nada de HTTP nem escreve SQL (isso é das Actions e do Repository).
 */
final class SmsService
{
    private const BATCH_MAX = 500;

    public function __construct(
        private readonly SmsQueueRepository $queue,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Settings $settings,
    ) {
    }

    /**
     * POST /api/messages
     *
     * @param array<mixed> $data
     * @return array{id:int,status:string,created_at:string}
     */
    public function create(array $data, ?string $apiClient): array
    {
        $v = new Validator($data);
        $msg = $this->validateMessage($data, $v);
        $v->throwIfFailed();

        $now = $this->clock->now();
        $id = $this->queue->insert($msg['to'], $msg['text'], $msg['instance_name'], $apiClient, $now);

        return ['id' => $id, 'status' => QueueStatus::Pending->label(), 'created_at' => $now->format('Y-m-d\TH:i:s')];
    }

    /**
     * POST /api/messages/batch — tudo ou nada: se uma mensagem for inválida nenhuma é inserida.
     *
     * @param array<mixed> $data
     * @return array{count:int,ids:list<int>}
     */
    public function createBatch(array $data, ?string $apiClient): array
    {
        $messages = $data['messages'] ?? null;
        if (!is_array($messages) || !array_is_list($messages) || count($messages) < 1 || count($messages) > self::BATCH_MAX) {
            throw new ValidationException(['messages' => 'Tem de ser uma lista com 1 a ' . self::BATCH_MAX . ' mensagens']);
        }

        // 1.º validar todas, juntando os erros (chaves tipo "messages.3.to")
        $errors = [];
        $valid = [];
        foreach ($messages as $i => $item) {
            $item = is_array($item) ? $item : [];
            $v = new Validator($item, "messages.{$i}.");
            $valid[] = $this->validateMessage($item, $v);
            $errors += $v->errors();
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // 2.º inserir todas numa transação
        $now = $this->clock->now();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $ids = [];
            foreach ($valid as $msg) {
                $ids[] = $this->queue->insert($msg['to'], $msg['text'], $msg['instance_name'], $apiClient, $now);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['count' => count($ids), 'ids' => $ids];
    }

    /**
     * GET /api/messages/{id}
     *
     * @return array<string,mixed>
     */
    public function get(int $id): array
    {
        $row = $this->queue->find($id);
        if ($row === null) {
            throw new NotFoundException('Mensagem não encontrada');
        }

        return [
            'id'            => (int) $row['id'],
            'to'            => $row['recipient_number'],
            'text'          => $row['sms_text'],
            'instance_name' => $row['instance_name'],
            'queue_status'  => QueueStatus::from((int) $row['status'])->label(),
            'send_status'   => $row['send_status'] === null ? null : (int) $row['send_status'],
            'sent_at'       => $this->iso($row['sent_at']),
            'created_at'    => $this->iso($row['created_at']),
            'dispatched_at' => $this->iso($row['dispatched_at']),
            'error'         => $row['error_message'],
        ];
    }

    /**
     * Valida uma mensagem e devolve-a já limpa (número normalizado, texto sem espaços nas pontas).
     * Os erros ficam no $v; quem chama decide quando lançar a exceção.
     *
     * @param array<mixed> $data
     * @return array{to:string,text:string,instance_name:?string}
     */
    private function validateMessage(array $data, Validator $v): array
    {
        $to = $data['to'] ?? null;
        if (is_int($to)) {
            $to = (string) $to;   // aceita 912345678 sem aspas no JSON
        }

        $normalized = '';
        if (!is_string($to) || trim($to) === '') {
            $v->fail('to', 'Campo obrigatório');
        } else {
            try {
                $normalized = Phone::normalize($to);
            } catch (ValidationException $e) {
                $v->fail('to', $e->errors()['to'] ?? 'Número de telefone inválido');
            }
        }

        $v->string('text', 1, (int) $this->settings->get('sms.max_text_length', 255));
        $v->string('instance_name', 0, 255);

        $text = is_string($data['text'] ?? null) ? trim($data['text']) : '';
        $instance = is_string($data['instance_name'] ?? null) ? trim($data['instance_name']) : '';

        return [
            'to'            => $normalized,
            'text'          => $text,
            'instance_name' => $instance === '' ? null : $instance,   // sem nome -> NULL
        ];
    }

    /** '2026-10-15 10:00:00' -> '2026-10-15T10:00:00' (null fica null). */
    private function iso(mixed $date): ?string
    {
        return $date === null ? null : str_replace(' ', 'T', (string) $date);
    }
}
