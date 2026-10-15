<?php
declare(strict_types=1);

namespace App\Domain\Approval;

use App\Database\Connection;
use App\Domain\ApprovalType\ApprovalTypeRepository;
use App\Domain\Manager\ManagerRepository;
use App\Domain\Sms\QueueStatus;
use App\Domain\Sms\SmsQueueRepository;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Support\Clock;
use App\Support\Phone;
use App\Support\Settings;
use App\Support\Text;
use App\Support\Validator;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/** Regras de negócio dos pedidos de aprovação (criar, consultar, cancelar). */
final class ApprovalService
{
    private const SMS_MAX = 255;
    private const DATE = 'Y-m-d H:i:s';
    private const ISO = 'Y-m-d\TH:i:s';

    public function __construct(
        private readonly ApprovalRepository $approvals,
        private readonly ApprovalTypeRepository $types,
        private readonly ManagerRepository $managers,
        private readonly SmsQueueRepository $queue,
        private readonly CodeGenerator $codes,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly ReplyParser $parser,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * POST /api/approvals
     *
     * @param array<mixed> $data
     * @return array{id:int,code:string,status:string,expires_at:string}
     */
    public function create(array $data, ?string $apiClient): array
    {
        $v = new Validator($data);
        $v->string('type', 1, 30);
        $v->string('description', 1, 120);
        $v->string('reference', 0, 100);
        $v->string('requested_by', 0, 100);
        $v->string('callback_url', 0, 255);
        $v->url('callback_url');
        $v->int('manager_id');

        // Só procura na BD se o formato estiver certo
        $type = null;
        $manager = null;
        if ($v->errors() === []) {
            $type = $this->types->findActiveByCode(trim($data['type']));
            if ($type === null) {
                $v->fail('type', 'Tipo de pedido inexistente ou inativo');
            }

            $managerId = $data['manager_id'] ?? null;
            if ($managerId === null || $managerId === '') {
                $manager = $this->managers->findDefault();
                if ($manager === null) {
                    $v->fail('manager_id', 'Não há gestor por defeito: indique o manager_id');
                }
            } else {
                $manager = $this->managers->findActive((int) $managerId);
                if ($manager === null) {
                    $v->fail('manager_id', 'Gestor inexistente ou inativo');
                }
            }
        }
        $v->throwIfFailed();

        $now = $this->clock->now();
        $minutes = (int) $this->settings->get('approvals.expiry_minutes', 10);
        $expiresAt = $now->modify("+{$minutes} minutes");
        $description = trim($data['description']);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $code = $this->codes->generate();

            $id = $this->approvals->insert([
                'code'             => $code,
                'approval_type_id' => $type['id'],
                'manager_id'       => $manager['id'],
                'description'      => $description,
                'reference'        => $this->optional($data['reference'] ?? null),
                'requested_by'     => $this->optional($data['requested_by'] ?? null),
                'callback_url'     => $this->optional($data['callback_url'] ?? null),
                'api_client'       => $apiClient,
            ], $now, $expiresAt);

            // O SMS vai para o número guardado do gestor, nunca para um vindo da APP
            $text = $this->buildSms($code, $type['label'], $description, $minutes);
            $queueId = $this->queue->insert($manager['phone_number'], $text, null, $apiClient, $now);
            $this->approvals->setQueueId($id, $queueId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id'         => $id,
            'code'       => $code,
            'status'     => ApprovalStatus::Pending->value,
            'expires_at' => $expiresAt->format(self::ISO),
        ];
    }

    /**
     * GET /api/approvals/{id}
     *
     * @return array<string,mixed>
     */
    public function get(int $id): array
    {
        $row = $this->approvals->find($id);
        if ($row === null) {
            throw new NotFoundException('Pedido não encontrado');
        }

        return [
            'id'           => (int) $row['id'],
            'code'         => $row['code'],
            'type'         => $row['type_code'],
            'type_label'   => $row['type_label'],
            'description'  => $row['description'],
            'reference'    => $row['reference'],
            'requested_by' => $row['requested_by'],
            'manager'      => ['id' => (int) $row['manager_id'], 'name' => $row['manager_name']],
            'status'       => $this->effectiveStatus($row)->value,
            'created_at'   => $this->iso($row['created_at']),
            'expires_at'   => $this->iso($row['expires_at']),
            'responded_at' => $this->iso($row['responded_at']),
            'response_text' => $row['response_text'],
            'sms_status'   => $this->smsStatus($row),
        ];
    }

    /**
     * POST /api/approvals/{id}/cancel
     *
     * @return array<string,mixed> o pedido já atualizado
     */
    public function cancel(int $id): array
    {
        $row = $this->approvals->find($id);
        if ($row === null) {
            throw new NotFoundException('Pedido não encontrado');
        }

        $status = $this->effectiveStatus($row);
        if ($status !== ApprovalStatus::Pending) {
            throw new ConflictException("O pedido já não está pendente (estado: {$status->value})");
        }

        // Se alguém o fechou entre a leitura e o UPDATE, o UPDATE não apanha nenhuma linha
        if (!$this->approvals->cancel($id, $this->clock->now())) {
            throw new ConflictException('O pedido já não está pendente');
        }

        return $this->get($id);
    }

    /** Passa a "expired" os pendentes cujo prazo acabou. Devolve quantos. */
    public function expireOverdue(): int
    {
        return $this->approvals->expireOverdue($this->clock->now());
    }

    /**
     * Trata um SMS recebido (linha do sms_in). Tem de ser chamado dentro de uma transação:
     * a resposta ao pedido e o SMS de feedback gravam-se juntos, ou não se grava nada.
     *
     *  - número que não é de um gestor ativo -> ignora, sem responder
     *  - texto que não é "SIM 1234" / "NAO 1234" -> ignora, sem responder
     *  - código de um pedido pendente -> aprova/rejeita e confirma ao gestor
     *  - código de um pedido já fechado ou inexistente -> avisa o gestor
     *
     * @param array{id:int,sms_text:string,sender_number:string} $sms
     */
    public function handleReply(array $sms): void
    {
        $manager = $this->managers->findActiveByPhoneKey(Phone::key($sms['sender_number']));
        if ($manager === null) {
            $this->logger->info('SMS recebido de número que não é gestor: ignorado', ['sms_id' => $sms['id']]);
            return;
        }

        $reply = $this->parser->parse($sms['sms_text']);
        if ($reply === null) {
            $this->logger->info('SMS do gestor não reconhecido: ignorado', ['sms_id' => $sms['id'], 'manager' => $manager['id']]);
            return;
        }

        $now = $this->clock->now();
        $open = $this->approvals->findOpenByCode($manager['id'], $reply->code, $now);

        if ($open !== null) {
            $status = $reply->approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected;
            $text = mb_substr(trim($sms['sms_text']), 0, 255);

            if ($this->approvals->respond($open['id'], $status, $now, $text, $sms['id'])) {
                $this->logger->info('Pedido respondido', ['approval' => $open['id'], 'status' => $status->value]);
                $this->feedback($manager['phone_number'], "Pedido {$reply->code} {$status->labelPt()}.", $now);
                return;
            }
            // Deixou de estar pendente entretanto (ex.: a APP cancelou): cai no aviso abaixo
        }

        $latest = $this->approvals->findLatestByCode($manager['id'], $reply->code);
        if ($latest === null) {
            $this->feedback($manager['phone_number'], "Pedido {$reply->code} nao encontrado.", $now);
            return;
        }

        $state = ApprovalStatus::from($latest['status']);
        if ($state === ApprovalStatus::Pending) {
            $state = ApprovalStatus::Expired;   // pendente na BD mas o prazo já passou
        }
        $this->feedback($manager['phone_number'], "Pedido {$reply->code} ja estava {$state->labelPt()}.", $now);
    }

    /** SMS de resposta ao gestor, para o número guardado em managers (só se APPROVAL_SEND_FEEDBACK=true). */
    private function feedback(string $phoneNumber, string $text, DateTimeImmutable $now): void
    {
        if (!$this->settings->get('approvals.send_feedback', true)) {
            return;
        }
        $this->queue->insert($phoneNumber, $text, null, 'approvals', $now);
    }

    /**
     * Texto do SMS ao gestor (sem acentos, até 255 caracteres; corta a descrição se for preciso):
     * "Pedido 4821 - Desconto: 15% na venda. Responda SIM 4821 ou NAO 4821. Valido 10 min."
     */
    private function buildSms(string $code, string $label, string $description, int $minutes): string
    {
        $head = "Pedido {$code} - " . Text::toGsm($label) . ': ';
        $tail = ". Responda SIM {$code} ou NAO {$code}. Valido {$minutes} min.";

        $room = self::SMS_MAX - mb_strlen($head) - mb_strlen($tail);
        $desc = Text::cut(rtrim(Text::toGsm($description), '.'), $room);

        return $head . $desc . $tail;
    }

    /** Pendente mas já passou o prazo = expirado (o worker é que grava a mudança na BD). */
    private function effectiveStatus(array $row): ApprovalStatus
    {
        $status = ApprovalStatus::from($row['status']);
        if ($status === ApprovalStatus::Pending) {
            $expires = DateTimeImmutable::createFromFormat(self::DATE, $row['expires_at'], $this->clock->now()->getTimezone());
            if ($expires !== false && $expires <= $this->clock->now()) {
                return ApprovalStatus::Expired;
            }
        }
        return $status;
    }

    /**
     * Estado do SMS enviado ao gestor, para a APP saber se o pedido chegou a sair:
     * pending (ainda não saiu), sent ou failed.
     */
    private function smsStatus(array $row): string
    {
        if ($row['queue_status'] === null) {
            return 'pending';
        }

        return match (QueueStatus::from((int) $row['queue_status'])) {
            QueueStatus::Failed => 'failed',
            QueueStatus::Pending => 'pending',
            QueueStatus::Dispatched => match ($row['send_status'] === null ? 0 : (int) $row['send_status']) {
                0 => 'pending',
                2 => 'failed',
                default => 'sent',
            },
        };
    }

    /** Texto opcional: vazio vira NULL. */
    private function optional(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }

    private function iso(mixed $date): ?string
    {
        return $date === null ? null : str_replace(' ', 'T', (string) $date);
    }
}
