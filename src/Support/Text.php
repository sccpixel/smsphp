<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Tratamento de texto para SMS.
 *
 * Um SMS só com caracteres GSM-7 simples cabe em 160 caracteres; basta um
 * acento ou símbolo fora desse conjunto para passar a UCS-2 (70 caracteres).
 */
final class Text
{
    /** Letras acentuadas -> equivalente sem acento. */
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y',
        'º' => 'o', 'ª' => 'a', '–' => '-', '—' => '-', '‘' => "'", '’' => "'", '“' => '"', '”' => '"',
    ];

    /**
     * Deixa só caracteres seguros para SMS: tira os acentos e remove tudo o que
     * não for letra, número, espaço ou pontuação simples. Espaços repetidos,
     * tabs e mudanças de linha ficam um único espaço.
     *
     *   Text::toGsm("15% na venda, cliente João Açoreano")  ->  "15% na venda, cliente Joao Acoreano"
     */
    public static function toGsm(string $text): string
    {
        $text = strtr($text, self::ACCENTS);
        $text = preg_replace('/[^A-Za-z0-9 .,:;!?()%&@#*=+\/_\'"-]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /** Corta para $max caracteres (sem deixar um espaço no fim). */
    public static function cut(string $text, int $max): string
    {
        return rtrim(mb_substr($text, 0, max(0, $max)));
    }
}
