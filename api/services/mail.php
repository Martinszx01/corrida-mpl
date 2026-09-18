<?php
function enviarIngressoMpl($db, $config, $registrationId, $collaborator) {
    $q = $db->prepare('SELECT i.numero, p.nome_completo, p.email, c.nome AS categoria_nome, d.distancia_km FROM inscricoes i JOIN participantes p ON p.id=i.participante_id JOIN categorias c ON c.id=i.categoria_id JOIN distancias d ON d.id=i.distancia_id WHERE i.id=? LIMIT 1');
    $q->execute(array($registrationId)); $r = $q->fetch();
    if (!$r) return false;
    $token = garantirTicketMpl($db, $registrationId);
    if (filter_var((string) $config['mail_enabled'], FILTER_VALIDATE_BOOLEAN) !== true) return false;
    $from = trim((string) $config['mail_from']);
    if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    $png = qrPngMpl($token); $boundary = 'MPL_' . bin2hex(random_bytes(10));
    $intro = $collaborator ? 'Sua inscrição como Colaborador MPL está confirmada e isenta de pagamento.' : 'Seu pagamento foi confirmado.';
    $message = "Olá, {$r['nome_completo']}!\n\n$intro\n\nNúmero: {$r['numero']}\nCategoria: {$r['categoria_nome']}\nDistância: {$r['distancia_km']} km\nCódigo: $token\n\nApresente o QR Code anexado na retirada do kit e no check-in.";
    $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($message)) . '--' . $boundary . "\r\nContent-Type: image/png; name=\"ingresso-corrida-mpl.png\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"ingresso-corrida-mpl.png\"\r\n\r\n" . chunk_split(base64_encode($png)) . '--' . $boundary . "--\r\n";
    return mplMailSend($config, $r['email'], 'Inscrição confirmada e ingresso, 4ª Corrida MPL', $body, 'multipart/mixed; boundary="' . $boundary . '"');
}

function enviarEmailCorredor($c, $to, $subject, $message) {
    if (filter_var((string) ((isset($c['mail_enabled']) ? $c['mail_enabled'] : '0')), FILTER_VALIDATE_BOOLEAN) !== true) {
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $from = trim((string) ((isset($c['mail_from']) ? $c['mail_from'] : '')));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $sent = mplMailSend($c, $to, $subject, $message, 'text/plain; charset=UTF-8');
    error_log('[MPL Mail] ' . ($sent ? 'email_sent' : 'email_failed'));
    return $sent;
}

function mensagemFalhaEmailMpl($acao) {
    $erro = function_exists('mplMailLastError') ? mplMailLastError() : '';
    if (strpos($erro, 'código 535') !== false || strpos($erro, 'codigo 535') !== false) {
        return 'O servidor de e-mail recusou o usuário ou a senha SMTP. Solicite à TI a credencial da conta remetente.';
    }
    if (strpos($erro, 'conectar ao servidor SMTP') !== false) {
        return 'Não foi possível conectar ao servidor de e-mail. Verifique host, porta e liberação de rede.';
    }
    if (strpos($erro, 'ativar TLS') !== false) {
        return 'O servidor de e-mail recusou a conexão segura. Verifique a porta e o tipo de criptografia SMTP.';
    }
    return 'Não foi possível ' . $acao . '. Verifique a configuração SMTP do servidor.';
}

function emailInscricao($registration) {
    return "Olá, {$registration['nome_completo']}!\n\n"
        . "Sua inscrição na 4ª Corrida MPL foi registrada.\n\n"
        . "Número: {$registration['numero']}\n"
        . "Categoria: {$registration['categoria_nome']}\n"
        . "Distância: {$registration['distancia_km']} km\n"
        . "Valor: R$ " . number_format((float) $registration['valor'], 2, ',', '.') . "\n\n"
        . "O pagamento ainda está pendente. Acesse o site para escolher PIX ou cartão de crédito.\n\n"
        . "4ª Corrida MPL";
}

function emailPagamentoConfirmado($registration) {
    return "Olá, {$registration['nome_completo']}!\n\n"
        . "Pagamento confirmado para sua inscrição na 4ª Corrida MPL.\n\n"
        . "Número: {$registration['numero']}\n"
        . "Categoria: {$registration['categoria_nome']}\n"
        . "Distância: {$registration['distancia_km']} km\n\n"
        . "Sua inscrição está confirmada.\n\n"
        . "4ª Corrida MPL";
}

