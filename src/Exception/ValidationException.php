<?php
declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/** Dados do pedido inválidos -> HTTP 422. `$errors` é ['campo' => 'mensagem']. */
final class ValidationException extends RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors, string $message = 'Dados inválidos')
    {
        parent::__construct($message);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
