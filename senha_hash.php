<?php
// Abra no navegador para gerar um hash bcrypt. Apague este arquivo depois de usar.
$senha = (isset($_POST['senha']) ? $_POST['senha'] : '');
$hash = $senha !== '' ? password_hash($senha, PASSWORD_DEFAULT) : '';
?><!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Gerar senha segura</title><style>body{font-family:Arial;max-width:640px;margin:40px auto;padding:20px}input,button{padding:10px;margin:6px 0;width:100%}textarea{width:100%;height:100px}</style><h1>Gerar senha segura</h1><form method="post"><input name="senha" type="password" placeholder="Digite a senha" required><button>Gerar hash bcrypt</button></form><?php if($hash): ?><p>Copie este valor para a coluna <b>senha_hash</b>:</p><textarea readonly><?=htmlspecialchars($hash,ENT_QUOTES,'UTF-8')?></textarea><p>Depois apague o arquivo <b>senha_hash.php</b>.</p><?php endif; ?></html>
