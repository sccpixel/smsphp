<?php
declare(strict_types=1);

namespace App\Worker;

use App\Domain\Callback\CallbackService;
use App\Support\Settings;

/**
 * Worker dos callbacks: a cada ciclo envia os avisos pendentes às APPs.
 * Corre à parte do worker de aprovações, para uma APP lenta nunca atrasar a leitura das respostas dos gestores.
 * Só pode correr UM destes de cada vez.
 */
final class CallbackWorker
{
    public function __construct(
        private readonly WorkerLoop $loop,
        private readonly CallbackService $callbacks,
        private readonly Settings $settings,
    ) {
    }

    public function run(bool $once): int
    {
        return $this->loop->run(
            'callbacks',
            fn () => $this->tick(),
            (int) $this->settings->get('callbacks.interval_seconds', 5),
            $once,
        );
    }

    /** @return int quantos callbacks foram tentados */
    public function tick(): int
    {
        return $this->callbacks->processDue();
    }
}
