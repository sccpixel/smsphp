<?php
/**
 * Definições do container (PHP-DI).
 *
 * Aqui só ficam as classes que precisam de "receita" para serem criadas
 * (porque dependem de configuração). As restantes, como as Actions,
 * o PHP-DI cria sozinho olhando para o construtor (autowiring).
 */
declare(strict_types=1);

use App\Database\Connection;
use App\Handler\JsonErrorHandler;
use App\Support\Clock;
use App\Support\Settings;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    // Configuração: lê o array de config/settings.php
    Settings::class => fn () => new Settings(require __DIR__ . '/settings.php'),

    // Ligação à base de dados (só liga ao MySQL quando for mesmo preciso)
    Connection::class => fn (ContainerInterface $c) => new Connection(
        $c->get(Settings::class)->get('db')
    ),

    // Relógio da aplicação (fuso APP_TIMEZONE)
    Clock::class => fn (ContainerInterface $c) => new Clock($c->get(Settings::class)->get('app.timezone')),

    // Handler de erros JSON: precisa de saber se o modo debug está ligado
    JsonErrorHandler::class => fn (ContainerInterface $c) => new JsonErrorHandler(
        $c->get(ResponseFactoryInterface::class),
        $c->get(LoggerInterface::class),
        $c->get(Settings::class)->get('app.debug'),
    ),
    ResponseFactoryInterface::class => fn () => new ResponseFactory(),

    // Logs: um ficheiro por dia em logs/, guardados 14 dias
    LoggerInterface::class => function (ContainerInterface $c) {
        $config = $c->get(Settings::class)->get('log');
        $level = Level::fromName($config['level']);

        $logger = new Logger('app');
        $logger->pushHandler(new RotatingFileHandler($config['path'] . '/app.log', 14, $level));

        // Na linha de comandos (workers), mostra também no ecrã
        if (PHP_SAPI === 'cli') {
            $logger->pushHandler(new StreamHandler('php://stdout', $level));
        }
        return $logger;
    },
];
