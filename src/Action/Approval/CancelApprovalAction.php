<?php
declare(strict_types=1);

namespace App\Action\Approval;

use App\Domain\Approval\ApprovalService;
use App\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** POST /api/approvals/{id}/cancel — a APP desiste do pedido (só se ainda estiver pendente). */
final class CancelApprovalAction
{
    public function __construct(private readonly ApprovalService $approvals)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        return Json::write($response, $this->approvals->cancel((int) $args['id']));
    }
}
