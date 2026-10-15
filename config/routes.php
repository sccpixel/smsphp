<?php
/**
 * Mapa de rotas: método + caminho -> Action que trata o pedido.
 */
declare(strict_types=1);

use App\Action\HealthAction;
use App\Action\Message\CreateMessageAction;
use App\Action\Message\CreateMessageBatchAction;
use App\Action\Message\GetMessageAction;
use App\Middleware\ApiAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    // Fora da autenticação
    $app->get('/health', HealthAction::class);

    // Tudo o que está em /api exige chave de API
    $app->group('/api', function (RouteCollectorProxy $api): void {
        $api->post('/messages', CreateMessageAction::class);
        $api->post('/messages/batch', CreateMessageBatchAction::class);
        $api->get('/messages/{id:[0-9]+}', GetMessageAction::class);

        // Fase 5: /api/managers, /api/approval-types
        // Fase 6: /api/approvals
    })->add(ApiAuthMiddleware::class);
};
