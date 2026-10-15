<?php
/**
 * Enche a base de dados de TESTES com dados de exemplo (gestores, mensagens, pedidos).
 *
 *   php bin\seed-dev.php
 *
 * APAGA o conteúdo de sms_queue, sms_out, sms_in, approval_requests, managers e worker_state.
 * Por segurança só corre se DB_NAME terminar em "_test" (ex.: smsphp_test).
 * Os números são fictícios (912000001...): troca-os pelos teus se quiseres testar com o modem.
 */
declare(strict_types=1);

use App\Database\Connection;
use App\Support\Clock;
use App\Support\Settings;

$container = require __DIR__ . '/../config/bootstrap.php';

$settings = $container->get(Settings::class);
$dbName = (string) $settings->get('db.name');
if (!str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "Recusado: DB_NAME='{$dbName}' não termina em _test. Este script apaga dados.\n");
    exit(1);
}

$pdo = $container->get(Connection::class)->pdo();
$clock = $container->get(Clock::class);
$t = $settings->get('tables');
$ago = static fn (int $minutes): string => $clock->now()->modify(sprintf("%+d minutes", -$minutes))->format('Y-m-d H:i:s');

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (["`{$t['sms_queue']}`", "`{$t['sms_out']}`", "`{$t['sms_in']}`", 'approval_requests', 'managers', 'worker_state'] as $table) {
    $pdo->exec("TRUNCATE TABLE {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// --- Gestores -------------------------------------------------------------------------------
$manager = $pdo->prepare(
    'INSERT INTO managers (name, phone_number, phone_key, is_default, active, created_at) VALUES (?, ?, ?, ?, ?, ?)'
);
foreach ([
    ['Joao Silva',    '912000001', 1, 1],
    ['Maria Santos',  '912000002', 0, 1],
    ['Carlos Pereira', '912000003', 0, 0],   // inativo
] as [$name, $phone, $default, $active]) {
    $manager->execute([$name, $phone, $phone, $default, $active, $ago(60 * 24)]);
}

/** Cria o SMS na fila e (se despachado) em sms_out; devolve o id da fila. msg_status: 0 por enviar, 1 enviado, 2 erro. */
$sms = static function (string $to, string $text, string $apiClient, int $createdMinutesAgo, string $state, ?int $msgStatus = null) use ($pdo, $t, $ago): int {
    $pdo->prepare("INSERT INTO `{$t['sms_queue']}` (recipient_number, sms_text, status, api_client, created_at) VALUES (?, ?, 0, ?, ?)")
        ->execute([$to, $text, $apiClient, $ago($createdMinutesAgo)]);
    $queueId = (int) $pdo->lastInsertId();

    if ($state === 'failed') {
        $pdo->prepare("UPDATE `{$t['sms_queue']}` SET status = 2, error_message = 'Erro de exemplo' WHERE id = ?")->execute([$queueId]);
    } elseif ($state === 'dispatched') {
        $sent = $msgStatus === 1 ? $ago($createdMinutesAgo - 1) : null;
        $pdo->prepare("INSERT INTO `{$t['sms_out']}` (recipient_number, sms_text, msg_status, sent_dt) VALUES (?, ?, ?, ?)")
            ->execute([$to, $text, $msgStatus ?? 0, $sent]);
        $pdo->prepare("UPDATE `{$t['sms_queue']}` SET status = 1, send_id = ?, dispatched_at = ? WHERE id = ?")
            ->execute([(int) $pdo->lastInsertId(), $ago($createdMinutesAgo), $queueId]);
    }
    return $queueId;
};

// --- Mensagens normais -----------------------------------------------------------------------
$sms('912345678', 'Ola, a sua marcacao esta confirmada para amanha as 10h.', 'chave-de', 90, 'dispatched', 1);
$sms('913456789', 'Lembrete: consulta hoje as 15h.', 'chave-de', 45, 'dispatched', 1);
$sms('914567890', 'O seu pedido esta pronto para levantar.', 'chave-de', 30, 'dispatched', 2);   // modem falhou
$sms('915678901', 'Promocao ate sexta-feira!', 'chave-de', 10, 'failed');                          // falhou no dispatcher
$sms('916789012', 'Esta mensagem ainda esta por despachar.', 'chave-de', 1, 'pending');

// --- Pedidos de aprovação --------------------------------------------------------------------
$approval = $pdo->prepare(
    "INSERT INTO approval_requests
        (code, approval_type_id, manager_id, description, reference, requested_by, status,
         created_at, expires_at, responded_at, response_text, queue_id, api_client)
     VALUES (?, (SELECT id FROM approval_types WHERE code = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'chave-de')"
);
$mk = static function (string $code, string $type, int $managerId, string $desc, string $ref, string $by, string $status,
                       int $createdAgo, int $validMin, ?int $respondedAgo, ?string $reply, string $smsState, ?int $msgStatus)
    use ($approval, $sms, $ago): void {
    $label = $type === 'discount' ? 'Desconto' : 'Mov. armazem';
    $queueId = $sms('91200000' . $managerId, "Pedido {$code} - {$label}: {$desc}. Responda SIM {$code} ou NAO {$code}. Valido {$validMin} min.",
        'chave-de', $createdAgo, $smsState, $msgStatus);
    $approval->execute([
        $code, $type, $managerId, $desc, $ref, $by, $status,
        $ago($createdAgo), $ago($createdAgo - $validMin),
        $respondedAgo === null ? null : $ago($respondedAgo), $reply, $queueId,
    ]);
};

//  codigo  tipo          gestor descricao                                  referencia    operador         estado      criado valido resp  resposta   sms          msg_status
$mk('4821', 'discount',   1, '15% na venda 1234, cliente Silva',            'VENDA-1234', 'Operador Ana',  'pending',  2,  10, null, null,      'dispatched', 1);
$mk('5190', 'stock_move', 2, 'Transferir 20 un. do armazem 1 para o 2',     'MOV-0087',   'Operador Rui',  'pending',  1,  10, null, null,      'dispatched', 0);
$mk('7302', 'discount',   1, '30% na venda 1230, cliente Costa',            'VENDA-1230', 'Operador Ana',  'approved', 40, 10, 38,   'SIM 7302', 'dispatched', 1);
$mk('6644', 'discount',   1, '50% na venda 1228, cliente Lopes',            'VENDA-1228', 'Operador Rui',  'rejected', 55, 10, 53,   'NAO 6644', 'dispatched', 1);
$mk('3318', 'stock_move', 2, 'Saida de 5 un. para reparacao',               'MOV-0080',   'Operador Ana',  'expired',  120, 10, null, null,     'dispatched', 1);
$mk('8875', 'discount',   1, '10% na venda 1225, cliente Dias',             'VENDA-1225', 'Operador Rui',  'cancelled', 70, 10, null, null,     'dispatched', 1);
$mk('2459', 'discount',   1, 'Pedido cujo SMS nunca chegou ao gestor',      'VENDA-1222', 'Operador Ana',  'pending',  3,  10, null, null,      'dispatched', 2);

// --- Respostas antigas no sms_in (o worker ignora-as na 1.ª execução) ------------------------
$pdo->prepare("INSERT INTO `{$t['sms_in']}` (sms_text, sender_number, sent_dt) VALUES (?, ?, ?)")
    ->execute(['SIM 7302', '+351912000001', $ago(38)]);
$pdo->prepare("INSERT INTO `{$t['sms_in']}` (sms_text, sender_number, sent_dt) VALUES (?, ?, ?)")
    ->execute(['NAO 6644', '912000001', $ago(53)]);

echo "Base '{$dbName}' preenchida:\n";
foreach (['managers', "`{$t['sms_queue']}`", "`{$t['sms_out']}`", 'approval_requests', "`{$t['sms_in']}`"] as $table) {
    echo sprintf("  %-20s %d linhas\n", trim($table, '`'), (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
}
