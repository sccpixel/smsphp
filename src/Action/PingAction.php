<?php
declare(strict_types=1);

namespace App\Action;

use App\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /api/ping — TEMPORÁRIA (remover na fase 4): serve só para testar a autenticação. */
final class PingAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        return Json::write($response, ['client' => $request->getAttribute('api_client')]);
    }
}
