<?php
/**
 * Ponto de entrada da API. Todos os pedidos HTTP passam por aqui.
 * Este ficheiro só monta a aplicação: não tem lógica nenhuma.
 */
declare(strict_types=1);

use Slim\Factory\AppFactory;

$container = require __DIR__ . '/../config/bootstrap.php';

AppFactory::setContainer($container);
$app = AppFactory::create();

// 3. Middleware e rotas
(require __DIR__ . '/../config/middleware.php')($app);
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
