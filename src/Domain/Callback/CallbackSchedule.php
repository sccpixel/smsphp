<?php
declare(strict_types=1);

namespace App\Domain\Callback;

use DateTimeImmutable;

/**
 * Calendário das tentativas. A 1.ª é logo que o pedido é decidido; se falhar, repete-se
 * depois de cada espera da lista (por defeito 1, 2, 5, 10, 20 e 30 minutos = 7 tentativas
 * em cerca de 1 hora). Passada a última espera sem sucesso, desiste.
 */
final class CallbackSchedule
{
    /** @param list<int> $retryMinutes esperas entre tentativas, em minutos */
    public function __construct(private readonly array $retryMinutes)
    {
    }

    /** Número máximo de tentativas (a primeira mais uma por cada espera). */
    public function maxAttempts(): int
    {
        return 1 + count($this->retryMinutes);
    }

    /**
     * Quando fazer a próxima tentativa, depois de $attemptsDone tentativas falhadas.
     *
     * @return DateTimeImmutable|null null = já não há mais tentativas
     */
    public function nextAttemptAt(int $attemptsDone, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $wait = $this->retryMinutes[$attemptsDone - 1] ?? null;

        return $wait === null ? null : $now->modify("+{$wait} minutes");
    }
}
