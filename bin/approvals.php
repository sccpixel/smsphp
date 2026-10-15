<?php
/**
 * Worker das aprovações: expira pedidos e lê as respostas dos gestores no sms_in.
 *
 *   php bin\approvals.php          corre em ciclo (Ctrl+C para parar)
 *   php bin\approvals.php --once   faz um único ciclo e termina (útil para testes)
 */
declare(strict_types=1);

use App\Worker\ApprovalWorker;

$container = require __DIR__ . '/../config/bootstrap.php';

exit($container->get(ApprovalWorker::class)->run(in_array('--once', $argv, true)));
