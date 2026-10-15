<?php
/**
 * Middleware global: camadas que envolvem TODAS as rotas.
 *
 * Atenção à ordem: no Slim, o último a ser adicionado é o primeiro a correr.
 * Por isso o ErrorMiddleware fica no fim: é a camada de fora e apanha
 * os erros de todas as outras.
 */
declare(strict_types=1);

use App\Handler\JsonErrorHandler;
use App\Support\Settings;
use Slim\App;

return function (App $app): void {
    $container = $app->getContainer();
    $debug = $container->get(Settings::class)->get('app.debug');

    // Lê o body dos pedidos (JSON ou formulário) para $request->getParsedBody()
    $app->addBodyParsingMiddleware();

    // Descobre qual a rota que corresponde ao pedido
    $app->addRoutingMiddleware();

    // Converte qualquer erro numa resposta JSON (o nosso JsonErrorHandler trata também do log).
    $errorMiddleware = $app->addErrorMiddleware($debug, false, false);
    $errorMiddleware->setDefaultErrorHandler($container->get(JsonErrorHandler::class));
};
