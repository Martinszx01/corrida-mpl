<?php
function conviteCriptografar($token, $secret) {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('A extensão OpenSSL é necessária para gerar convites.');
    $cipher = 'AES-256-CBC';
    $iv = random_bytes(openssl_cipher_iv_length($cipher));
    $encrypted = openssl_encrypt($token, $cipher, hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) throw new RuntimeException('Não foi possível proteger o convite.');
    return base64_encode($iv . $encrypted);
}

function conviteDescriptografar($value, $secret) {
    if (!function_exists('openssl_decrypt')) return '';
    $cipher = 'AES-256-CBC';
    $raw = base64_decode((string) $value, true);
    $length = openssl_cipher_iv_length($cipher);
    if ($raw === false || strlen($raw) <= $length) return '';
    $token = openssl_decrypt(substr($raw, $length), $cipher, hash('sha256', $secret, true), OPENSSL_RAW_DATA, substr($raw, 0, $length));
    return $token === false ? '' : $token;
}

function conviteLink($config, $token) {
    return rtrim((string) $config['site_url'], '/') . '/inscricao?convite=' . rawurlencode($token);
}

