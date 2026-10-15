<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

/**
 * Ligação à base de dados.
 *
 * A ligação só é aberta na primeira vez que alguém chama pdo() ("lazy").
 * Assim, uma rota que não usa a base de dados não liga ao MySQL.
 */
final class Connection
{
    private ?PDO $pdo = null;
    private ?string $lastError = null;

    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /** Testa a ligação. Usado pelo /health. */
    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            $this->lastError = null;
            return true;
        } catch (PDOException $e) {
            $this->pdo = null;   // da próxima vez tenta ligar de novo
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /** Motivo da última falha do ping() (ex.: "Access denied for user..."). */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** Fecha a ligação; a próxima chamada a pdo() volta a ligar (útil nos workers). */
    public function reset(): void
    {
        $this->pdo = null;
    }

    private function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            $this->config['port'],
            $this->config['name'],
        );

        return new PDO($dsn, $this->config['user'], $this->config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // erros SQL viram exceções
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // linhas como arrays associativos
            PDO::ATTR_EMULATE_PREPARES   => false,                   // prepared statements reais
        ]);
    }
}
