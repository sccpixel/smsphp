<?php
declare(strict_types=1);

namespace App\Domain\Approval;

use App\Support\Text;

/**
 * Interpreta o SMS do gestor. Formato estrito, sem ambiguidade:
 *
 *   SIM 4821   ou   NAO 4821   (maiúsculas/minúsculas e o acento de "não" são indiferentes)
 *
 * Qualquer outra coisa ("SIM", "OK 4821", "SIM 4821 obrigado", "SIM 4821 4822") não é
 * reconhecida e devolve null. Classe pura: não acede à base de dados.
 */
final class ReplyParser
{
    public function parse(string $text): ?ParsedReply
    {
        $normalized = mb_strtoupper(trim(Text::stripAccents($text)));

        if (preg_match('/^(SIM|NAO) ([0-9]{4})$/D', $normalized, $m) !== 1) {
            return null;
        }

        return new ParsedReply($m[1] === 'SIM', $m[2]);
    }
}
