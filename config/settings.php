<?php
/**
 * Toda a configuração da aplicação num único array.
 * Os valores vêm do .env; o segundo argumento de $env() é o valor por defeito.
 *
 * No resto do código lê-se com:  $settings->get('db.host')
 */
declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $default;
$bool = static fn (string $key, bool $default): bool =>
    filter_var($env($key, $default), FILTER_VALIDATE_BOOLEAN);

// Lista separada por vírgulas -> array (sem espaços nem entradas vazias)
$list = static fn (string $key): array =>
    array_values(array_filter(array_map('trim', explode(',', (string) $env($key, ''))), 'strlen'));

// Nome de tabela: só letras, números e _ (evita SQL injection vinda do .env)
$table = static function (string $key, string $default) use ($env): string {
    $name = (string) $env($key, $default);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("Nome de tabela inválido em {$key}");
    }
    return $name;
};

return [
    'app' => [
        'name'     => $env('APP_NAME', 'SMS Gateway'),
        'debug'    => $bool('APP_DEBUG', false),     // true = mostra detalhes dos erros
        'timezone' => $env('APP_TIMEZONE', 'Europe/Lisbon'),
    ],

    'db' => [
        'host'     => $env('DB_HOST', '127.0.0.1'),
        'port'     => (int) $env('DB_PORT', 3306),
        'name'     => $env('DB_NAME', 'sms'),
        'user'     => $env('DB_USER', 'root'),
        'password' => $env('DB_PASSWORD', ''),
    ],

    'log' => [
        'path'  => APP_ROOT . '/logs',
        'level' => $env('LOG_LEVEL', 'info'),
    ],

    // Nomes das tabelas (vêm do .env, por isso são validados antes de entrarem no SQL)
    'tables' => [
        'sms_out'   => $table('SMS_OUT_TABLE', 'sms_out'),
        'sms_in'    => $table('SMS_IN_TABLE', 'sms_in'),
        'sms_queue' => $table('SMS_QUEUE_TABLE', 'sms_queue'),
    ],

    'auth' => [
        'enabled'     => $bool('AUTH_ENABLED', true),     // false = aceita pedidos sem chave
        'api_keys'    => $list('API_KEYS'),
        'allowed_ips' => $list('ALLOWED_IPS'),            // vazio = qualquer IP
    ],

    'sms' => [
        'max_text_length' => (int) $env('MAX_TEXT_LENGTH', 255),
    ],
];
