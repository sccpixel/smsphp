<?php
declare(strict_types=1);

namespace App\Action\ApprovalType;

use App\Domain\ApprovalType\ApprovalTypeRepository;
use App\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /api/approval-types — tipos de pedido que a APP pode usar no campo "type". */
final class ListApprovalTypesAction
{
    public function __construct(private readonly ApprovalTypeRepository $types)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return Json::write($response, $this->types->listActive());
    }
}
