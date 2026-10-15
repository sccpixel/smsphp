<?php
/**
 * Arranque comum: usado pela API (public/index.php) e, mais tarde, pelos workers (bin/).
 *   1. carrega o autoload do Composer
 *   2. lê o .env
 *   3. constrói e devolve o container
 */
declare(strict_types=1);

use App\Support\Settings;
use DI\ContainerBuilder;
use Dotenv\Dotenv;

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/vendor/autoload.php';

Dotenv::createImmutable(APP_ROOT)->safeLoad();

$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/container.php');
$container = $builder->build();

date_default_timezone_set($container->get(Settings::class)->get('app.timezone'));

return $container;
