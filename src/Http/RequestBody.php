<?php
declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Lê o body de um pedido como array. Aceita JSON e formulário
 * (application/x-www-form-urlencoded). Se o Content-Type faltar e o Slim não
 * conseguir ler nada, tenta descodificar o body em bruto como JSON.
 */
final class RequestBody
{
    /** @return array<mixed> */
    public static function read(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $raw = (string) $request->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
