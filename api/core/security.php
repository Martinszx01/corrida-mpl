<?php
function clientIp() {
    return substr((string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 0, 45);
}

function auditLog($db, $userId, $action, $table, $recordId, $description) {
    try {
        $q = $db->prepare('INSERT INTO logs_auditoria (usuario_id, acao, tabela_afetada, registro_id, descricao, ip) VALUES (?, ?, ?, ?, ?, ?)');
        $q->execute(array($userId ?: null, substr($action, 0, 80), $table ?: null, $recordId ?: null, substr((string)$description, 0, 1000), clientIp()));
    } catch (Exception $ignored) {
        // Uma falha de auditoria não deve expor detalhes internos ao usuário.
    }
}

function rateLimit($db, $config, $action, $identifier, $maxAttempts, $windowSeconds, $blockSeconds) {
    $key = hash_hmac('sha256', $action . '|' . strtolower(trim((string)$identifier)), $config['jwt_secret']);
    $q = $db->prepare('SELECT * FROM limites_requisicao WHERE chave_hash = ? LIMIT 1');
    $q->execute(array($key));
    $row = $q->fetch();
    $now = time();
    if ($row && $row['bloqueado_ate'] && strtotime($row['bloqueado_ate']) > $now) {
        resposta(array('error' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.'), 429);
    }
    if (!$row || strtotime($row['janela_inicio']) <= $now - $windowSeconds) {
        $q = $db->prepare('REPLACE INTO limites_requisicao (chave_hash, acao, janela_inicio, tentativas, bloqueado_ate, atualizado_em) VALUES (?, ?, NOW(), 1, NULL, NOW())');
        $q->execute(array($key, $action));
        return $key;
    }
    $attempts = (int)$row['tentativas'] + 1;
    $blockedUntil = $attempts > $maxAttempts ? date('Y-m-d H:i:s', $now + $blockSeconds) : null;
    $q = $db->prepare('UPDATE limites_requisicao SET tentativas = ?, bloqueado_ate = ?, atualizado_em = NOW() WHERE chave_hash = ?');
    $q->execute(array($attempts, $blockedUntil, $key));
    if ($blockedUntil !== null) resposta(array('error' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.'), 429);
    return $key;
}

function rateLimitClear($db, $key) {
    if ($key) $db->prepare('DELETE FROM limites_requisicao WHERE chave_hash = ?')->execute(array($key));
}

function cpfValido($cpf) {
    $cpf = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($digit = 9; $digit < 11; $digit++) {
        $sum = 0;
        for ($i = 0; $i < $digit; $i++) $sum += (int)$cpf[$i] * (($digit + 1) - $i);
        $check = (10 * $sum) % 11; if ($check === 10) $check = 0;
        if ((int)$cpf[$digit] !== $check) return false;
    }
    return true;
}

