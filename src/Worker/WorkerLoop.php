<?php
declare(strict_types=1);

namespace App\Worker;

use App\Database\Connection;
use App\Support\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ciclo comum a todos os workers: executa um "tick", dorme o que faltar do
 * intervalo e repete. Um erro num tick não mata o worker (fecha a ligação à BD,
 * regista e tenta de novo); só depois de muitos erros seguidos é que termina
 * com código 1, para o gestor de serviços o voltar a arrancar.
 */
final class WorkerLoop
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param callable():void $tick  trabalho de cada ciclo
     * @return int código de saída do processo (0 = ok)
     */
    public function run(string $name, callable $tick, int $intervalSeconds, bool $once): int
    {
        set_time_limit(0);

        $maxErrors = (int) $this->settings->get('worker.max_consecutive_errors', 10);
        $errors = 0;
        $this->logger->info("Worker '{$name}' a arrancar", ['intervalo' => $intervalSeconds, 'once' => $once]);

        while (true) {
            $start = microtime(true);

            try {
                $tick();
                $errors = 0;
            } catch (Throwable $e) {
                $errors++;
                $this->logger->error("Worker '{$name}': erro no ciclo ({$errors}/{$maxErrors})", ['exception' => $e]);
                $this->db->reset();   // a ligação pode ter caído: da próxima vez liga de novo

                if ($errors >= $maxErrors) {
                    $this->logger->critical("Worker '{$name}': demasiados erros seguidos, a terminar");
                    return 1;
                }
            }

            if ($once) {
                return $errors > 0 ? 1 : 0;
            }

            $remaining = $intervalSeconds - (microtime(true) - $start);
            if ($remaining > 0) {
                usleep((int) ($remaining * 1_000_000));
            }
        }
    }
}
