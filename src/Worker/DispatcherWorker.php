<?php
declare(strict_types=1);

namespace App\Worker;

use App\Database\Connection;
use App\Domain\Sms\SmsOutRepository;
use App\Domain\Sms\SmsQueueRepository;
use App\Support\Clock;
use App\Support\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Passa as mensagens de sms_queue (pendentes) para sms_out, de onde o programa
 * de SMS as lê e envia. Só pode correr UM destes de cada vez.
 */
final class DispatcherWorker
{
    public function __construct(
        private readonly WorkerLoop $loop,
        private readonly SmsQueueRepository $queue,
        private readonly SmsOutRepository $out,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(bool $once): int
    {
        return $this->loop->run(
            'dispatcher',
            fn () => $this->tick(),
            (int) $this->settings->get('dispatcher.interval_seconds', 5),
            $once,
        );
    }

    /**
     * Um ciclo, numa só transação: bloqueia as pendentes, insere cada uma em
     * sms_out e marca-a como despachada. Se algo falhar, ROLLBACK: continuam
     * pendentes e voltam a ser tentadas no ciclo seguinte.
     *
     * @return int quantas mensagens foram despachadas
     */
    public function tick(): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $pending = $this->queue->lockPending((int) $this->settings->get('dispatcher.batch_size', 10));
            $now = $this->clock->now();

            foreach ($pending as $row) {
                $sendId = $this->out->insert($row['recipient_number'], $row['sms_text'], $row['instance_name']);
                $this->queue->markDispatched((int) $row['id'], $sendId, $now);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($pending !== []) {
            $this->logger->info('Mensagens despachadas', ['total' => count($pending)]);
        }

        return count($pending);
    }
}
