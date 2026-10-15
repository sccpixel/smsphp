<?php
/**
 * Worker dos callbacks: avisa as APPs (POST ao callback_url) quando um pedido de aprovação é decidido.
 *
 *   php bin\callbacks.php          corre em ciclo (Ctrl+C para parar)
 *   php bin\callbacks.php --once   faz um único ciclo e termina (útil para testes)
 */
declare(strict_types=1);

use App\Worker\CallbackWorker;

$container = require __DIR__ . '/../config/bootstrap.php';

exit($container->get(CallbackWorker::class)->run(in_array('--once', $argv, true)));
