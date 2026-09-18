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
$requestPath = parse_url((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), PHP_URL_PATH);
$relativePath = ($base !== '' && strpos($requestPath, $base) === 0) ? substr($requestPath, strlen($base)) : $requestPath;
$canonicalUrl = rtrim($config['site_url'], '/') . ($relativePath ? '/' . ltrim($relativePath, '/') : '');
$isSponsorship = preg_match('#/patrocinio/?$#', $requestPath) === 1;
$pageTitle = $isSponsorship ? 'Patrocínio | 4ª Corrida MPL' : '4ª Corrida MPL';
$pageDescription = $isSponsorship
  ? 'Conheça a 4ª Corrida MPL, o público das edições anteriores e as oportunidades de patrocínio.'
  : 'Site oficial da 4ª Corrida do Grupo MPL.';
$publicConfig = array(
  'api_base' => $config['api_base'],
  'site_url' => $config['site_url'],
  'event_slug' => $config['event_slug'],
  'base_url' => $config['base_url']
);
$cspNonce = base64_encode(random_bytes(18));
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $cspNonce . "'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; media-src 'self' blob:; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
?><!doctype html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#1a4a8a">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image" content="<?= htmlspecialchars(rtrim($config['site_url'], '/') . '/assets/images/banners/banner-principal.jpg?v=20260918', ENT_QUOTES, 'UTF-8') ?>">
  <link rel="icon" href="<?= htmlspecialchars($base) ?>/assets/images/logo/favicon.svg">
  <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/style.css?v=20260918a">
  <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/ux-modern.css?v=20260916d">
</head>

<body>
  <div id="app" aria-live="polite"></div>
  <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">window.MPL_CONFIG = <?= json_encode($publicConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="<?= htmlspecialchars($base) ?>/assets/vendor/qrcode.min.js"></script>
  <script src="<?= htmlspecialchars($base) ?>/assets/vendor/html5-qrcode.min.js"></script>
  <script src="<?= htmlspecialchars($base) ?>/app.js?v=20260918f"></script>
</body>

</html>
