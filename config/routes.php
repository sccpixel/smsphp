<?php
/**
 * Mapa de rotas: método + caminho -> Action que trata o pedido.
 */
declare(strict_types=1);

use App\Action\HealthAction;
use App\Action\PingAction;
use App\Middleware\ApiAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    // Fora da autenticação
    $app->get('/health', HealthAction::class);

    // Tudo o que está em /api exige chave de API
    $app->group('/api', function (RouteCollectorProxy $api): void {
        $api->get('/ping', PingAction::class);   // temporária: remover na fase 4

        // Fase 4: /api/messages
        // Fase 6: /api/approvals
    })->add(ApiAuthMiddleware::class);
};
