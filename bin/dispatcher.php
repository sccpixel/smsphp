<?php
/**
 * Worker que passa as mensagens de sms_queue para sms_out.
 *
 *   php bin\dispatcher.php          corre em ciclo (Ctrl+C para parar)
 *   php bin\dispatcher.php --once   faz um único ciclo e termina (útil para testes)
 */
declare(strict_types=1);

use App\Worker\DispatcherWorker;

$container = require __DIR__ . '/../config/bootstrap.php';

exit($container->get(DispatcherWorker::class)->run(in_array('--once', $argv, true)));
