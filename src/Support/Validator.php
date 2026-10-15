<?php
declare(strict_types=1);

namespace App\Support;

use App\Exception\ValidationException;

/**
 * Validador pequeno. Cada método verifica um campo; os erros vão-se juntando
 * e só no fim, com throwIfFailed(), é lançada UMA ValidationException com todos.
 *
 *   $v = new Validator($body);
 *   $v->string('text', 1, 255);
 *   $v->url('callback_url');
 *   $v->throwIfFailed();
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<mixed> $data  @param string $prefix prefixo das chaves de erro (ex.: 'messages.3.') */
    public function __construct(private readonly array $data, private readonly string $prefix = '')
    {
    }

    /** Campo presente e não vazio. */
    public function required(string $field): self
    {
        $v = $this->data[$field] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $this->fail($field, 'Campo obrigatório');
        }
        return $this;
    }

    /** Texto (com trim) com entre $min e $max caracteres. Campo ausente só falha se $min > 0. */
    public function string(string $field, int $min = 0, int $max = 255): self
    {
        $v = $this->data[$field] ?? null;
        if ($v === null) {
            return $min > 0 ? $this->fail($field, 'Campo obrigatório') : $this;
        }
        if (!is_string($v)) {
            return $this->fail($field, 'Tem de ser texto');
        }
        $len = mb_strlen(trim($v));
        if ($len < $min) {
            return $this->fail($field, $min === 1 ? 'Campo obrigatório' : "Mínimo {$min} caracteres");
        }
        if ($len > $max) {
            return $this->fail($field, "Máximo {$max} caracteres");
        }
        return $this;
    }

    public function maxLength(string $field, int $max): self
    {
        return $this->string($field, 0, $max);
    }

    /** Número inteiro (aceita "5" vindo de formulário). Campo ausente ou vazio passa. */
    public function int(string $field): self
    {
        $v = $this->data[$field] ?? null;
        if ($v !== null && $v !== '' && filter_var($v, FILTER_VALIDATE_INT) === false) {
            $this->fail($field, 'Tem de ser um número inteiro');
        }
        return $this;
    }

    /** URL http/https válido. Campo ausente ou vazio passa. */
    public function url(string $field): self
    {
        $v = $this->data[$field] ?? null;
        if ($v === null || $v === '') {
            return $this;
        }
        if (!is_string($v) || filter_var($v, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $v)) {
            $this->fail($field, 'URL inválido');
        }
        return $this;
    }

    public function fail(string $field, string $message): self
    {
        $this->errors[$this->prefix . $field] ??= $message;   // fica a primeira mensagem de cada campo
        return $this;
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function throwIfFailed(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
    }
}
