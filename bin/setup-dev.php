<?php
/**
 * Prepara a base de dados de TESTES (idempotente: pode correr-se as vezes que quiseres).
 *
 *   php bin\setup-dev.php            cria o que faltar; só enche com dados de exemplo se a base for nova
 *   php bin\setup-dev.php --seed     apaga tudo e volta a encher com dados de exemplo
 *
 * Usa as credenciais do .env mas o nome da base vem de DB_NAME, que TEM de terminar em "_test".
 * Cria a base, corre sql\01..03 e cria cópias de sms_out/sms_in (no uso real são do programa de SMS).
 */
declare(strict_types=1);

use Dotenv\Dotenv;

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv::createImmutable(APP_ROOT)->safeLoad();

$env = static fn (string $k, string $d = ''): string => (string) ($_ENV[$k] ?? getenv($k) ?: $d);
$dbName = $env('DB_NAME');

if (!str_ends_with($dbName, '_test') || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    fwrite(STDERR, "Recusado: DB_NAME='{$dbName}' tem de terminar em _test (ex.: smsphp_test).\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $env('DB_HOST', '127.0.0.1'), $env('DB_PORT', '3306')),
    $env('DB_USER', 'root'),
    $env('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$exists = $pdo->query("SHOW DATABASES LIKE '{$dbName}'")->fetchColumn() !== false;
if (!$exists) {
    $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Base '{$dbName}' criada.\n";
}
$pdo->exec("USE `{$dbName}`");

// Tabelas nossas (CREATE TABLE IF NOT EXISTS: não mexem no que já existe)
foreach (['01_sms_queue', '02_managers_types', '03_approvals'] as $file) {
    $pdo->exec((string) file_get_contents(APP_ROOT . "/sql/{$file}.sql"));
}

// Tabelas do programa de SMS: só para testes, com a estrutura descrita no plano (secção 4.1)
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS sms_out (
        id INT PRIMARY KEY AUTO_INCREMENT, recipient_number VARCHAR(50), sms_text VARCHAR(255),
        msg_status INT NOT NULL DEFAULT 0, instance_name VARCHAR(255) NULL, sent_dt DATETIME NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS sms_in (
        id INT PRIMARY KEY AUTO_INCREMENT, sms_text VARCHAR(255), sender_number VARCHAR(50), sent_dt DATETIME NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
echo "Tabelas prontas.\n";

$empty = (int) $pdo->query('SELECT COUNT(*) FROM managers')->fetchColumn() === 0;
if (!$exists || $empty || in_array('--seed', $argv, true)) {
    echo "A encher com dados de exemplo...\n";
    passthru('"' . PHP_BINARY . '" -d variables_order=EGPCS ' . escapeshellarg(__DIR__ . '/seed-dev.php'), $code);
    exit($code);
}
echo "Dados existentes mantidos (usa --seed para recomeçar do zero).\n";
