<?php
declare(strict_types=1);

namespace App\Action\Approval;

use App\Domain\Approval\ApprovalService;
use App\Http\Json;
use App\Http\RequestBody;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** POST /api/approvals — cria um pedido e põe o SMS para o gestor na fila. */
final class CreateApprovalAction
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $result = $this->approvals->create(RequestBody::read($request), $request->getAttribute('api_client'));

        return Json::write($response, $result, 201);
    }
}
