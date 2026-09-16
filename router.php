<?php
// Router para o servidor embutido do PHP (php -S), emulando o .htaccess.
$uri = urldecode(parse_url((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), PHP_URL_PATH));
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // Servir arquivos estáticos e .php existentes (ex.: api.php)
}
require __DIR__ . '/index.php';
