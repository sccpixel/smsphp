<?php
declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Erro HTTP com código próprio, lançado pelo middleware de autenticação
 * (401, 403, 503). Os restantes erros usam as outras exceções desta pasta.
 */
final class ApiException extends RuntimeException
{
    public function __construct(private readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
