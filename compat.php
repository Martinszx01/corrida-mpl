<?php
// Funções necessárias para execução em PHP 5.5.
if (!function_exists('hash_equals')) {
    function hash_equals($known, $given)
    {
        if (!is_string($known) || !is_string($given) || strlen($known) !== strlen($given))
            return false;
        $difference = 0;
        for ($i = 0; $i < strlen($known); $i++)
            $difference |= ord($known[$i]) ^ ord($given[$i]);
        return $difference === 0;
    }
}
if (!function_exists('random_bytes')) {
    function random_bytes($length)
    {
        if ($length < 1)
            throw new Exception('Tamanho de token inválido.');
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($strong && $bytes !== false && strlen($bytes) === $length)
                return $bytes;
        }
        $handle = @fopen('/dev/urandom', 'rb');
        if ($handle) {
            $bytes = fread($handle, $length);
            fclose($handle);
            if (strlen($bytes) === $length)
                return $bytes;
        }
        throw new Exception('Ative OpenSSL para gerar tokens seguros.');
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($text, $prefix)
    {
        return $prefix === '' || substr($text, 0, strlen($prefix)) === $prefix;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($text, $suffix)
    {
        return $suffix === '' || substr($text, -strlen($suffix)) === $suffix;
    }
}
