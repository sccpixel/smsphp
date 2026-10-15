<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Acesso à configuração com "caminhos" separados por pontos.
 *
 *   $settings->get('db.host')         -> '127.0.0.1'
 *   $settings->get('db')              -> o array inteiro da base de dados
 *   $settings->get('nao.existe', 10)  -> 10
 */
final class Settings
{
    public function __construct(private readonly array $values)
    {
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
