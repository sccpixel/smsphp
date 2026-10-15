<?php
declare(strict_types=1);

namespace App\Action\Approval;

use App\Domain\Approval\ApprovalService;
use App\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /api/approvals/{id} — a APP consulta isto de poucos em poucos segundos para saber o resultado. */
final class GetApprovalAction
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        return Json::write($response, $this->approvals->get((int) $args['id']));
    }
}
