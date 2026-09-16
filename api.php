<?php
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ((isset($config['cors_origin']) ? $config['cors_origin'] : '*')));
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-MPL-Token, X-MPL-Email');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function entrada() {
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function resposta($data, $status = 200) {
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function banco($c) {
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        "mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_database']};charset=utf8mb4",
        $c['db_user'],
        $c['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    return $pdo;
}

function ensurePatrocinadoresSchema($db) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS patrocinadores (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            corrida_id INT UNSIGNED NOT NULL,
            empresa_nome VARCHAR(180) NOT NULL,
            cnpj VARCHAR(18) NULL,
            responsavel_nome VARCHAR(160) NOT NULL,
            responsavel_cargo VARCHAR(120) NULL,
            email VARCHAR(190) NOT NULL,
            telefone VARCHAR(40) NOT NULL,
            site_url VARCHAR(255) NULL,
            modalidade_interesse ENUM('CONTRIBUICAO','PRODUTOS') NOT NULL DEFAULT 'CONTRIBUICAO',
            mensagem TEXT NULL,
            status ENUM('PENDENTE','EM_ANALISE','APROVADO','RECUSADO') NOT NULL DEFAULT 'PENDENTE',
            observacoes_admin TEXT NULL,
            analisado_em DATETIME NULL,
            analisado_por INT UNSIGNED NULL,
            logo_path VARCHAR(255) NULL,
            publicado TINYINT(1) NOT NULL DEFAULT 0,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:01',
            PRIMARY KEY (id),
            KEY idx_patrocinadores_corrida_status (corrida_id, status),
            KEY idx_patrocinadores_publicacao (corrida_id, status, publicado),
            CONSTRAINT fk_patrocinadores_corrida FOREIGN KEY (corrida_id) REFERENCES corridas(id),
            CONSTRAINT fk_patrocinadores_analisado_por FOREIGN KEY (analisado_por) REFERENCES usuarios_admin(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensurePagamentoSchema($db) {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS pagamentos_belluno (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inscricao_id INT UNSIGNED NOT NULL,
            metodo ENUM('PIX','CARD') NOT NULL,
            transaction_id VARCHAR(120) NULL,
            referencia_externa VARCHAR(160) NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            status ENUM('PENDING_PAYMENT','PROCESSING','PAID','FAILED','EXPIRED','CANCELLED','REFUNDED') NOT NULL DEFAULT 'PENDING_PAYMENT',
            status_belluno VARCHAR(80) NULL,
            pix_code TEXT NULL,
            pix_expira_em DATETIME NULL,
            erro_tecnico VARCHAR(1000) NULL,
            confirmado_em DATETIME NULL,
            email_inscricao_em DATETIME NULL,
            email_confirmacao_em DATETIME NULL,
            tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:01',
            PRIMARY KEY (id),
            UNIQUE KEY uq_pagamentos_belluno_inscricao (inscricao_id),
            UNIQUE KEY uq_pagamentos_belluno_transaction (transaction_id),
            KEY idx_pagamentos_belluno_status (status),
            KEY idx_pagamentos_belluno_referencia (referencia_externa),
            CONSTRAINT fk_pagamentos_belluno_inscricao FOREIGN KEY (inscricao_id) REFERENCES inscricoes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [];
    foreach ($db->query('SHOW COLUMNS FROM pagamentos_belluno')->fetchAll() as $column) {
        $columns[(string) $column['Field']] = true;
    }
    foreach (['email_inscricao_em', 'email_confirmacao_em'] as $column) {
        if (empty($columns[$column])) {
            $db->exec("ALTER TABLE pagamentos_belluno ADD COLUMN $column DATETIME NULL AFTER confirmado_em");
        }
    }
}

function ensureConvitesColaboradoresSchema($db) {
    $registrationColumns = array();
    foreach ($db->query('SHOW COLUMNS FROM inscricoes')->fetchAll() as $column) $registrationColumns[(string)$column['Field']] = true;
    if (empty($registrationColumns['tipo'])) $db->exec("ALTER TABLE inscricoes ADD COLUMN tipo VARCHAR(30) NOT NULL DEFAULT 'publico' AFTER status");
    $db->exec(
        "CREATE TABLE IF NOT EXISTS convites_colaboradores (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            corrida_id INT UNSIGNED NOT NULL,
            categoria_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            token_criptografado TEXT NOT NULL,
            expira_em DATETIME NOT NULL,
            cancelado_em DATETIME NULL,
            utilizado_em DATETIME NULL,
            participante_id INT UNSIGNED NULL,
            inscricao_id INT UNSIGNED NULL,
            email_destino VARCHAR(180) NULL,
            criado_por INT UNSIGNED NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_convites_token_hash (token_hash),
            UNIQUE KEY uq_convites_inscricao (inscricao_id),
            KEY idx_convites_corrida (corrida_id),
            KEY idx_convites_categoria (categoria_id),
            KEY idx_convites_status (utilizado_em, cancelado_em, expira_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

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

function garantirTicketMpl($db, $registrationId) {
    $q = $db->prepare("SELECT token FROM tickets WHERE inscricao_id = ? AND status = 'ativo' LIMIT 1");
    $q->execute(array($registrationId));
    $row = $q->fetch();
    if ($row) return $row['token'];
    $token = 'MPL-RACE-TICKET-' . strtoupper(bin2hex(random_bytes(8)));
    $db->prepare('INSERT INTO tickets (inscricao_id, token, qr_code_data) VALUES (?, ?, ?)')->execute(array($registrationId, $token, $token));
    $ticketId = $db->lastInsertId();
    $db->prepare('INSERT IGNORE INTO retiradas_kit (inscricao_id, ticket_id) VALUES (?, ?)')->execute(array($registrationId, $ticketId));
    return $token;
}

function qrPngMpl($token) {
    require_once __DIR__ . '/vendor/phpqrcode/qrlib.php';
    $rows = QRcode::text($token, false, QR_ECLEVEL_M);
    $size = strlen($rows[0]); $margin = 4; $scale = 7; $width = ($size + $margin * 2) * $scale; $raw = '';
    for ($py = 0; $py < $width; $py++) {
        $raw .= "\x00"; $my = (int) floor($py / $scale) - $margin;
        for ($px = 0; $px < $width; $px++) {
            $mx = (int) floor($px / $scale) - $margin;
            $dark = $mx >= 0 && $my >= 0 && $mx < $size && $my < $size && $rows[$my][$mx] === '1';
            $raw .= $dark ? "\x10\x28\x44" : "\xff\xff\xff";
        }
    }
    $chunk = function ($type, $data) { return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data)); };
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNC5', $width, $width, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw, 9)) . $chunk('IEND', '');
}

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
    $headers = array('From: 4ª Corrida MPL <' . $from . '>', 'Reply-To: ' . $from, 'MIME-Version: 1.0', 'Content-Type: multipart/mixed; boundary="' . $boundary . '"');
    $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($message)) . '--' . $boundary . "\r\nContent-Type: image/png; name=\"ingresso-corrida-mpl.png\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"ingresso-corrida-mpl.png\"\r\n\r\n" . chunk_split(base64_encode($png)) . '--' . $boundary . "--\r\n";
    return @mail($r['email'], '=?UTF-8?B?' . base64_encode('Inscrição confirmada e ingresso, 4ª Corrida MPL') . '?=', $body, implode("\r\n", $headers));
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
    $headers = [
        'From: 4ª Corrida MPL <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0'
    ];
    $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, implode("\r\n", $headers));
    bellunoLog($sent ? 'email_sent' : 'email_failed', ['to' => $to, 'subject' => $subject]);
    return $sent;
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

function bellunoLog($event, $context = []) {
    $safe = [];
    foreach ($context as $key => $value) {
        if (in_array(strtolower((string) $key), ['token', 'authorization', 'card_hash', 'card_number', 'card_cvv', 'card_expiration_date'], true)) {
            continue;
        }
        $safe[$key] = is_scalar($value) || $value === null ? $value : '[omitted]';
    }
    error_log('[MPL Belluno] ' . json_encode(['event' => $event, 'context' => $safe], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function bellunoBaseUrl($c) {
    $custom = trim((string) ((isset($c['belluno_base_url']) ? $c['belluno_base_url'] : '')));
    if ($custom !== '') {
        return rtrim($custom, '/');
    }

    return strtolower((string) ((isset($c['belluno_env']) ? $c['belluno_env'] : 'sandbox'))) === 'production'
        ? 'https://api.belluno.digital/v2'
        : 'https://api-sandbox.belluno.digital/v2';
}

function bellunoRequest($c, $method, $path, $payload = null) {
    $token = trim((string) ((isset($c['belluno_token']) ? $c['belluno_token'] : '')));
    if ($token === '') {
        throw new RuntimeException('Pagamento ainda não configurado no servidor.');
    }

    $ch = curl_init(bellunoBaseUrl($c) . '/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('Não foi possível preparar a comunicação com a Belluno.');
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    bellunoLog('request', ['method' => strtoupper($method), 'path' => $path, 'http_status' => $httpStatus]);
    if ($raw === false || $curlError !== '') {
        bellunoLog('connection_error', ['method' => strtoupper($method), 'path' => $path]);
        throw new RuntimeException('A Belluno não respondeu. Tente novamente.');
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        throw new RuntimeException('A Belluno retornou uma resposta inválida.');
    }

    return [
        'http_status' => $httpStatus,
        'body' => $body
    ];
}

function bellunoTransaction($body) {
    $transaction = (isset($body['transaction']) ? $body['transaction'] : $body);
    return is_array($transaction) ? $transaction : [];
}

function bellunoTransactionId($body) {
    $transaction = bellunoTransaction($body);
    $id = isset($transaction['transaction_id']) ? $transaction['transaction_id']
        : (isset($transaction['id']) ? $transaction['id']
            : (isset($body['transaction_id']) ? $body['transaction_id']
                : (isset($body['id']) ? $body['id'] : null)));
    return $id === null || $id === '' ? null : (string) $id;
}

function bellunoRawStatus($body) {
    $transaction = bellunoTransaction($body);
    return trim((string) (isset($transaction['status']) ? $transaction['status'] : (isset($body['status']) ? $body['status'] : '')));
}

function bellunoPixNormalize($value) {
    $text = trim($value);
    if ($text === '') {
        return $text;
    }
    // O "copia e cola" (BR Code) sempre inicia com 000201.
    if (stripos($text, '000201') === 0) {
        return $text;
    }
    // Alguns retornos vêm em Base64; decodifica quando o conteúdo é um BR Code.
    $decoded = base64_decode($text, true);
    if ($decoded !== false && stripos(ltrim($decoded), '000201') === 0) {
        return trim($decoded);
    }
    return $text;
}

function bellunoPixCode($body) {
    $transaction = bellunoTransaction($body);

    // Formato oficial: transaction.pix.base64_text (código) e base64_image (QR).
    $pix = (isset($transaction['pix']) ? $transaction['pix'] : null);
    if (is_array($pix)) {
        foreach (['base64_text', 'copy_paste', 'emv', 'qr_code', 'qrcode', 'pix_code', 'code'] as $key) {
            if (!empty($pix[$key])) {
                return bellunoPixNormalize((string) $pix[$key]);
            }
        }
    }

    // Compatibilidade com formatos alternativos.
    if (!empty($transaction['pix_code'])) {
        return bellunoPixNormalize((string) $transaction['pix_code']);
    }
    $payments = (isset($transaction['payments']) ? $transaction['payments'] : []);
    if (is_array($payments)) {
        foreach ($payments as $payment) {
            if (is_array($payment) && !empty($payment['pix_code'])) {
                return bellunoPixNormalize((string) $payment['pix_code']);
            }
        }
    }
    return null;
}

function bellunoPixImage($body) {
    $transaction = bellunoTransaction($body);
    $pix = (isset($transaction['pix']) ? $transaction['pix'] : null);
    if (is_array($pix)) {
        foreach (['base64_image', 'qr_code_image', 'image'] as $key) {
            if (!empty($pix[$key])) {
                $img = trim((string) $pix[$key]);
                return str_starts_with($img, 'data:') ? $img : ('data:image/png;base64,' . $img);
            }
        }
    }
    return null;
}

function bellunoLocalStatus($raw) {
    $value = strtolower(trim($raw));
    if (in_array($value, ['paid', 'approved', 'captured', 'succeeded', 'success'], true)) {
        return 'PAID';
    }
    if (in_array($value, ['refused', 'refuse', 'declined', 'denied', 'failed', 'failure'], true)) {
        return 'FAILED';
    }
    if (in_array($value, ['expired', 'closure by deadline'], true)) {
        return 'EXPIRED';
    }
    if (in_array($value, ['cancelled', 'canceled', 'closure by request', 'closure requested'], true)) {
        return 'CANCELLED';
    }
    return 'PROCESSING';
}

function paymentView($row) {
    return [
        'id' => (int) $row['id'],
        'method' => $row['metodo'],
        'transaction_id' => $row['transaction_id'],
        'value_cents' => (int) round(((float) $row['valor']) * 100),
        'status' => $row['status'],
        'belluno_status' => $row['status_belluno'],
        'pix_code' => $row['pix_code'],
        'confirmed_at' => $row['confirmado_em']
    ];
}

function loadPayment($db, $registrationId) {
    $q = $db->prepare('SELECT * FROM pagamentos_belluno WHERE inscricao_id = ? LIMIT 1');
    $q->execute([$registrationId]);
    return $q->fetch() ?: null;
}

function applyBellunoStatus($db, $config, $paymentId, $body) {
    $q = $db->prepare('SELECT * FROM pagamentos_belluno WHERE id = ? FOR UPDATE');
    $q->execute([$paymentId]);
    $payment = $q->fetch();
    if (!$payment) {
        throw new RuntimeException('Pagamento não encontrado.');
    }

    $rawStatus = bellunoRawStatus($body);
    $localStatus = bellunoLocalStatus($rawStatus);
    $transaction = bellunoTransaction($body);
    $remoteValue = (isset($transaction['value']) ? $transaction['value'] : null);
    if ($remoteValue !== null && abs((float) $remoteValue - (float) $payment['valor']) > 0.01) {
        throw new RuntimeException('O valor confirmado pela Belluno não corresponde à inscrição.');
    }

    $pixCode = bellunoPixCode($body) ?: $payment['pix_code'];
    $confirmedAt = $localStatus === 'PAID' ? ($payment['confirmado_em'] ?: date('Y-m-d H:i:s')) : $payment['confirmado_em'];
    $q = $db->prepare(
        'UPDATE pagamentos_belluno
         SET status = ?, status_belluno = ?, pix_code = ?, confirmado_em = ?, erro_tecnico = NULL, atualizado_em = NOW()
         WHERE id = ?'
    );
    $q->execute([$localStatus, $rawStatus ?: null, $pixCode, $confirmedAt, $paymentId]);

    if ($localStatus === 'PAID') {
        $db->prepare("UPDATE inscricoes SET status = 'paga' WHERE id = ? AND status <> 'cancelada'")->execute([(int) $payment['inscricao_id']]);
        if (empty($payment['email_confirmacao_em'])) {
            $emailQuery = $db->prepare(
                'SELECT i.numero, i.valor, p.nome_completo, p.email, c.nome AS categoria_nome, d.distancia_km
                 FROM inscricoes i
                 JOIN participantes p ON p.id = i.participante_id
                 JOIN categorias c ON c.id = i.categoria_id
                 JOIN distancias d ON d.id = i.distancia_id
                 WHERE i.id = ? LIMIT 1'
            );
            $emailQuery->execute([(int) $payment['inscricao_id']]);
            $registration = $emailQuery->fetch();
            if ($registration && enviarEmailCorredor($config, $registration['email'], 'Pagamento confirmado, 4ª Corrida MPL', emailPagamentoConfirmado($registration))) {
                $db->prepare('UPDATE pagamentos_belluno SET email_confirmacao_em = NOW() WHERE id = ?')->execute([$paymentId]);
            }
        }
    } elseif ($localStatus !== 'PROCESSING') {
        $db->prepare("UPDATE inscricoes SET status = 'pendente_pagamento' WHERE id = ? AND status NOT IN ('paga', 'confirmada', 'cancelada')")->execute([(int) $payment['inscricao_id']]);
    }

    $q = $db->prepare('SELECT * FROM pagamentos_belluno WHERE id = ? LIMIT 1');
    $q->execute([$paymentId]);
    return $q->fetch() ?: $payment;
}

function paymentOwner($db, $registrationId) {
    $email = strtolower(trim((string) ((isset($_SERVER['HTTP_X_MPL_EMAIL']) ? $_SERVER['HTTP_X_MPL_EMAIL'] : ''))));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        resposta(['error' => 'Informe o e-mail da inscrição.'], 401);
    }

    $q = $db->prepare(
        'SELECT i.*, p.nome_completo, p.email, p.cpf, p.telefone, p.data_nascimento,
                p.tamanho_camiseta, c.nome AS categoria_nome, d.distancia_km
         FROM inscricoes i
         JOIN participantes p ON p.id = i.participante_id
         JOIN categorias c ON c.id = i.categoria_id
         JOIN distancias d ON d.id = i.distancia_id
         WHERE i.id = ? AND LOWER(TRIM(p.email)) = ?
         LIMIT 1'
    );
    $q->execute([$registrationId, $email]);
    $row = $q->fetch();
    if (!$row) {
        resposta(['error' => 'Inscrição não encontrada para este e-mail.'], 404);
    }
    return $row;
}

function b64($v) {
    return rtrim(
        strtr(base64_encode($v), '+/', '-_'),
        '='
    );
}

function tokenCriar(
    $id,
    $perfil,
    $email,
    $segredo
) {
    $h = b64(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT'
    ]));

    $p = b64(json_encode([
        'sub' => $id,
        'role' => $perfil,
        'email' => $email,
        'iat' => time(),
        'exp' => time() + 86400
    ]));

    $assinatura = b64(
        hash_hmac(
            'sha256',
            "$h.$p",
            $segredo,
            true
        )
    );

    return "$h.$p.$assinatura";
}

function usuarioAtual($c) {
    $h = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION']
        : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : '');

    if (
        $h === ''
        && !empty($_SERVER['HTTP_X_MPL_TOKEN'])
    ) {
        $h = 'Bearer ' . $_SERVER['HTTP_X_MPL_TOKEN'];
    }

    if (!preg_match('/Bearer\s+(.+)/i', $h, $m)) {
        return null;
    }

    $part = explode('.', $m[1]);

    if (count($part) !== 3) {
        return null;
    }

    $assinaturaEsperada = b64(
        hash_hmac(
            'sha256',
            "{$part[0]}.{$part[1]}",
            $c['jwt_secret'],
            true
        )
    );

    if (!hash_equals($assinaturaEsperada, $part[2])) {
        return null;
    }

    $raw = strtr($part[1], '-_', '+/');
    $raw .= str_repeat(
        '=',
        (4 - strlen($raw) % 4) % 4
    );

    $p = json_decode(
        base64_decode($raw),
        true
    );

    if (
        !$p
        || ((isset($p['exp']) ? $p['exp'] : 0)) <= time()
    ) {
        return null;
    }

    return $p;
}

function exigir(
    $c,
    $perfis = []
) {
    $u = usuarioAtual($c);

    if (!$u) {
        resposta([
            'error' => 'Faça login para continuar.'
        ], 401);
    }

    if (
        $perfis
        && !in_array(
            strtolower((isset($u['role']) ? $u['role'] : '')),
            array_map('strtolower', $perfis),
            true
        )
    ) {
        resposta([
            'error' => 'Você não tem permissão.'
        ], 403);
    }

    return $u;
}

function corrida(
    $db,
    $c
) {
    $q = $db->prepare(
        'SELECT *
         FROM corridas
         WHERE slug = ?
         LIMIT 1'
    );

    $q->execute([
        $c['event_slug']
    ]);

    $e = $q->fetch();

    if (!$e) {
        throw new RuntimeException(
            'Corrida não cadastrada.'
        );
    }

    $q = $db->prepare(
        "SELECT
            c.id,
            c.nome,
            c.nome AS name,
            c.genero,
            c.idade_minima,
            c.idade_maxima,
            c.idade_minima AS min_age,
            c.idade_maxima AS max_age,
            d.distancia_km,
            d.distancia_km AS distance_km,
            COALESCE(
                (
                    SELECT CAST(l.preco * 100 AS UNSIGNED)
                    FROM lotes l
                    WHERE l.categoria_id = c.id
                      AND l.corrida_id = c.corrida_id
                      AND l.ativo = 1
                    ORDER BY l.id
                    LIMIT 1
                ),
                0
            ) AS price_cents
         FROM categorias c
         JOIN distancias d
           ON d.id = c.distancia_id
         WHERE c.corrida_id = ?
           AND c.ativa = 1
         ORDER BY c.ordem, c.id"
    );

    $q->execute([
        $e['id']
    ]);

    $e['categories'] = $q->fetchAll();

    $e['name'] = $e['nome'];
    $e['event_date'] = $e['data_corrida'];
    $e['start_time'] = $e['horario_largada'];
    $e['registration_open'] = (bool) $e['inscricoes_abertas'];

    return $e;
}

function confirmar(
    $db,
    $corridaId,
    $nome
) {
    $q = $db->prepare(
        "SELECT
            c.id,
            c.nome AS name,
            d.distancia_km,
            d.distancia_km AS distance_km,
            c.idade_minima AS min_age,
            c.idade_maxima AS max_age,
            c.ativa AS active,
            COALESCE(
                (
                    SELECT SUM(l.quantidade)
                    FROM lotes l
                    WHERE l.categoria_id = c.id
                      AND l.corrida_id = c.corrida_id
                ),
                0
            ) AS capacity
         FROM categorias c
         JOIN distancias d
           ON d.id = c.distancia_id
         WHERE c.corrida_id = ?
         ORDER BY c.ordem, c.id"
    );

    $q->execute([
        $corridaId
    ]);

    $out = [];

    foreach ($q->fetchAll() as $r) {
        $r['active'] = (bool) $r['active'];
        $r['capacity'] = (int) $r['capacity'];
        $out[] = $r;
    }

    return $out;
}

$acao = (isset($_GET['action']) ? $_GET['action'] : '');
$dados = entrada();

try {
    $db = banco($config);
    ensurePagamentoSchema($db);
    ensurePatrocinadoresSchema($db);
    ensureConvitesColaboradoresSchema($db);

    if ($acao === 'health') {
        $db->query('SELECT 1');

        resposta([
            'status' => 'healthy',
            'database' => $config['db_database']
        ]);
    }

    if ($acao === 'diagnostico') {
        $tabelas = [
            'corridas',
            'distancias',
            'categorias',
            'lotes',
            'participantes',
            'inscricoes',
            'pagamentos',
            'tickets',
            'retiradas_kit',
            'checkins',
            'usuarios_admin',
            'logs_auditoria',
            'pagamentos_belluno',
            'patrocinadores'
        ];

        $ok = [];

        foreach ($tabelas as $nome) {
            $db->query(
                "SELECT 1 FROM `$nome` LIMIT 1"
            );

            $ok[$nome] = true;
        }

        resposta([
            'status' => 'ok',
            'banco' => $config['db_database'],
            'tabelas' => $ok
        ]);
    }

    if ($acao === 'public-event') {
        resposta(
            corrida($db, $config)
        );
    }

    if ($acao === 'invitation-info') {
        $token = trim((string) (isset($_GET['token']) ? $_GET['token'] : (isset($dados['token']) ? $dados['token'] : '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) resposta(array('error' => 'Convite inválido.'), 404);
        $q = $db->prepare("SELECT cv.id, cv.expira_em, cv.cancelado_em, cv.utilizado_em, co.id AS event_id, co.nome AS event_name, co.data_corrida AS event_date, ca.id AS category_id, ca.nome AS category_name, d.distancia_km FROM convites_colaboradores cv JOIN corridas co ON co.id=cv.corrida_id JOIN categorias ca ON ca.id=cv.categoria_id JOIN distancias d ON d.id=ca.distancia_id WHERE cv.token_hash=? LIMIT 1");
        $q->execute(array(hash('sha256', $token))); $invite = $q->fetch();
        if (!$invite || $invite['cancelado_em'] || $invite['utilizado_em'] || strtotime($invite['expira_em']) <= time()) resposta(array('error' => 'Este convite não está mais disponível.'), 410);
        resposta(array('event_id' => (int) $invite['event_id'], 'event_name' => $invite['event_name'], 'event_date' => $invite['event_date'], 'category_id' => (int) $invite['category_id'], 'category_name' => $invite['category_name'], 'distance_km' => (float) $invite['distancia_km'], 'type' => 'COLABORADOR_MPL', 'price_cents' => 0));
    }

    if ($acao === 'public-sponsors') {
        $e = corrida($db, $config);
        $q = $db->prepare("SELECT empresa_nome AS name, logo_path AS logo_url, site_url AS website_url FROM patrocinadores WHERE corrida_id = ? AND status = 'APROVADO' AND publicado = 1 AND logo_path IS NOT NULL ORDER BY id DESC");
        $q->execute([(int) $e['id']]);
        resposta($q->fetchAll());
    }

    if ($acao === 'sponsor-interest') {
        $e = corrida($db, $config);
        $company = trim((string) ((isset($dados['company']) ? $dados['company'] : '')));
        $contact = trim((string) ((isset($dados['contact_name']) ? $dados['contact_name'] : '')));
        $email = strtolower(trim((string) ((isset($dados['email']) ? $dados['email'] : ''))));
        $phone = trim((string) ((isset($dados['phone']) ? $dados['phone'] : '')));
        $cnpj = preg_replace('/\D/', '', (string) ((isset($dados['cnpj']) ? $dados['cnpj'] : '')));
        $website = trim((string) ((isset($dados['website']) ? $dados['website'] : '')));
        $support = strtoupper(trim((string) ((isset($dados['support_type']) ? $dados['support_type'] : 'CONTRIBUICAO'))));
        $message = trim((string) ((isset($dados['message']) ? $dados['message'] : '')));
        if (mb_strlen($company) < 2 || mb_strlen($company) > 180 || mb_strlen($contact) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($phone) < 8) {
            resposta(['error' => 'Informe empresa, responsável, e-mail e telefone válidos.'], 422);
        }
        if ($cnpj !== '' && strlen($cnpj) !== 14)
            resposta(['error' => 'CNPJ inválido.'], 422);
        if ($website !== '' && (!filter_var($website, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $website)))
            resposta(['error' => 'Site inválido.'], 422);
        if (!in_array($support, ['CONTRIBUICAO', 'PRODUTOS'], true))
            resposta(['error' => 'Modalidade de apoio inválida.'], 422);
        if (mb_strlen($message) > 2000)
            resposta(['error' => 'A mensagem deve ter no máximo 2.000 caracteres.'], 422);
        $q = $db->prepare("INSERT INTO patrocinadores (corrida_id, empresa_nome, cnpj, responsavel_nome, email, telefone, site_url, modalidade_interesse, mensagem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $q->execute([(int) $e['id'], $company, $cnpj ?: null, $contact, $email, $phone, $website ?: null, $support, $message ?: null]);
        resposta(['ok' => true, 'status' => 'PENDENTE'], 201);
    }

    if ($acao === 'auth-login') {
        $email = strtolower(
            trim(
                (string) ((isset($dados['email']) ? $dados['email'] : ''))
            )
        );

        $password = (string) (
            (isset($dados['password']) ? $dados['password'] : '')
        );

        $q = $db->prepare(
            'SELECT
                id,
                nome,
                email,
                senha_hash,
                perfil,
                ativo
             FROM usuarios_admin
             WHERE LOWER(TRIM(email)) = ?
             LIMIT 1'
        );

        $q->execute([
            $email
        ]);

        $u = $q->fetch();

        if (
            !$u
            || !(int) $u['ativo']
            || !password_verify(
                $password,
                $u['senha_hash']
            )
        ) {
            resposta([
                'error' => 'E-mail ou senha inválidos.'
            ], 401);
        }

        $db->prepare(
            'UPDATE usuarios_admin
             SET ultimo_login_em = NOW()
             WHERE id = ?'
        )->execute([
                    $u['id']
                ]);

        $perfil = strtoupper(
            (string) $u['perfil']
        );

        resposta([
            'token' => tokenCriar(
                (int) $u['id'],
                $perfil,
                $u['email'],
                $config['jwt_secret']
            ),
            'user' => [
                'id' => (int) $u['id'],
                'name' => $u['nome'],
                'email' => $u['email'],
                'role' => $perfil
            ]
        ]);
    }

    if ($acao === 'create-admin') {
        $quantidade = (int) $db
            ->query(
                'SELECT COUNT(*) FROM usuarios_admin'
            )
            ->fetchColumn();

        if ($quantidade > 0) {
            resposta([
                'error' => 'Já existe um administrador.'
            ], 400);
        }

        $q = $db->prepare(
            "INSERT INTO usuarios_admin
            (
                nome,
                email,
                senha_hash,
                perfil,
                ativo
            )
            VALUES
            (
                ?,
                ?,
                ?,
                'super_admin',
                1
            )"
        );

        $q->execute([
            (isset($dados['name']) ? $dados['name'] : ''),
            (isset($dados['email']) ? $dados['email'] : ''),
            password_hash(
                (string) ((isset($dados['password']) ? $dados['password'] : '')),
                PASSWORD_DEFAULT
            )
        ]);

        resposta([
            'ok' => true,
            'id' => $db->lastInsertId()
        ]);
    }

    if ($acao === 'membership') {
        $u = exigir($config);

        $e = corrida(
            $db,
            $config
        );

        resposta([
            'role' => $u['role'],
            'event_id' => $e['id'],
            'event_name' => $e['name']
        ]);
    }

    if ($acao === 'sponsor-logo-upload') {
        exigir($config, ['super_admin', 'admin']);
        $id = (int) ((isset($_POST['sponsor_id']) ? $_POST['sponsor_id'] : 0));
        $file = (isset($_FILES['logo']) ? $_FILES['logo'] : null);
        if ($id <= 0 || !is_array($file) || (isset($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) $file['size'] > 5 * 1024 * 1024)
            resposta(['error' => 'Logo inválida.'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($ext[$mime]) || @getimagesize($file['tmp_name']) === false)
            resposta(['error' => 'Envie JPG, PNG ou WebP.'], 422);
        $dir = __DIR__ . '/assets/images/sponsors';
        if (!is_dir($dir))
            mkdir($dir, 0750, true);
        $name = bin2hex(random_bytes(16)) . '.' . $ext[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name))
            resposta(['error' => 'Não foi possível salvar a logo.'], 500);
        $q = $db->prepare('UPDATE patrocinadores SET logo_path = ?, atualizado_em = NOW() WHERE id = ?');
        $q->execute(['assets/images/sponsors/' . $name, $id]);
        resposta(['ok' => true]);
    }

    if ($acao === 'admin-api') {
        $u = exigir(
            $config,
            [
                'super_admin',
                'admin',
                'financeiro',
                'operador',
                'consulta'
            ]
        );

        $e = corrida(
            $db,
            $config
        );

        $what = (isset($dados['action']) ? $dados['action'] : 'overview');

        if (in_array($what, array('invitations', 'invitation-options', 'create-invitation', 'cancel-invitation', 'send-invitation', 'resend-invitation-confirmation'), true)) {
            if (!in_array(strtolower((string) $u['role']), array('super_admin', 'admin'), true)) resposta(array('error' => 'Sem permissão para gerenciar convites.'), 403);
        }

        if ($what === 'invitation-options') {
            $rows = $db->query("SELECT co.id AS event_id, co.nome AS event_name, ca.id AS category_id, ca.nome AS category_name, d.distancia_km FROM corridas co JOIN categorias ca ON ca.corrida_id=co.id AND ca.ativa=1 JOIN distancias d ON d.id=ca.distancia_id WHERE co.status='publicada' ORDER BY co.data_corrida DESC, ca.ordem, ca.id")->fetchAll();
            resposta($rows);
        }

        if ($what === 'invitations') {
            $q = $db->query("SELECT cv.*, co.nome AS event_name, ca.nome AS category_name, d.distancia_km, p.nome_completo AS collaborator_name, p.email AS collaborator_email, CASE WHEN cv.cancelado_em IS NOT NULL THEN 'CANCELADO' WHEN cv.utilizado_em IS NOT NULL THEN 'UTILIZADO' WHEN cv.expira_em<=NOW() THEN 'EXPIRADO' ELSE 'DISPONIVEL' END AS status FROM convites_colaboradores cv JOIN corridas co ON co.id=cv.corrida_id JOIN categorias ca ON ca.id=cv.categoria_id JOIN distancias d ON d.id=ca.distancia_id LEFT JOIN participantes p ON p.id=cv.participante_id ORDER BY cv.criado_em DESC");
            $rows = $q->fetchAll();
            foreach ($rows as &$row) {
                $token = conviteDescriptografar($row['token_criptografado'], $config['jwt_secret']);
                $row['link'] = $token === '' ? '' : conviteLink($config, $token);
                unset($row['token_hash'], $row['token_criptografado']);
            }
            unset($row); resposta($rows);
        }

        if ($what === 'create-invitation') {
            $eventId = (int) (isset($dados['event_id']) ? $dados['event_id'] : 0); $categoryId = (int) (isset($dados['category_id']) ? $dados['category_id'] : 0);
            $q = $db->prepare("SELECT ca.id FROM categorias ca JOIN corridas co ON co.id=ca.corrida_id JOIN distancias d ON d.id=ca.distancia_id WHERE ca.id=? AND ca.corrida_id=? AND ca.ativa=1 AND co.status='publicada' LIMIT 1");
            $q->execute(array($categoryId, $eventId)); if (!$q->fetch()) resposta(array('error' => 'Corrida ou categoria inválida.'), 422);
            $token = bin2hex(random_bytes(32)); $hours = max(1, min(8760, (int) $config['invitation_expires_hours']));
            $q = $db->prepare('INSERT INTO convites_colaboradores (corrida_id,categoria_id,token_hash,token_criptografado,expira_em,criado_por) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL ? HOUR),?)');
            $q->execute(array($eventId, $categoryId, hash('sha256', $token), conviteCriptografar($token, $config['jwt_secret']), $hours, (int) $u['sub']));
            resposta(array('ok' => true, 'id' => (int) $db->lastInsertId(), 'link' => conviteLink($config, $token)), 201);
        }

        if ($what === 'cancel-invitation') {
            $id = (int) (isset($dados['id']) ? $dados['id'] : 0);
            $q = $db->prepare('UPDATE convites_colaboradores SET cancelado_em=NOW(), atualizado_em=NOW() WHERE id=? AND utilizado_em IS NULL AND cancelado_em IS NULL AND expira_em>NOW()'); $q->execute(array($id));
            if (!$q->rowCount()) resposta(array('error' => 'Convite indisponível ou já utilizado.'), 409); resposta(array('ok' => true));
        }

        if ($what === 'send-invitation') {
            $id = (int) (isset($dados['id']) ? $dados['id'] : 0); $email = strtolower(trim((string) (isset($dados['email']) ? $dados['email'] : '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) resposta(array('error' => 'Informe um e-mail válido.'), 422);
            $q = $db->prepare("SELECT cv.token_criptografado, co.nome AS event_name, ca.nome AS category_name, d.distancia_km FROM convites_colaboradores cv JOIN corridas co ON co.id=cv.corrida_id JOIN categorias ca ON ca.id=cv.categoria_id JOIN distancias d ON d.id=ca.distancia_id WHERE cv.id=? AND cv.utilizado_em IS NULL AND cv.cancelado_em IS NULL AND cv.expira_em>NOW() LIMIT 1");
            $q->execute(array($id)); $invite = $q->fetch(); if (!$invite) resposta(array('error' => 'Convite indisponível.'), 409);
            $token = conviteDescriptografar($invite['token_criptografado'], $config['jwt_secret']); if ($token === '') resposta(array('error' => 'Não foi possível recuperar o link.'), 500);
            $message = "Você recebeu um convite para se inscrever como Colaborador MPL.\n\nCorrida: {$invite['event_name']}\nCategoria: {$invite['category_name']} ({$invite['distancia_km']} km)\nValor: R$ 0,00 (isento)\n\nUse o link único abaixo:\n" . conviteLink($config, $token) . "\n\nO convite é pessoal e de uso único.";
            $sent = enviarEmailCorredor($config, $email, 'Convite de Colaborador MPL', $message);
            $db->prepare('UPDATE convites_colaboradores SET email_destino=?, atualizado_em=NOW() WHERE id=?')->execute(array($email, $id));
            resposta(array('ok' => true, 'email_sent' => $sent));
        }

        if ($what === 'resend-invitation-confirmation') {
            $id = (int) (isset($dados['id']) ? $dados['id'] : 0); $q = $db->prepare('SELECT inscricao_id FROM convites_colaboradores WHERE id=? AND utilizado_em IS NOT NULL AND inscricao_id IS NOT NULL LIMIT 1'); $q->execute(array($id)); $registrationId = $q->fetchColumn();
            if (!$registrationId) resposta(array('error' => 'Este convite ainda não foi utilizado.'), 409);
            $sent = enviarIngressoMpl($db, $config, (int) $registrationId, true); resposta(array('ok' => true, 'email_sent' => $sent));
        }

        if ($what === 'sponsors') {
            if (!in_array(strtolower((string) $u['role']), ['super_admin', 'admin'], true))
                resposta(['error' => 'Sem permissão para acessar patrocinadores.'], 403);
            $q = $db->prepare("SELECT p.id, p.empresa_nome AS company, p.cnpj, p.responsavel_nome AS contact_name, p.email, p.telefone AS phone, p.site_url AS website, p.modalidade_interesse AS support_type, p.mensagem AS message, p.status, p.observacoes_admin AS admin_notes, p.analisado_em AS reviewed_at, p.logo_path AS logo_url, p.publicado AS published, p.criado_em AS created_at, ua.nome AS reviewed_by_name FROM patrocinadores p LEFT JOIN usuarios_admin ua ON ua.id = p.analisado_por WHERE p.corrida_id = ? ORDER BY FIELD(p.status, 'PENDENTE','EM_ANALISE','APROVADO','RECUSADO'), p.criado_em DESC");
            $q->execute([(int) $e['id']]);
            $rows = $q->fetchAll();
            foreach ($rows as &$row)
                $row['published'] = (bool) $row['published'];
            unset($row);
            resposta($rows);
        }
        if ($what === 'update-sponsor') {
            if (!in_array(strtolower((string) $u['role']), ['super_admin', 'admin'], true))
                resposta(['error' => 'Sem permissão para alterar patrocinadores.'], 403);
            $id = (int) ((isset($dados['id']) ? $dados['id'] : 0));
            $x = is_array((isset($dados['data']) ? $dados['data'] : null)) ? $dados['data'] : [];
            $status = strtoupper(trim((string) ((isset($x['status']) ? $x['status'] : 'PENDENTE'))));
            $notes = trim((string) ((isset($x['admin_notes']) ? $x['admin_notes'] : '')));
            $published = $status === 'APROVADO' && !empty($x['published']) ? 1 : 0;
            if ($id <= 0 || !in_array($status, ['PENDENTE', 'EM_ANALISE', 'APROVADO', 'RECUSADO'], true))
                resposta(['error' => 'Status inválido.'], 422);
            $q = $db->prepare('UPDATE patrocinadores SET status = ?, observacoes_admin = ?, publicado = ?, analisado_em = NOW(), analisado_por = ?, atualizado_em = NOW() WHERE id = ? AND corrida_id = ?');
            $q->execute([$status, $notes ?: null, $published, (int) $u['sub'], $id, (int) $e['id']]);
            if ($q->rowCount() === 0)
                resposta(['error' => 'Solicitação não encontrada.'], 404);
            resposta(['ok' => true, 'status' => $status, 'published' => (bool) $published]);
        }

        if ($what === 'overview') {
            $q = $db->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(
                        status IN ('paga','confirmada')
                    ) AS confirmed,
                    SUM(
                        status = 'pendente_pagamento'
                    ) AS pending
                 FROM inscricoes
                 WHERE corrida_id = ?"
            );

            $q->execute([
                $e['id']
            ]);

            $x = $q->fetch();

            $q = $db->prepare(
                "SELECT COUNT(*)
                 FROM retiradas_kit k
                 JOIN inscricoes i
                   ON i.id = k.inscricao_id
                 WHERE i.corrida_id = ?
                   AND k.status = 'retirado'"
            );

            $q->execute([
                $e['id']
            ]);

            $kits = (int) $q->fetchColumn();

            $q = $db->prepare(
                'SELECT COUNT(*)
                 FROM checkins
                 WHERE corrida_id = ?'
            );

            $q->execute([
                $e['id']
            ]);

            $checkins = (int) $q->fetchColumn();

            $q = $db->prepare(
                "SELECT
                    c.nome AS name,
                    COUNT(i.id) AS count
                 FROM categorias c
                 LEFT JOIN inscricoes i
                   ON i.categoria_id = c.id
                  AND i.corrida_id = c.corrida_id
                 WHERE c.corrida_id = ?
                 GROUP BY c.id, c.nome
                 ORDER BY c.ordem, c.id"
            );

            $q->execute([
                $e['id']
            ]);

            resposta([
                'total' => (int) ((isset($x['total']) ? $x['total'] : 0)),
                'confirmed' => (int) ((isset($x['confirmed']) ? $x['confirmed'] : 0)),
                'pending' => (int) ((isset($x['pending']) ? $x['pending'] : 0)),
                    'employees' => (int) $db->query("SELECT COUNT(*) FROM inscricoes WHERE corrida_id=" . (int)$e['id'] . " AND tipo='colaborador_mpl'")->fetchColumn(),
                    'public' => (int) $db->query("SELECT COUNT(*) FROM inscricoes WHERE corrida_id=" . (int)$e['id'] . " AND tipo<>'colaborador_mpl'")->fetchColumn(),
                'approved' => (int) ((isset($x['confirmed']) ? $x['confirmed'] : 0)),
                'kits' => $kits,
                'checkins' => $checkins,
                'categories' => $q->fetchAll()
            ]);
        }

        if ($what === 'categories') {
            resposta(
                confirmar(
                    $db,
                    (int) $e['id'],
                    'categories'
                )
            );
        }

        if ($what === 'lots') {
            $q = $db->prepare(
                "SELECT
                    l.id,
                    l.nome AS name,
                    l.preco * 100 AS price_cents,
                    l.quantidade AS capacity,
                    l.inicio AS starts_at,
                    l.fim AS ends_at,
                    l.ativo AS active,
                    l.categoria_id,
                    c.nome AS category_name
                 FROM lotes l
                 LEFT JOIN categorias c
                   ON c.id = l.categoria_id
                 WHERE l.corrida_id = ?
                 ORDER BY l.id"
            );

            $q->execute([
                $e['id']
            ]);

            $r = $q->fetchAll();

            foreach ($r as &$v) {
                $v['active'] = (bool) $v['active'];
                $v['price_cents'] = (int) $v['price_cents'];
                $v['capacity'] = (int) $v['capacity'];
            }

            unset($v);

            resposta($r);
        }

        if ($what === 'registrations') {
            $q = $db->prepare(
                "SELECT
                    i.id,
                    i.numero AS number,
                    CASE i.status
                        WHEN 'confirmada' THEN 'CONFIRMED'
                        WHEN 'paga' THEN 'CONFIRMED'
                        WHEN 'cancelada' THEN 'CANCELLED'
                        ELSE 'PENDING'
                    END AS status,
                    p.nome_completo AS name,
                    p.cpf AS cpf_masked,
                    c.nome AS category_name,
                    p.tamanho_camiseta AS shirt_size,
                    COALESCE(
                        rk.retirado_em,
                        ''
                    ) AS kit_at,
                    COALESCE(
                        ch.realizado_em,
                        ''
                    ) AS checkin_at,
                    UPPER(i.tipo) AS type,
                    i.valor * 100 AS amount_cents,
                    CASE WHEN i.tipo='colaborador_mpl' THEN 'ISENTO' ELSE CASE pb.status
                        WHEN 'PAID' THEN 'APPROVED'
                        WHEN 'FAILED' THEN 'DECLINED'
                        WHEN 'EXPIRED' THEN 'DECLINED'
                        WHEN 'CANCELLED' THEN 'DECLINED'
                        ELSE 'PENDING'
                    END END AS payment_status
                 FROM inscricoes i
                 JOIN participantes p
                   ON p.id = i.participante_id
                 JOIN categorias c
                   ON c.id = i.categoria_id
                 LEFT JOIN pagamentos_belluno pb
                   ON pb.inscricao_id = i.id
                 LEFT JOIN retiradas_kit rk
                   ON rk.inscricao_id = i.id
                 LEFT JOIN checkins ch
                   ON ch.inscricao_id = i.id
                 WHERE i.corrida_id = ?
                 ORDER BY i.criada_em DESC"
            );

            $q->execute([
                $e['id']
            ]);

            $r = $q->fetchAll();

            resposta([
                'rows' => $r,
                'total' => count($r)
            ]);
        }

        if ($what === 'users') {
            $r = $db
                ->query(
                    'SELECT
                        id,
                        nome AS name,
                        email,
                        perfil AS role,
                        ativo AS active
                     FROM usuarios_admin
                     ORDER BY nome'
                )
                ->fetchAll();

            foreach ($r as &$v) {
                $v['active'] = (bool) $v['active'];
            }

            unset($v);

            resposta($r);
        }

        if ($what === 'report') {
            $q = $db->prepare(
                "SELECT
                    i.numero,
                    p.nome_completo AS name,
                    p.email,
                    c.nome AS category_name,
                    i.status,
                    i.valor
                 FROM inscricoes i
                 JOIN participantes p
                   ON p.id = i.participante_id
                 JOIN categorias c
                   ON c.id = i.categoria_id
                 WHERE i.corrida_id = ?
                 ORDER BY i.criada_em DESC"
            );

            $q->execute([
                $e['id']
            ]);

            resposta([
                'rows' => $q->fetchAll()
            ]);
        }

        if ($what === 'settings') {
            resposta($e);
        }

        if (
            $what === 'save-category'
            || $what === 'save-lot'
            || $what === 'save-settings'
            || $what === 'save-user'
        ) {
            if (
                !in_array(
                    strtolower($u['role']),
                    [
                        'super_admin',
                        'admin'
                    ],
                    true
                )
            ) {
                resposta([
                    'error' => 'Sem permissão.'
                ], 403);
            }

            $x = (isset($dados['data']) ? $dados['data'] : []);

            if (!is_array($x)) {
                $x = [];
            }
        }

        if ($what === 'save-category') {
            $id = (int) ((isset($dados['id']) ? $dados['id'] : 0));

            $nome = trim(
                (string) ((isset($x['name']) ? $x['name'] : ''))
            );

            $km = (float) (
                (isset($x['distance_km']) ? $x['distance_km'] : 0)
            );

            if ($nome === '' || $km <= 0) {
                resposta([
                    'error' => 'Informe nome e distância.'
                ], 400);
            }

            $q = $db->prepare(
                'SELECT id
                 FROM distancias
                 WHERE corrida_id = ?
                   AND distancia_km = ?
                 LIMIT 1'
            );

            $q->execute([
                $e['id'],
                $km
            ]);

            $d = $q->fetch();

            if (!$d) {
                $q = $db->prepare(
                    'INSERT INTO distancias
                    (
                        corrida_id,
                        nome,
                        distancia_km
                    )
                    VALUES (?, ?, ?)'
                );

                $q->execute([
                    $e['id'],
                    $km . ' KM',
                    $km
                ]);

                $d = [
                    'id' => $db->lastInsertId()
                ];
            }

            $minAge = (int) (
                (isset($x['min_age']) ? $x['min_age'] : 0)
            );

            $maxAge = (int) (
                (isset($x['max_age']) ? $x['max_age'] : 120)
            );

            $ativo = !empty(
                $x['active']
            ) ? 1 : 0;

            if ($id) {
                $q = $db->prepare(
                    'UPDATE categorias
                     SET
                        nome = ?,
                        distancia_id = ?,
                        idade_minima = ?,
                        idade_maxima = ?,
                        ativa = ?
                     WHERE id = ?
                       AND corrida_id = ?'
                );

                $q->execute([
                    $nome,
                    (int) $d['id'],
                    $minAge,
                    $maxAge,
                    $ativo,
                    $id,
                    $e['id']
                ]);
            } else {
                $q = $db->prepare(
                    'INSERT INTO categorias
                    (
                        corrida_id,
                        nome,
                        distancia_id,
                        idade_minima,
                        idade_maxima,
                        ativa
                    )
                    VALUES (?, ?, ?, ?, ?, ?)'
                );

                $q->execute([
                    $e['id'],
                    $nome,
                    (int) $d['id'],
                    $minAge,
                    $maxAge,
                    $ativo
                ]);
            }

            resposta([
                'ok' => true
            ]);
        }

        if ($what === 'save-lot') {
            $id = (int) (
                (isset($dados['id']) ? $dados['id'] : 0)
            );

            $nome = trim(
                (string) ((isset($x['name']) ? $x['name'] : ''))
            );

            $categoriaId = (int) (
                (isset($x['category_id']) ? $x['category_id'] : 0)
            );

            $precoCentavos = (float) (
                (isset($x['price_cents']) ? $x['price_cents'] : 0)
            );

            $preco = $precoCentavos / 100;

            $quantidade = (int) (
                (isset($x['capacity']) ? $x['capacity'] : 0)
            );

            $inicio = (isset($x['starts_at']) ? $x['starts_at'] : null);
            $fim = (isset($x['ends_at']) ? $x['ends_at'] : null);

            $ativo = !empty(
                $x['active']
            ) ? 1 : 0;

            if ($nome === '') {
                resposta([
                    'error' => 'Informe o nome do lote.'
                ], 400);
            }

            if ($categoriaId <= 0) {
                resposta([
                    'error' => 'Selecione uma categoria.'
                ], 400);
            }

            if ($quantidade < 0) {
                resposta([
                    'error' => 'A quantidade de vagas não pode ser negativa.'
                ], 400);
            }

            if ($id) {
                $q = $db->prepare(
                    'UPDATE lotes
                     SET
                        categoria_id = ?,
                        nome = ?,
                        preco = ?,
                        quantidade = ?,
                        inicio = ?,
                        fim = ?,
                        ativo = ?
                     WHERE id = ?
                       AND corrida_id = ?'
                );

                $q->execute([
                    $categoriaId,
                    $nome,
                    $preco,
                    $quantidade,
                    $inicio,
                    $fim,
                    $ativo,
                    $id,
                    $e['id']
                ]);
            } else {
                $q = $db->prepare(
                    'INSERT INTO lotes
                    (
                        corrida_id,
                        categoria_id,
                        nome,
                        preco,
                        quantidade,
                        inicio,
                        fim,
                        ativo
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $q->execute([
                    $e['id'],
                    $categoriaId,
                    $nome,
                    $preco,
                    $quantidade,
                    $inicio,
                    $fim,
                    $ativo
                ]);
            }

            resposta([
                'ok' => true
            ]);
        }

        if ($what === 'save-settings') {
            $q = $db->prepare(
                'UPDATE corridas
                 SET
                    nome = ?,
                    data_corrida = ?,
                    horario_largada = ?,
                    local_corrida = ?,
                    cidade = ?,
                    inscricoes_abertas = ?,
                    status = ?
                 WHERE id = ?'
            );

            $q->execute([
                (isset($x['name']) ? $x['name'] : $e['name']),
                (isset($x['event_date']) ? $x['event_date'] : $e['event_date']),
                (isset($x['start_time']) ? $x['start_time'] : $e['start_time']),
                (isset($x['location']) ? $x['location'] : null),
                (isset($x['city']) ? $x['city'] : null),
                !empty($x['registration_open']) ? 1 : 0,
                !empty($x['published'])
                ? 'publicada'
                : 'rascunho',
                $e['id']
            ]);

            resposta([
                'ok' => true
            ]);
        }

        if ($what === 'save-user') {
            if (
                strtolower($u['role'])
                !== 'super_admin'
            ) {
                resposta([
                    'error' => 'Apenas super administrador pode alterar usuários.'
                ], 403);
            }

            $id = (int) (
                (isset($dados['id']) ? $dados['id'] : 0)
            );

            $email = strtolower(
                trim(
                    (string) ((isset($x['email']) ? $x['email'] : ''))
                )
            );

            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                resposta([
                    'error' => 'E-mail inválido.'
                ], 400);
            }

            $perfil = strtolower(
                (string) (
                    (isset($x['role']) ? $x['role'] : 'consulta')
                )
            );

            $senha = (string) (
                (isset($x['password']) ? $x['password'] : '')
            );

            $nome = trim(
                (string) (
                    (isset($x['name']) ? $x['name'] : $email)
                )
            );

            $ativo = !empty(
                $x['active']
            ) ? 1 : 0;

            if ($id) {
                $sql = '
                    UPDATE usuarios_admin
                    SET
                        nome = ?,
                        email = ?,
                        perfil = ?,
                        ativo = ?
                ';

                $args = [
                    $nome,
                    $email,
                    $perfil,
                    $ativo
                ];

                if ($senha !== '') {
                    $sql .= ', senha_hash = ?';

                    $args[] = password_hash(
                        $senha,
                        PASSWORD_DEFAULT
                    );
                }

                $sql .= ' WHERE id = ?';

                $args[] = $id;

                $q = $db->prepare($sql);

                $q->execute($args);
            } else {
                if ($senha === '') {
                    $senha = bin2hex(
                        random_bytes(8)
                    );
                }

                $q = $db->prepare(
                    'INSERT INTO usuarios_admin
                    (
                        nome,
                        email,
                        senha_hash,
                        perfil,
                        ativo
                    )
                    VALUES (?, ?, ?, ?, ?)'
                );

                $q->execute([
                    $nome,
                    $email,
                    password_hash(
                        $senha,
                        PASSWORD_DEFAULT
                    ),
                    $perfil,
                    $ativo
                ]);
            }

            resposta([
                'ok' => true
            ]);
        }

        resposta([]);
    }

    if ($acao === 'search-registration') {
        $email = strtolower(trim((string) ((isset($_SERVER['HTTP_X_MPL_EMAIL']) ? $_SERVER['HTTP_X_MPL_EMAIL'] : ''))));
        if ($email === '') {
            resposta(null);
        }
        $registrationId = (int) ((isset($dados['registration_id']) ? $dados['registration_id'] : 0));
        $where = $registrationId > 0 ? 'i.id = ? AND LOWER(TRIM(p.email)) = ?' : 'LOWER(TRIM(p.email)) = ?';
        $args = $registrationId > 0 ? [$registrationId, $email] : [$email];

        $q = $db->prepare(
            "SELECT
                i.id,
                i.numero AS number,
                CASE i.status
                    WHEN 'confirmada' THEN 'CONFIRMED'
                    WHEN 'paga' THEN 'CONFIRMED'
                    WHEN 'cancelada' THEN 'CANCELLED'
                    ELSE 'PENDING'
                END AS status,
                i.valor * 100 AS amount_cents,
                UPPER(i.tipo) AS type,
                p.nome_completo AS name,
                p.cpf,
                p.email,
                p.telefone AS phone,
                p.data_nascimento AS birth_date,
                p.tamanho_camiseta AS shirt_size,
                c.nome AS category_name,
                d.distancia_km,
                d.distancia_km AS distance_km,
                CASE WHEN i.tipo='colaborador_mpl' THEN 'ISENTO' ELSE CASE pb.status
                    WHEN 'PAID' THEN 'APPROVED'
                    WHEN 'FAILED' THEN 'DECLINED'
                    WHEN 'EXPIRED' THEN 'DECLINED'
                    WHEN 'CANCELLED' THEN 'DECLINED'
                    ELSE 'PENDING'
                END END AS payment_status,
                pb.id AS payment_id,
                pb.metodo AS payment_method,
                pb.transaction_id AS belluno_transaction_id,
                pb.status AS payment_local_status,
                pb.status_belluno AS belluno_status,
                pb.pix_code,
                rk.retirado_em AS kit_at,
                ch.realizado_em AS checkin_at
             FROM inscricoes i
             JOIN participantes p ON p.id = i.participante_id
             JOIN categorias c ON c.id = i.categoria_id
             JOIN distancias d ON d.id = i.distancia_id
             LEFT JOIN pagamentos_belluno pb ON pb.inscricao_id = i.id
             LEFT JOIN retiradas_kit rk ON rk.inscricao_id = i.id
             LEFT JOIN checkins ch ON ch.inscricao_id = i.id
             WHERE " . $where . "
             ORDER BY i.id DESC
             LIMIT 1"
        );
        $q->execute($args);

        resposta(
            $q->fetch() ?: null
        );
    }

    if ($acao === 'send-participant-email') {
        $registrationId = (int) ((isset($dados['registration_id']) ? $dados['registration_id'] : 0));
        $registration = paymentOwner($db, $registrationId);
        $payment = loadPayment($db, $registrationId);
        $confirmed = in_array($registration['status'], ['paga', 'confirmada'], true) || ($payment && $payment['status'] === 'PAID');
        $ticketToken = null;
        if ($confirmed) {
            $q = $db->prepare("SELECT token FROM tickets WHERE inscricao_id = ? AND status = 'ativo' LIMIT 1");
            $q->execute([$registrationId]);
            $ticket = $q->fetch();
            if ($ticket) {
                $ticketToken = (string) $ticket['token'];
            } else {
                $ticketToken = 'MPL-RACE-TICKET-' . strtoupper(bin2hex(random_bytes(8)));
                $q = $db->prepare('INSERT INTO tickets (inscricao_id, token, qr_code_data) VALUES (?, ?, ?)');
                $q->execute([$registrationId, $ticketToken, $ticketToken]);
                $ticketId = (int) $db->lastInsertId();
                $db->prepare('INSERT IGNORE INTO retiradas_kit (inscricao_id, ticket_id) VALUES (?, ?)')->execute([$registrationId, $ticketId]);
            }
        }
        $site = rtrim((string) ((isset($config['site_url']) ? $config['site_url'] : '')), '/');
        $message = "Olá, {$registration['nome_completo']}!\n\n"
            . "Acesse sua área da 4ª Corrida MPL pelo link:\n"
            . $site . '/minha-inscricao' . "\n\n"
            . "Número da inscrição: {$registration['numero']}\n"
            . "Categoria: {$registration['categoria_nome']}\n"
            . "Distância: {$registration['distancia_km']} km\n"
            . "Status: " . ($confirmed ? 'Inscrição confirmada' : 'Pagamento pendente') . "\n\n";
        if ($ticketToken)
            $message .= "QR Code / token do ingresso:\n{$ticketToken}\n\n";
        $message .= "Se você não solicitou este acesso, ignore esta mensagem.\n\n4ª Corrida MPL";
        $sent = enviarEmailCorredor($config, $registration['email'], $confirmed ? 'Seu acesso e QR Code, 4ª Corrida MPL' : 'Seu acesso à inscrição, 4ª Corrida MPL', $message);
        resposta(['ok' => true, 'email_sent' => $sent, 'has_qr' => (bool) $ticketToken]);
    }

    if ($acao === 'resend-registration-email') {
        $registrationId = (int) ((isset($dados['registration_id']) ? $dados['registration_id'] : 0));
        $registration = paymentOwner($db, $registrationId);
        $sent = enviarEmailCorredor(
            $config,
            $registration['email'],
            'Dados da sua inscrição, 4ª Corrida MPL',
            emailInscricao([
                'nome_completo' => $registration['nome_completo'],
                'email' => $registration['email'],
                'numero' => $registration['numero'],
                'categoria_nome' => $registration['categoria_nome'],
                'distancia_km' => $registration['distancia_km'],
                'valor' => $registration['valor']
            ])
        );
        resposta(['ok' => true, 'email_sent' => $sent]);
    }

    if ($acao === 'create-registration') {
        $invitationToken = trim((string) (isset($dados['invitation_token']) ? $dados['invitation_token'] : ''));
        $isCollaborator = $invitationToken !== '';
        if ($isCollaborator && !preg_match('/^[a-f0-9]{64}$/', $invitationToken)) resposta(array('error' => 'Convite inválido.'), 404);

        $requiredRegistration = array('name','cpf','birth_date','gender','email','phone','category_id','shirt_size','zip_code','street','address_number','neighborhood','city','state','emergency_name','emergency_phone','terms');
        foreach ($requiredRegistration as $field) {
            if (trim((string) (isset($dados[$field]) ? $dados[$field] : '')) === '') resposta(array('error' => 'Preencha todos os campos obrigatórios da inscrição.'), 422);
        }
        $cpf = preg_replace('/\D/', '', (string) $dados['cpf']);
        $email = strtolower(trim((string) $dados['email']));
        if (strlen($cpf) !== 11 || !filter_var($email, FILTER_VALIDATE_EMAIL)) resposta(array('error' => 'Confira o CPF e o e-mail informados.'), 422);

        $db->beginTransaction();
        try {
            $invite = null;
            if ($isCollaborator) {
                $q = $db->prepare("SELECT cv.*, co.nome AS event_name, co.status AS event_status, ca.nome AS category_name, ca.distancia_id, ca.ativa AS category_active, d.distancia_km FROM convites_colaboradores cv JOIN corridas co ON co.id=cv.corrida_id JOIN categorias ca ON ca.id=cv.categoria_id JOIN distancias d ON d.id=ca.distancia_id WHERE cv.token_hash=? FOR UPDATE");
                $q->execute(array(hash('sha256', $invitationToken))); $invite = $q->fetch();
                if (!$invite || $invite['cancelado_em'] || $invite['utilizado_em'] || strtotime($invite['expira_em']) <= time()) {
                    $db->rollBack(); resposta(array('error' => 'Este convite não está mais disponível.'), 410);
                }
                if ($invite['event_status'] !== 'publicada' || !(int) $invite['category_active']) {
                    $db->rollBack(); resposta(array('error' => 'A corrida ou categoria deste convite não está disponível.'), 409);
                }
                if ((int) $dados['category_id'] !== (int) $invite['categoria_id']) {
                    $db->rollBack(); resposta(array('error' => 'A categoria não corresponde ao convite.'), 422);
                }
                $eventId = (int) $invite['corrida_id'];
            } else {
                $e = corrida($db, $config);
                if (!$e['registration_open']) { $db->rollBack(); resposta(array('error' => 'As inscrições ainda estão fechadas.'), 400); }
                $eventId = (int) $e['id'];
            }

            $q = $db->prepare('SELECT * FROM participantes WHERE cpf=? LIMIT 1'); $q->execute(array($cpf)); $existingParticipant = $q->fetch();
            if ($existingParticipant) {
                if (strtolower(trim((string) $existingParticipant['email'])) !== $email) { $db->rollBack(); resposta(array('error' => 'Este CPF já está cadastrado com outro e-mail. Use o e-mail da inscrição existente.'), 409); }
                $pid = (int) $existingParticipant['id'];
                $q = $db->prepare('SELECT id,numero,status FROM inscricoes WHERE participante_id=? AND corrida_id=? ORDER BY id DESC LIMIT 1'); $q->execute(array($pid,$eventId)); $existingRegistration=$q->fetch();
                if ($existingRegistration) { $db->rollBack(); resposta(array('error' => 'Este CPF já possui inscrição nesta corrida.'), 409); }
                $db->prepare('UPDATE participantes SET nome_completo=?,telefone=?,data_nascimento=?,genero=?,tamanho_camiseta=?,consentiu_lgpd=1,consentiu_lgpd_em=NOW() WHERE id=?')->execute(array($dados['name'],$dados['phone'],$dados['birth_date'],$dados['gender'],$dados['shirt_size'],$pid));
            } else {
                $q=$db->prepare('INSERT INTO participantes (nome_completo,email,cpf,telefone,data_nascimento,genero,tamanho_camiseta,consentiu_lgpd,consentiu_lgpd_em) VALUES (?,?,?,?,?,?,?,1,NOW())');
                $q->execute(array($dados['name'],$email,$cpf,$dados['phone'],$dados['birth_date'],$dados['gender'],$dados['shirt_size'])); $pid=(int)$db->lastInsertId();
            }

            if ($isCollaborator) {
                $cat = array('id'=>(int)$invite['categoria_id'],'nome'=>$invite['category_name'],'distancia_id'=>(int)$invite['distancia_id'],'distancia_km'=>$invite['distancia_km'],'preco'=>0);
            } else {
                $q=$db->prepare("SELECT c.*,d.distancia_km,COALESCE((SELECT preco FROM lotes WHERE corrida_id=? AND categoria_id=c.id AND ativo=1 ORDER BY id LIMIT 1),0) AS preco FROM categorias c JOIN distancias d ON d.id=c.distancia_id WHERE c.id=? AND c.corrida_id=? AND c.ativa=1");
                $q->execute(array($eventId,(int)$dados['category_id'],$eventId)); $cat=$q->fetch(); if(!$cat) throw new RuntimeException('Categoria inválida.');
            }

            $numero='MPL'.date('Y').str_pad((string)$eventId,2,'0',STR_PAD_LEFT).str_pad((string)$pid,7,'0',STR_PAD_LEFT);
            $status=$isCollaborator?'confirmada':'pendente_pagamento'; $type=$isCollaborator?'colaborador_mpl':'publico'; $value=$isCollaborator?0:$cat['preco'];
            $q=$db->prepare('INSERT INTO inscricoes (numero,corrida_id,participante_id,categoria_id,distancia_id,status,tipo,valor) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute(array($numero,$eventId,$pid,$cat['id'],$cat['distancia_id'],$status,$type,$value)); $id=(int)$db->lastInsertId();

            if ($isCollaborator) {
                $db->prepare("INSERT INTO pagamentos (inscricao_id,provedor,metodo,valor,status,pago_em) VALUES (?,'interno','isento',0,'isento',NOW())")->execute(array($id));
                garantirTicketMpl($db,$id);
                $q=$db->prepare('UPDATE convites_colaboradores SET utilizado_em=NOW(),participante_id=?,inscricao_id=?,atualizado_em=NOW() WHERE id=? AND utilizado_em IS NULL AND cancelado_em IS NULL AND expira_em>NOW()');
                $q->execute(array($pid,$id,(int)$invite['id'])); if($q->rowCount()!==1) throw new RuntimeException('O convite foi utilizado por outra inscrição.');
            }

            $db->commit();
            if ($isCollaborator) {
                $emailSent=enviarIngressoMpl($db,$config,$id,true);
            } else {
                $registrationEmail=array('nome_completo'=>$dados['name'],'email'=>$email,'numero'=>$numero,'categoria_nome'=>$cat['nome'],'distancia_km'=>$cat['distancia_km'],'valor'=>$value);
                $emailSent=enviarEmailCorredor($config,$email,'Inscrição recebida, 4ª Corrida MPL',emailInscricao($registrationEmail));
            }
            resposta(array('id'=>$id,'number'=>$numero,'email_sent'=>$emailSent,'type'=>strtoupper($type),'payment_status'=>$isCollaborator?'ISENTO':'PENDING','is_collaborator'=>$isCollaborator));
        } catch (Exception $z) {
            if ($db->inTransaction()) $db->rollBack();
            throw $z;
        }
    }

    if ($acao === 'generate-ticket') {
        exigir($config);

        $id = (int) (
            (isset($dados['registration_id']) ? $dados['registration_id'] : 0)
        );

        if ($id <= 0) {
            resposta(['error' => 'Inscrição inválida.'], 422);
        }

        // O ticket só é emitido após a confirmação do pagamento.
        $statusQuery = $db->prepare(
            "SELECT i.status, pb.status AS pagamento
             FROM inscricoes i
             LEFT JOIN pagamentos_belluno pb ON pb.inscricao_id = i.id
             WHERE i.id = ? LIMIT 1"
        );
        $statusQuery->execute([$id]);
        $situacao = $statusQuery->fetch();
        if (!$situacao) {
            resposta(['error' => 'Inscrição não encontrada.'], 404);
        }
        $confirmada = in_array($situacao['status'], ['paga', 'confirmada'], true)
            || ((isset($situacao['pagamento']) ? $situacao['pagamento'] : '')) === 'PAID';
        if (!$confirmada) {
            resposta(['error' => 'O ingresso só pode ser gerado após a confirmação do pagamento.'], 409);
        }

        $q = $db->prepare(
            "SELECT token
             FROM tickets
             WHERE inscricao_id = ?
               AND status = 'ativo'"
        );

        $q->execute([
            $id
        ]);

        $r = $q->fetch();

        if (!$r) {
            $t =
                'MPL-RACE-TICKET-'
                . strtoupper(
                    bin2hex(
                        random_bytes(8)
                    )
                );

            $q = $db->prepare(
                'INSERT INTO tickets
                (
                    inscricao_id,
                    token,
                    qr_code_data
                )
                VALUES (?, ?, ?)'
            );

            $q->execute([
                $id,
                $t,
                $t
            ]);

            $tid = $db->lastInsertId();

            $db->prepare(
                'INSERT IGNORE INTO retiradas_kit
                (
                    inscricao_id,
                    ticket_id
                )
                VALUES (?, ?)'
            )->execute([
                        $id,
                        $tid
                    ]);

            $r = [
                'token' => $t
            ];
        }

        resposta($r);
    }

    require __DIR__ . '/pagarme_checkout.php';

    if (in_array($acao, ['card-hash-key', 'create-payment', 'belluno-webhook'], true)) {
        resposta(['error' => 'Integração Belluno desativada. Utilize o Checkout Pagar.me/Stone.'], 410);
    }

    if ($acao === 'card-hash-key') {
        $result = bellunoRequest($config, 'GET', '/transaction/card_hash_key');
        if ($result['http_status'] < 200 || $result['http_status'] >= 300) {
            resposta(['error' => 'Não foi possível preparar o cartão para pagamento.'], 400);
        }
        $body = $result['body'];
        if (empty($body['id']) || empty($body['rsa_public_key'])) {
            resposta(['error' => 'A Belluno não forneceu uma chave válida para o cartão.'], 400);
        }
        resposta([
            'id' => (string) $body['id'],
            'rsa_public_key' => (string) $body['rsa_public_key'],
            'created_at' => (isset($body['created_at']) ? $body['created_at'] : null)
        ]);
    }

    if ($acao === 'create-payment') {
        $registrationId = (int) ((isset($dados['registration_id']) ? $dados['registration_id'] : 0));
        $method = strtoupper(trim((string) ((isset($dados['method']) ? $dados['method'] : ''))));
        if ($registrationId <= 0 || !in_array($method, ['PIX', 'CARD'], true)) {
            resposta(['error' => 'Selecione uma inscrição e um método de pagamento válido.'], 422);
        }
        $registration = paymentOwner($db, $registrationId);
        if (in_array($registration['status'], ['paga', 'confirmada'], true)) {
            resposta(['error' => 'Esta inscrição já está confirmada.'], 409);
        }

        $payment = loadPayment($db, $registrationId);
        if ($payment && $payment['status'] === 'PAID') {
            resposta(['payment' => paymentView($payment)]);
        }
        if ($payment && in_array($payment['status'], ['PENDING_PAYMENT', 'PROCESSING'], true) && ($payment['transaction_id'] || $payment['status'] === 'PROCESSING')) {
            if ($payment['metodo'] !== $method) {
                resposta(['error' => 'Já existe uma cobrança pendente para esta inscrição. Aguarde a confirmação ou use a mesma forma de pagamento.'], 409);
            }
            resposta(['payment' => paymentView($payment)]);
        }

        $reference = 'MPL-' . $registration['numero'] . '-' . $method;
        $db->beginTransaction();
        try {
            $payment = loadPayment($db, $registrationId);
            if (!$payment) {
                $q = $db->prepare(
                    "INSERT INTO pagamentos_belluno (inscricao_id, metodo, referencia_externa, valor, status, tentativas)
                     VALUES (?, ?, ?, ?, 'PROCESSING', 1)"
                );
                $q->execute([$registrationId, $method, $reference, (float) $registration['valor']]);
            } else {
                $q = $db->prepare(
                    "UPDATE pagamentos_belluno SET metodo = ?, referencia_externa = ?, valor = ?, transaction_id = NULL, pix_code = NULL, status = 'PROCESSING', status_belluno = NULL, erro_tecnico = NULL, confirmado_em = NULL, tentativas = tentativas + 1, atualizado_em = NOW() WHERE id = ?"
                );
                $q->execute([$method, $reference, (float) $registration['valor'], (int) $payment['id']]);
            }
            $db->commit();
        } catch (Exception $z) {
            if ($db->inTransaction())
                $db->rollBack();
            if ($z instanceof PDOException && $z->getCode() === '23000') {
                $existing = loadPayment($db, $registrationId);
                if ($existing)
                    resposta(['payment' => paymentView($existing)]);
            }
            throw $z;
        }
        $payment = loadPayment($db, $registrationId);

        $value = (float) $registration['valor'];
        $cart = [
            [
                'product_name' => 'Inscrição 4ª Corrida MPL',
                'quantity' => 1,
                'unit_value' => $value
            ]
        ];
        if ($method === 'PIX') {
            $transaction = [
                'value' => $value,
                'client_name' => $registration['nome_completo'],
                'client_document' => $registration['cpf'],
                'client_email' => $registration['email'],
                'client_phone' => $registration['telefone'],
                'client_cellphone' => $registration['telefone'],
                'details' => 'Inscrição ' . $registration['numero'],
                'cart' => $cart
            ];
        } else {
            $cardHash = trim((string) ((isset($dados['card_hash']) ? $dados['card_hash'] : '')));
            $visitorId = trim((string) ((isset($config['belluno_visitor_id']) ? $config['belluno_visitor_id'] : '')));
            $brand = (int) ((isset($dados['brand']) ? $dados['brand'] : 0));
            $installments = (int) ((isset($dados['installment_number']) ? $dados['installment_number'] : 0));
            if (!in_array($brand, [1, 2, 3, 4, 5, 6, 7], true) || $installments < 1 || $installments > 12) {
                resposta(['error' => 'Bandeira ou quantidade de parcelas inválida.'], 422);
            }
            if ($cardHash === '' || $visitorId === '') {
                $db->prepare("UPDATE pagamentos_belluno SET status = 'FAILED', erro_tecnico = ?, atualizado_em = NOW() WHERE id = ?")->execute(['Dados de cartão ou visitor_id não configurados.', (int) $payment['id']]);
                resposta(['error' => 'O pagamento por cartão ainda não está pronto para este ambiente.'], 422);
            }
            $billing = is_array((isset($dados['billing']) ? $dados['billing'] : null)) ? $dados['billing'] : [];
            foreach (['postalCode', 'street', 'number', 'city', 'state'] as $field) {
                if (trim((string) ((isset($billing[$field]) ? $billing[$field] : ''))) === '') {
                    resposta(['error' => 'Preencha o endereço de cobrança para pagar com cartão.'], 422);
                }
            }
            $birth = (string) ((isset($dados['cardholder_birth']) ? $dados['cardholder_birth'] : $registration['data_nascimento']));
            $birthTimestamp = strtotime($birth);
            $birthBelluno = $birthTimestamp ? date('d/m/Y', $birthTimestamp) : $birth;
            $transaction = [
                'value' => $value,
                'card_hash' => $cardHash,
                'cardholder_name' => trim((string) ((isset($dados['cardholder_name']) ? $dados['cardholder_name'] : $registration['nome_completo']))),
                'cardholder_document' => preg_replace('/\D/', '', (string) ((isset($dados['cardholder_document']) ? $dados['cardholder_document'] : $registration['cpf']))),
                'cardholder_cellphone' => $registration['telefone'],
                'cardholder_birth' => $birthBelluno,
                'brand' => $brand,
                'installment_number' => $installments,
                'visitor_id' => $visitorId,
                'payer_ip' => (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0'),
                'client_name' => $registration['nome_completo'],
                'client_document' => $registration['cpf'],
                'client_email' => $registration['email'],
                'client_cellphone' => $registration['telefone'],
                'billing' => [
                    'postalCode' => trim((string) $billing['postalCode']),
                    'street' => trim((string) $billing['street']),
                    'number' => trim((string) $billing['number']),
                    'city' => trim((string) $billing['city']),
                    'state' => strtoupper(trim((string) $billing['state']))
                ],
                'cart' => $cart
            ];
        }
        $postbackUrl = trim((string) ((isset($config['belluno_postback_url']) ? $config['belluno_postback_url'] : '')));
        if ($postbackUrl !== '')
            $transaction['postback'] = ['url' => $postbackUrl];

        try {
            $result = bellunoRequest($config, 'POST', $method === 'PIX' ? '/transaction/pix' : '/transaction/async', ['transaction' => $transaction]);
            if ($result['http_status'] < 200 || $result['http_status'] >= 300) {
                throw new RuntimeException('A Belluno recusou a cobrança.');
            }
            $body = $result['body'];
            $transactionId = bellunoTransactionId($body);
            if ($transactionId === null)
                throw new RuntimeException('A Belluno não retornou o identificador da transação.');
            $rawStatus = bellunoRawStatus($body);
            $localStatus = bellunoLocalStatus($rawStatus);
            $pixCode = bellunoPixCode($body);
            $q = $db->prepare('UPDATE pagamentos_belluno SET transaction_id = ?, status = ?, status_belluno = ?, pix_code = ?, erro_tecnico = NULL, atualizado_em = NOW() WHERE id = ?');
            $q->execute([$transactionId, $localStatus, $rawStatus ?: null, $pixCode, (int) $payment['id']]);
            if ($localStatus === 'PAID') {
                $db->prepare("UPDATE inscricoes SET status = 'paga' WHERE id = ? AND status <> 'cancelada'")->execute([$registrationId]);
            }
            $payment = loadPayment($db, $registrationId);
            resposta(['payment' => paymentView($payment)]);
        } catch (Exception $z) {
            $db->prepare("UPDATE pagamentos_belluno SET status = 'FAILED', erro_tecnico = ?, atualizado_em = NOW() WHERE id = ?")->execute([mb_substr($z->getMessage(), 0, 1000), (int) $payment['id']]);
            resposta(['error' => $z->getMessage()], 400);
        }
    }

    if ($acao === 'payment-status') {
        $registrationId = (int) ((isset($dados['registration_id']) ? $dados['registration_id'] : 0));
        $registration = paymentOwner($db, $registrationId);
        $payment = loadPayment($db, $registrationId);
        if (!$payment)
            resposta(['error' => 'Nenhuma cobrança foi criada para esta inscrição.'], 404);
        if ($payment['transaction_id'] && in_array($payment['status'], ['PENDING_PAYMENT', 'PROCESSING'], true)) {
            $path = $payment['metodo'] === 'PIX'
                ? '/transaction/' . rawurlencode((string) $payment['transaction_id']) . '/pix'
                : '/transaction/' . rawurlencode((string) $payment['transaction_id']);
            try {
                $result = bellunoRequest($config, 'GET', $path);
                if ($result['http_status'] >= 200 && $result['http_status'] < 300) {
                    $db->beginTransaction();
                    try {
                        $payment = applyBellunoStatus($db, $config, (int) $payment['id'], $result['body']);
                        $db->commit();
                    } catch (Exception $z) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        throw $z;
                    }
                }
            } catch (Exception $z) {
                resposta(['error' => 'Não foi possível consultar o status agora.'], 400);
            }
        }
        resposta(['payment' => paymentView($payment)]);
    }

    if ($acao === 'belluno-webhook') {
        $secret = trim((string) ((isset($config['belluno_postback_secret']) ? $config['belluno_postback_secret'] : '')));
        $providedSecret = trim((string) ((isset($_SERVER['HTTP_X_BELLUNO_WEBHOOK_SECRET']) ? $_SERVER['HTTP_X_BELLUNO_WEBHOOK_SECRET'] : '')));
        if ($secret !== '' && !hash_equals($secret, $providedSecret))
            resposta(['error' => 'Postback não autorizado.'], 401);
        $body = $dados;
        bellunoLog('webhook_received', ['has_transaction' => bellunoTransactionId($body) !== null]);
        $transactionId = bellunoTransactionId($body);
        if ($transactionId === null)
            resposta(['error' => 'Transação não informada.'], 422);
        $q = $db->prepare('SELECT id, valor FROM pagamentos_belluno WHERE transaction_id = ? LIMIT 1');
        $q->execute([$transactionId]);
        $payment = $q->fetch();
        if (!$payment)
            resposta(['error' => 'Transação não encontrada.'], 404);
        $db->beginTransaction();
        try {
            $updated = applyBellunoStatus($db, $config, (int) $payment['id'], $body);
            $db->commit();
            resposta(['ok' => true, 'status' => $updated['status']]);
        } catch (Exception $z) {
            if ($db->inTransaction())
                $db->rollBack();
            resposta(['error' => 'Postback rejeitado.'], 422);
        }
    }

    if ($acao === 'validate-ticket') {
        exigir(
            $config,
            [
                'super_admin',
                'admin',
                'operador'
            ]
        );

        $consulta = (string) (
            (isset($dados['query']) ? $dados['query'] : '')
        );

        $cpf = preg_replace(
            '/\D/',
            '',
            $consulta
        );

        $s = $db->prepare(
            "SELECT
                i.id,
                i.numero AS number,
                CASE i.status
                    WHEN 'confirmada' THEN 'CONFIRMED'
                    WHEN 'paga' THEN 'CONFIRMED'
                    WHEN 'cancelada' THEN 'CANCELLED'
                    ELSE 'PENDING'
                END AS status,
                p.nome_completo AS name,
                p.cpf,
                c.nome AS category_name,
                p.tamanho_camiseta AS shirt_size,
                t.token,
                rk.retirado_em AS kit_at,
                ch.realizado_em AS checkin_at,
                UPPER(i.tipo) AS type,
                CASE
                    WHEN i.tipo='colaborador_mpl' THEN 'ISENTO'
                    WHEN i.status IN ('paga','confirmada') OR pb.status = 'PAID' THEN 'APPROVED'
                    WHEN pb.status IN ('FAILED','EXPIRED','CANCELLED') THEN 'DECLINED'
                    ELSE 'PENDING'
                END AS payment_status
             FROM tickets t
             JOIN inscricoes i
               ON i.id = t.inscricao_id
             JOIN participantes p
               ON p.id = i.participante_id
             JOIN categorias c
               ON c.id = i.categoria_id
             LEFT JOIN pagamentos_belluno pb
               ON pb.inscricao_id = i.id
             LEFT JOIN retiradas_kit rk
               ON rk.inscricao_id = i.id
             LEFT JOIN checkins ch
               ON ch.inscricao_id = i.id
             WHERE t.token = ?
                OR i.numero = ?
                OR p.cpf = ?
             LIMIT 1"
        );

        $s->execute([
            $consulta,
            $consulta,
            $cpf
        ]);

        $r = $s->fetch();

        if (!$r) {
            resposta([
                'error' => 'Participante não encontrado.'
            ], 404);
        }

        resposta($r);
    }

    if (
        $acao === 'withdraw-kit'
        || $acao === 'checkin'
    ) {
        $u = exigir(
            $config,
            [
                'super_admin',
                'admin',
                'operador'
            ]
        );

        $id = (int) (
            (isset($dados['registration_id']) ? $dados['registration_id'] : 0)
        );

        if ($acao === 'withdraw-kit') {
            $info = $db->prepare(
                "SELECT i.status AS inscricao_status,
                        pb.status AS pagamento_status,
                        rk.status AS retirada_status
                 FROM inscricoes i
                 LEFT JOIN pagamentos_belluno pb ON pb.inscricao_id = i.id
                 LEFT JOIN retiradas_kit rk ON rk.inscricao_id = i.id
                 WHERE i.id = ? LIMIT 1"
            );
            $info->execute([$id]);
            $reg = $info->fetch();
            if (!$reg) {
                resposta(['error' => 'Inscrição não encontrada.'], 404);
            }
            $pago = in_array($reg['inscricao_status'], ['paga', 'confirmada'], true)
                || ((isset($reg['pagamento_status']) ? $reg['pagamento_status'] : '')) === 'PAID';
            if (!$pago) {
                resposta(['error' => 'Pagamento não confirmado. O kit não pode ser retirado.'], 409);
            }
            if ($reg['retirada_status'] === null) {
                resposta(['error' => 'Ingresso ainda não emitido para esta inscrição.'], 409);
            }
            if ($reg['retirada_status'] === 'retirado') {
                resposta(['error' => 'O kit desta inscrição já foi retirado.'], 409);
            }
            $q = $db->prepare(
                "UPDATE retiradas_kit
                 SET
                    status = 'retirado',
                    retirado_em = NOW(),
                    operador_id = ?
                 WHERE inscricao_id = ?
                   AND status = 'pendente'"
            );

            $q->execute([
                $u['sub'],
                $id
            ]);
        } else {
            $info = $db->prepare(
                "SELECT i.status AS inscricao_status, pb.status AS pagamento_status
                 FROM inscricoes i
                 LEFT JOIN pagamentos_belluno pb ON pb.inscricao_id = i.id
                 WHERE i.id = ? LIMIT 1"
            );
            $info->execute([$id]);
            $reg = $info->fetch();
            if (!$reg) {
                resposta(['error' => 'Inscrição não encontrada.'], 404);
            }
            $pago = in_array($reg['inscricao_status'], ['paga', 'confirmada'], true)
                || ((isset($reg['pagamento_status']) ? $reg['pagamento_status'] : '')) === 'PAID';
            if (!$pago) {
                resposta(['error' => 'Pagamento não confirmado. Check-in não permitido.'], 409);
            }
            $q = $db->prepare(
                "INSERT IGNORE INTO checkins
                (
                    inscricao_id,
                    ticket_id,
                    corrida_id,
                    operador_id
                )
                SELECT
                    i.id,
                    t.id,
                    i.corrida_id,
                    ?
                FROM inscricoes i
                JOIN tickets t
                  ON t.inscricao_id = i.id
                WHERE i.id = ?"
            );

            $q->execute([
                $u['sub'],
                $id
            ]);
        }

        if ($q->rowCount() === 0) {
            resposta([
                'error' => 'Operação já realizada ou registro inexistente.'
            ], 409);
        }

        resposta([
            'ok' => true
        ]);
    }

    resposta([
        'error' => 'Ação desconhecida.'
    ], 404);

} catch (Exception $e) {
    resposta([
        'error' => $config['debug']
            ? $e->getMessage()
            : 'Erro interno. Verifique o XAMPP e o MySQL.'
    ], 500);
}

