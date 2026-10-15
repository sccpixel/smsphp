<?php
declare(strict_types=1);

namespace App\Action;

use App\Database\Connection;
use App\Http\Json;
use App\Support\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * GET /health — diz se a API está viva e se consegue falar com a base de dados.
 *
 * Não criamos a Connection nem as Settings aqui: o container vê o construtor
 * e entrega-as já prontas (injeção de dependências).
 */
final class HealthAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $dbOk = $this->db->ping();

        if (!$dbOk) {
            // O motivo vai só para o log: não expomos detalhes da BD na resposta
            $this->logger->warning('Health: sem ligação à base de dados', ['erro' => $this->db->lastError()]);
        }

        return Json::write($response, [
            'status'   => $dbOk ? 'ok' : 'degraded',
            'app'      => $this->settings->get('app.name'),
            'database' => $dbOk,
            'time'     => date('c'),
        ], $dbOk ? 200 : 503);
    }
}
