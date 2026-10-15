<?php
declare(strict_types=1);

namespace App\Action\Message;

use App\Domain\Sms\SmsService;
use App\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /api/messages/{id} — estado de uma mensagem (fila + envio). */
final class GetMessageAction
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        return Json::write($response, $this->sms->get((int) $args['id']));
    }
}
