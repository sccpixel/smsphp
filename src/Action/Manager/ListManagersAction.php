<?php
declare(strict_types=1);

namespace App\Action\Manager;

use App\Domain\Manager\ManagerRepository;
use App\Http\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /api/managers — gestores ativos (para a APP escolher a quem pedir). Sem telefone. */
final class ListManagersAction
{
    public function __construct(private readonly ManagerRepository $managers)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return Json::write($response, $this->managers->listActive());
    }
}
