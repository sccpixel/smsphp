<?php
declare(strict_types=1);

namespace App\Worker;

use App\Database\Connection;
use App\Domain\Approval\ApprovalService;
use App\Domain\Inbox\SmsInRepository;
use App\Domain\Inbox\WorkerStateRepository;
use App\Support\Clock;
use App\Support\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Worker das aprovações. A cada ciclo:
 *   a) marca como expirados os pedidos cujo prazo acabou;
 *   b) lê as mensagens novas do sms_in e entrega-as ao ApprovalService.
 *
 * O "cursor" (worker_state) guarda o último id do sms_in já tratado, porque o
 * sms_in não tem coluna de estado e não podemos alterá-lo. Só pode correr UM worker.
 */
final class ApprovalWorker
{
    private const CURSOR = 'sms_in';

    public function __construct(
        private readonly WorkerLoop $loop,
        private readonly ApprovalService $approvals,
        private readonly SmsInRepository $smsIn,
        private readonly WorkerStateRepository $state,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(bool $once): int
    {
        return $this->loop->run(
            'approvals',
            fn () => $this->tick(),
            (int) $this->settings->get('approvals.poll_seconds', 3),
            $once,
        );
    }

    /**
     * Um ciclo.
     *
     * @return int quantas mensagens do sms_in foram tratadas
     */
    public function tick(): int
    {
        $expired = $this->approvals->expireOverdue();
        if ($expired > 0) {
            $this->logger->info('Pedidos expirados', ['total' => $expired]);
        }

        return $this->readInbox();
    }

    private function readInbox(): int
    {
        $last = $this->state->lastId(self::CURSOR);
        $maxId = $this->smsIn->maxId();

        // Primeira execução: começa a contar a partir de agora (ignora as mensagens antigas)
        if ($last === null) {
            $this->state->create(self::CURSOR, $maxId, $this->clock->now());
            $this->logger->info('Cursor do sms_in criado', ['last_id' => $maxId]);
            return 0;
        }

        // O sms_in foi esvaziado (só acontece em testes): o cursor ficou à frente, repõe-no
        if ($maxId < $last) {
            $this->state->update(self::CURSOR, $maxId, $this->clock->now());
            $this->logger->warning('sms_in com ids menores que o cursor: cursor reposto', ['antes' => $last, 'depois' => $maxId]);
            return 0;
        }

        $messages = $this->smsIn->fetchAfter($last, (int) $this->settings->get('approvals.inbox_batch', 50));
        $handled = 0;

        foreach ($messages as $sms) {
            $this->processOne($sms);
            $handled++;
        }

        return $handled;
    }

    /**
     * Cada mensagem na sua própria transação: aplicar a resposta, enviar o feedback e
     * avançar o cursor ou acontecem os três, ou nenhum. Se der erro, ROLLBACK e o erro
     * sobe (o WorkerLoop regista e o ciclo seguinte volta a tentar esta mesma mensagem).
     *
     * @param array{id:int,sms_text:string,sender_number:string} $sms
     */
    private function processOne(array $sms): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $cursor = $this->state->lockLastId(self::CURSOR) ?? 0;

            if ((int) $sms['id'] > $cursor) {
                $this->approvals->handleReply($sms);
                $this->state->update(self::CURSOR, (int) $sms['id'], $this->clock->now());
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
