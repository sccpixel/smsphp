<?php
declare(strict_types=1);

namespace App\Handler;

use App\Exception\ApiException;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Http\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Converte qualquer exceção numa resposta JSON {"error": "...", "details": {...}}.
 * Erros 4xx -> log "warning" sem stack trace; 5xx -> log "error" com stack trace.
 * Os detalhes técnicos só vão na resposta com APP_DEBUG=true.
 */
final class JsonErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Throwable $e): ResponseInterface
    {
        if ($e instanceof ValidationException) {
            $status = 422;
            $body = ['error' => 'Dados inválidos', 'details' => $e->errors()];
        } elseif ($e instanceof NotFoundException) {
            $status = 404;
            $body = ['error' => $e->getMessage()];
        } elseif ($e instanceof HttpNotFoundException) {
            $status = 404;
            $body = ['error' => 'Não encontrado'];
        } elseif ($e instanceof ConflictException) {
            $status = 409;
            $body = ['error' => $e->getMessage()];
        } elseif ($e instanceof HttpMethodNotAllowedException) {
            $status = 405;
            $body = ['error' => 'Método não permitido'];
        } elseif ($e instanceof ApiException) {
            $status = $e->status();
            $body = ['error' => $e->getMessage()];
        } else {
            $status = 500;
            $body = ['error' => 'Erro interno'];
            if ($this->debug) {
                $body['details'] = ['exception' => $e::class, 'message' => $e->getMessage()];
            }
        }

        $context = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath()];
        if ($status >= 500) {
            $this->logger->error($e->getMessage(), $context + ['exception' => $e]);
        } else {
            $this->logger->warning($body['error'], $context + ['status' => $status]);
        }

        $response = Json::write($this->responses->createResponse($status), $body, $status);

        if ($e instanceof HttpMethodNotAllowedException) {
            $response = $response->withHeader('Allow', implode(', ', $e->getAllowedMethods()));
        }
        return $response;
    }
}
