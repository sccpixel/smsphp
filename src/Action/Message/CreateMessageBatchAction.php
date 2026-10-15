<?php
declare(strict_types=1);

namespace App\Action\Message;

use App\Domain\Sms\SmsService;
use App\Http\Json;
use App\Http\RequestBody;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** POST /api/messages/batch — até 500 mensagens de uma vez (tudo ou nada). */
final class CreateMessageBatchAction
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $result = $this->sms->createBatch(RequestBody::read($request), $request->getAttribute('api_client'));

        return Json::write($response, $result, 201);
    }
}
