<?php
require_once __DIR__ . '/compat.php';
$dotenv = __DIR__ . '/.env';
if (is_file($dotenv)) {
    $lines = file($dotenv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';')
                continue;
            $separator = strpos($line, '=');
            if ($separator === false)
                continue;
            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key))
                continue;
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            putenv($key . '=' . $value);
        }
    }
}
$config = [
    'api_base' => getenv('API_BASE') ?: '',
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_database' => getenv('DB_DATABASE') ?: 'corrida_mpl',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_password' => getenv('DB_PASSWORD') ?: '',
    'jwt_secret' => getenv('JWT_SECRET') ?: 'troque-esta-chave-em-producao',
    'cors_origin' => getenv('CORS_ORIGIN') ?: '*',
    'debug' => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN),
    'site_url' => getenv('SITE_URL') ?: 'http://192.168.0.137/corrida-mpl',
    'event_slug' => '4-corrida-mpl',
    'owner_email' => 'admin@grupompl.com.br',
    'event_date_fallback' => '2026-11-29',
    'event_start_time_fallback' => '07:00',
    'pagarme_secret_key' => getenv('PAGARME_SECRET_KEY') ?: '',
    'pagarme_base_url' => getenv('PAGARME_BASE_URL') ?: 'https://api.pagar.me/core/v5',
    'pagarme_checkout_expires_minutes' => getenv('PAGARME_CHECKOUT_EXPIRES_MINUTES') ?: '60',
    'pagarme_max_installments' => getenv('PAGARME_MAX_INSTALLMENTS') ?: '3',
    'invitation_expires_hours' => getenv('INVITATION_EXPIRES_HOURS') ?: '168',
    'belluno_env' => getenv('BELLUNO_ENV') ?: 'sandbox',
    'belluno_token' => getenv('BELLUNO_TOKEN') ?: '',
    'belluno_base_url' => getenv('BELLUNO_BASE_URL') ?: '',
    'belluno_visitor_id' => getenv('BELLUNO_VISITOR_ID') ?: '',
    'belluno_postback_url' => getenv('BELLUNO_POSTBACK_URL') ?: '',
    'belluno_postback_secret' => getenv('BELLUNO_POSTBACK_SECRET') ?: '',
    'mail_enabled' => getenv('MAIL_ENABLED') ?: '1',
    'mail_from' => getenv('MAIL_FROM') ?: 'noreply@grupompl.com.br'
];
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local))
        $config = array_replace($config, $local);
}
return $config;
