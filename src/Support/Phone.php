<?php
declare(strict_types=1);

namespace App\Support;

use App\Exception\ValidationException;

/**
 * Números de telefone.
 *
 * normalize(): formato guardado e enviado ao programa de SMS (só dígitos, sem +351).
 * key():       forma de comparar números (últimos 9 dígitos).
 */
final class Phone
{
    /** '+351 912 345 678' -> '912345678'. Lança ValidationException se for inválido. */
    public static function normalize(string $number, string $field = 'to'): string
    {
        $n = preg_replace('/[\s\-().]/', '', $number) ?? '';

        if (str_starts_with($n, '+351')) {
            $n = substr($n, 4);
        } elseif (str_starts_with($n, '00351')) {
            $n = substr($n, 5);
        } elseif (preg_match('/^351\d{9}$/', $n) === 1) {
            $n = substr($n, 3);
        }

        // Se ainda tiver '+' ou '00' à frente é de outro país: não aceitamos
        if (!preg_match('/^\d{6,15}$/', $n) || str_starts_with($n, '00')) {
            throw new ValidationException([$field => 'Número de telefone inválido']);
        }

        return $n;
    }

    /** Últimos 9 dígitos: '+351912345678', '351912345678' e '912345678' dão todos '912345678'. */
    public static function key(string $number): string
    {
        return substr(preg_replace('/\D/', '', $number) ?? '', -9);
    }
}
