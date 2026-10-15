<?php
declare(strict_types=1);

namespace App\Domain\Callback;

/**
 * Assina o conteúdo do callback, para a APP ter a certeza de que veio do gateway e não foi alterado.
 *
 * A assinatura é um HMAC-SHA256 de "<timestamp>.<corpo>" com a chave de API da própria APP como segredo
 * (a APP já a conhece). Incluir o timestamp permite à APP recusar callbacks antigos repetidos por um atacante.
 *
 * A APP verifica assim:  hash_equals( 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $corpo, $suaChave), $assinatura )
 */
final class CallbackSigner
{
    public function sign(string $secret, int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}
