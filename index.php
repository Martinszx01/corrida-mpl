<?php
$config = require __DIR__ . '/config.php';
// base_url robusto: caminho de URL da pasta onde este index.php está,
// funciona em subpasta do Apache/XAMPP, na raiz do Apache e no `php -S`.
$docRoot = str_replace('\\', '/', rtrim((string) ((isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '')), '/'));
$appDir = str_replace('\\', '/', __DIR__);
$base = '';
if ($docRoot !== '' && strpos($appDir, $docRoot) === 0) {
  $base = rtrim(substr($appDir, strlen($docRoot)), '/');
} elseif (php_sapi_name() !== 'cli-server') {
  $base = rtrim(str_replace('\\', '/', dirname((isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''))), '/');
}
$config['base_url'] = $base;
$config['api_base'] = $base . '/api.php';
$publicConfig = array(
  'api_base' => $config['api_base'],
  'site_url' => $config['site_url'],
  'event_slug' => $config['event_slug'],
  'base_url' => $config['base_url']
);
?><!doctype html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#1a4a8a">
  <title>4ª Corrida MPL</title>
  <link rel="icon" href="<?= htmlspecialchars($base) ?>/assets/images/logo/favicon.svg">
  <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/style.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/ux-modern.css?v=20260916">
</head>

<body>
  <div id="app" aria-live="polite"></div>
  <script>window.MPL_CONFIG = <?= json_encode($publicConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="<?= htmlspecialchars($base) ?>/assets/vendor/qrcode.min.js"></script>
  <script src="<?= htmlspecialchars($base) ?>/assets/vendor/jsencrypt.min.js"></script>
  <script src="<?= htmlspecialchars($base) ?>/assets/vendor/html5-qrcode.min.js"></script>
  <script src="<?= htmlspecialchars($base) ?>/app.js?v=20260916"></script>
</body>

</html>
