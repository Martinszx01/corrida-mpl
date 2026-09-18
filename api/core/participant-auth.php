<?php
function participantSessionCreate($db, $email) {
    $token = bin2hex(random_bytes(32));
    $q = $db->prepare('INSERT INTO participante_sessoes (token_hash, email, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 12 HOUR))');
    $q->execute(array(hash('sha256', $token), strtolower(trim($email))));
    return $token;
}

function participantSessionEmail($db, $required = true) {
    $token = trim((string)(isset($_SERVER['HTTP_X_MPL_PARTICIPANT_TOKEN']) ? $_SERVER['HTTP_X_MPL_PARTICIPANT_TOKEN'] : ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        if ($required) resposta(array('error' => 'Confirme seu e-mail para acessar a inscrição.'), 401);
        return null;
    }
    $q = $db->prepare('SELECT id, email FROM participante_sessoes WHERE token_hash = ? AND revogado_em IS NULL AND expira_em > NOW() LIMIT 1');
    $q->execute(array(hash('sha256', $token))); $row = $q->fetch();
    if (!$row) {
        if ($required) resposta(array('error' => 'Seu acesso expirou. Confirme o e-mail novamente.'), 401);
        return null;
    }
    $db->prepare('UPDATE participante_sessoes SET ultimo_acesso_em = NOW() WHERE id = ?')->execute(array((int)$row['id']));
    return strtolower(trim((string)$row['email']));
}

