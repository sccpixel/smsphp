<?php
declare(strict_types=1);

namespace App\Domain\Approval;

use RuntimeException;

/**
 * Gera o código de 4 dígitos que o gestor usa na resposta ("SIM 4821").
 * Tem de ser único entre os pedidos pendentes, para a resposta não ser ambígua.
 */
final class CodeGenerator
{
    private const MAX_ATTEMPTS = 20;

    public function __construct(private readonly ApprovalRepository $approvals)
    {
    }

    public function generate(): string
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $code = (string) random_int(1000, 9999);
            if (!$this->approvals->pendingCodeExists($code)) {
                return $code;
            }
        }

        throw new RuntimeException('Não foi possível gerar um código único para o pedido');
    }
}
