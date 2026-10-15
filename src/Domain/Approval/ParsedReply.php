<?php
declare(strict_types=1);

namespace App\Domain\Approval;

/** Resposta do gestor já interpretada: aprova ou rejeita, e qual o pedido (código de 4 dígitos). */
final class ParsedReply
{
    public function __construct(
        public readonly bool $approve,
        public readonly string $code,
    ) {
    }
}
