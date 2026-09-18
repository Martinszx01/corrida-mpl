<?php
$config = require __DIR__ . '/api/core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ((isset($config['cors_origin']) ? $config['cors_origin'] : '*')));
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-MPL-Token, X-MPL-Participant-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$acao = (isset($_GET['action']) ? $_GET['action'] : '');
$dados = entrada();

try {
    $db = banco($config);
    ensurePagamentoSchema($db);
    ensurePatrocinadoresSchema($db);
    ensureConvitesColaboradoresSchema($db);
    ensureSecuritySchema($db);

    if ($acao === 'health') {
        $db->query('SELECT 1');

        resposta([
            'status' => 'healthy'
        ]);
    }

    if ($acao === 'diagnostico') {
        exigir($config, array('super_admin'));
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
            'pagamentos_gateway',
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
            'tabelas' => $ok
        ]);
    }

    if ($acao === 'public-event') {
        resposta(
            corrida($db, $config)
        );
    }

    if ($acao === 'request-participant-access') {
        $email = strtolower(trim((string)(isset($dados['email']) ? $dados['email'] : '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) resposta(array('error' => 'Informe um e-mail válido.'), 422);
        rateLimit($db, $config, 'participant-ip', clientIp(), 5, 900, 900);
        rateLimit($db, $config, 'participant-email', $email, 3, 900, 900);
        $q = $db->prepare('SELECT p.id FROM participantes p JOIN inscricoes i ON i.participante_id = p.id WHERE LOWER(TRIM(p.email)) = ? LIMIT 1');
        $q->execute(array($email));
        if ($q->fetch()) {
            $random = unpack('Nvalue', random_bytes(4));
            $code = str_pad((string)($random['value'] % 1000000), 6, '0', STR_PAD_LEFT);
            $codeHash = hash_hmac('sha256', $email . '|' . $code, $config['jwt_secret']);
            $db->prepare('UPDATE participante_codigos_acesso SET utilizado_em = NOW() WHERE email = ? AND utilizado_em IS NULL')->execute(array($email));
            $db->prepare('INSERT INTO participante_codigos_acesso (email, codigo_hash, expira_em) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute(array($email, $codeHash));
            $message = "Seu código de acesso à inscrição da 4ª Corrida MPL é: " . $code . "\n\nO código expira em 10 minutos. Se você não solicitou este acesso, ignore esta mensagem.";
            enviarEmailCorredor($config, $email, 'Código de acesso, 4ª Corrida MPL', $message);
        }
        resposta(array('ok' => true, 'message' => 'Se houver uma inscrição para este e-mail, o código será enviado.'));
    }

    if ($acao === 'verify-participant-access') {
        $email = strtolower(trim((string)(isset($dados['email']) ? $dados['email'] : '')));
        $code = preg_replace('/\D/', '', (string)(isset($dados['code']) ? $dados['code'] : ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) !== 6) resposta(array('error' => 'Código inválido ou expirado.'), 401);
        rateLimit($db, $config, 'participant-verify-ip', clientIp(), 10, 900, 900);
        $db->beginTransaction();
        $q = $db->prepare('SELECT * FROM participante_codigos_acesso WHERE email = ? AND utilizado_em IS NULL AND expira_em > NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $q->execute(array($email)); $access = $q->fetch();
        $valid = $access && (int)$access['tentativas'] < 5 && hash_equals($access['codigo_hash'], hash_hmac('sha256', $email . '|' . $code, $config['jwt_secret']));
        if (!$valid) {
            if ($access) $db->prepare('UPDATE participante_codigos_acesso SET tentativas = tentativas + 1 WHERE id = ?')->execute(array((int)$access['id']));
            $db->commit();
            resposta(array('error' => 'Código inválido ou expirado.'), 401);
        }
        $db->prepare('UPDATE participante_codigos_acesso SET utilizado_em = NOW() WHERE id = ?')->execute(array((int)$access['id']));
        $token = participantSessionCreate($db, $email);
        $db->commit();
        auditLog($db, null, 'PARTICIPANTE_LOGIN', 'participantes', null, 'Acesso do participante confirmado por código de uso único.');
        resposta(array('ok' => true, 'token' => $token, 'email' => $email));
    }

    if ($acao === 'participant-logout') {
        $token = trim((string)(isset($_SERVER['HTTP_X_MPL_PARTICIPANT_TOKEN']) ? $_SERVER['HTTP_X_MPL_PARTICIPANT_TOKEN'] : ''));
        if (preg_match('/^[a-f0-9]{64}$/', $token)) $db->prepare('UPDATE participante_sessoes SET revogado_em = NOW() WHERE token_hash = ?')->execute(array(hash('sha256', $token)));
        resposta(array('ok' => true));
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
        rateLimit($db, $config, 'sponsor-interest', clientIp(), 5, 3600, 3600);
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
        $loginLimitKey = rateLimit($db, $config, 'admin-login', clientIp() . '|' . $email, 5, 900, 900);

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
            auditLog($db, $u ? (int)$u['id'] : null, 'LOGIN_FALHOU', 'usuarios_admin', $u ? (int)$u['id'] : null, 'Tentativa administrativa recusada.');
            resposta([
                'error' => 'E-mail ou senha inválidos.'
            ], 401);
        }

        rateLimitClear($db, $loginLimitKey);
        auditLog($db, (int)$u['id'], 'LOGIN_SUCESSO', 'usuarios_admin', (int)$u['id'], 'Acesso administrativo autenticado.');

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
        if (empty($config['allow_bootstrap_admin'])) resposta(array('error' => 'Criação inicial de administrador desativada.'), 403);
        rateLimit($db, $config, 'create-admin', clientIp(), 3, 3600, 3600);
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

        $bootstrapName = trim((string)(isset($dados['name']) ? $dados['name'] : ''));
        $bootstrapEmail = strtolower(trim((string)(isset($dados['email']) ? $dados['email'] : '')));
        $bootstrapPassword = (string)(isset($dados['password']) ? $dados['password'] : '');
        if (mb_strlen($bootstrapName) < 3 || !filter_var($bootstrapEmail, FILTER_VALIDATE_EMAIL) || strlen($bootstrapPassword) < 12) resposta(array('error' => 'Informe nome, e-mail válido e senha com pelo menos 12 caracteres.'), 422);

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
            $bootstrapName,
            $bootstrapEmail,
            password_hash(
                $bootstrapPassword,
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
        $u = exigir($config, ['super_admin', 'admin']);
        $id = (int) ((isset($_POST['sponsor_id']) ? $_POST['sponsor_id'] : 0));
        $file = (isset($_FILES['logo']) ? $_FILES['logo'] : null);
        if ($id <= 0 || !is_array($file) || (isset($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) $file['size'] > 5 * 1024 * 1024)
            resposta(['error' => 'Logo inválida.'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $imageInfo = @getimagesize($file['tmp_name']);
        if (!isset($ext[$mime]) || $imageInfo === false || (int)$imageInfo[0] > 5000 || (int)$imageInfo[1] > 5000)
            resposta(['error' => 'Envie JPG, PNG ou WebP.'], 422);
        $event = corrida($db, $config);
        $owner = $db->prepare('SELECT id FROM patrocinadores WHERE id = ? AND corrida_id = ? LIMIT 1');
        $owner->execute(array($id, (int)$event['id']));
        if (!$owner->fetch()) resposta(array('error' => 'Patrocinador não encontrado.'), 404);
        $dir = __DIR__ . '/assets/images/sponsors';
        if (!is_dir($dir))
            mkdir($dir, 0750, true);
        $name = bin2hex(random_bytes(16)) . '.' . $ext[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name))
            resposta(['error' => 'Não foi possível salvar a logo.'], 500);
        $q = $db->prepare('UPDATE patrocinadores SET logo_path = ?, atualizado_em = NOW() WHERE id = ?');
        $q->execute(['assets/images/sponsors/' . $name, $id]);
        auditLog($db, (int)$u['sub'], 'PATROCINADOR_LOGO', 'patrocinadores', $id, 'Logo do patrocinador atualizada.');
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
        $adminRole = strtolower((string)$u['role']);

        if ($what === 'users' && $adminRole !== 'super_admin') resposta(array('error' => 'Apenas super administrador pode consultar usuários.'), 403);
        if ($what === 'settings' && !in_array($adminRole, array('super_admin','admin'), true)) resposta(array('error' => 'Sem permissão para consultar configurações.'), 403);
        if ($what === 'report' && !in_array($adminRole, array('super_admin','admin','financeiro','consulta'), true)) resposta(array('error' => 'Sem permissão para emitir relatórios.'), 403);

        if (in_array($what, array('invitations', 'invitation-options', 'create-invitation', 'cancel-invitation', 'send-invitation', 'resend-invitation-confirmation'), true)) {
            if (!in_array(strtolower((string) $u['role']), array('super_admin', 'admin'), true)) resposta(array('error' => 'Sem permissão para gerenciar convites.'), 403);
        }

        if ($what === 'invitation-options') {
            $rows = $db->query("SELECT co.id AS event_id, co.nome AS event_name, ca.id AS category_id, ca.nome AS category_name, d.distancia_km FROM corridas co JOIN categorias ca ON ca.corrida_id=co.id AND ca.ativa=1 JOIN distancias d ON d.id=ca.distancia_id WHERE co.status='publicada' AND d.distancia_km=5 ORDER BY co.data_corrida DESC, ca.ordem, ca.id")->fetchAll();
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
            $eventId = (int) (isset($dados['event_id']) ? $dados['event_id'] : 0);
            $q = $db->prepare("SELECT ca.id FROM categorias ca JOIN corridas co ON co.id=ca.corrida_id JOIN distancias d ON d.id=ca.distancia_id WHERE ca.corrida_id=? AND ca.ativa=1 AND co.status='publicada' AND d.distancia_km=5 ORDER BY ca.ordem,ca.id LIMIT 1");
            $q->execute(array($eventId)); $categoryId = (int) $q->fetchColumn(); if (!$categoryId) resposta(array('error' => 'A prova de 5 km não está disponível para esta corrida.'), 422);
            $token = bin2hex(random_bytes(32)); $hours = max(1, min(8760, (int) $config['invitation_expires_hours']));
            $q = $db->prepare('INSERT INTO convites_colaboradores (corrida_id,categoria_id,token_hash,token_criptografado,expira_em,criado_por) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL ? HOUR),?)');
            $q->execute(array($eventId, $categoryId, hash('sha256', $token), conviteCriptografar($token, $config['jwt_secret']), $hours, (int) $u['sub']));
            $inviteId = (int)$db->lastInsertId(); auditLog($db, (int)$u['sub'], 'CONVITE_CRIADO', 'convites_colaboradores', $inviteId, 'Convite de colaborador criado.');
            resposta(array('ok' => true, 'id' => $inviteId, 'link' => conviteLink($config, $token)), 201);
        }

        if ($what === 'cancel-invitation') {
            $id = (int) (isset($dados['id']) ? $dados['id'] : 0);
            $q = $db->prepare('UPDATE convites_colaboradores SET cancelado_em=NOW(), atualizado_em=NOW() WHERE id=? AND utilizado_em IS NULL AND cancelado_em IS NULL AND expira_em>NOW()'); $q->execute(array($id));
            if (!$q->rowCount()) resposta(array('error' => 'Convite indisponível ou já utilizado.'), 409); auditLog($db, (int)$u['sub'], 'CONVITE_CANCELADO', 'convites_colaboradores', $id, 'Convite cancelado.'); resposta(array('ok' => true));
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
            auditLog($db, (int)$u['sub'], 'CONVITE_ENVIADO', 'convites_colaboradores', $id, 'Convite enviado por e-mail.');
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
            auditLog($db, (int)$u['sub'], 'PATROCINADOR_ATUALIZADO', 'patrocinadores', $id, 'Status: ' . $status . '; publicado: ' . $published . '.');
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
                    CONCAT(LEFT(p.cpf,3), '.***.***-', RIGHT(p.cpf,2)) AS cpf_masked,
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
                    CASE WHEN i.tipo='colaborador_mpl' THEN 'ISENTO' WHEN i.status IN ('paga','confirmada') THEN 'APPROVED' ELSE CASE pg.status
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
                 LEFT JOIN pagamentos_gateway pg
                   ON pg.inscricao_id = i.id
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

            if ($minAge < 0 || $maxAge < $minAge || $maxAge > 120) resposta(array('error' => 'Faixa etária inválida.'), 422);

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
                $id = (int)$db->lastInsertId();
            }

            auditLog($db, (int)$u['sub'], 'CATEGORIA_SALVA', 'categorias', $id, 'Categoria administrativa salva.');

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
                $id = (int)$db->lastInsertId();
            }

            if ($precoCentavos < 0) resposta(array('error' => 'O valor do lote não pode ser negativo.'), 422);
            $categoryCheck = $db->prepare('SELECT id FROM categorias WHERE id = ? AND corrida_id = ? LIMIT 1');
            $categoryCheck->execute(array($categoriaId, (int)$e['id']));
            if (!$categoryCheck->fetch()) resposta(array('error' => 'Categoria inválida para esta corrida.'), 422);
            if ($inicio && $fim && strtotime($inicio) !== false && strtotime($fim) !== false && strtotime($fim) <= strtotime($inicio)) resposta(array('error' => 'O fim do lote deve ser posterior ao início.'), 422);

            auditLog($db, (int)$u['sub'], 'LOTE_SALVO', 'lotes', $id, 'Lote administrativo salvo.');

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

            auditLog($db, (int)$u['sub'], 'CORRIDA_CONFIGURADA', 'corridas', (int)$e['id'], 'Configurações da corrida atualizadas.');

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

            if (!in_array($perfil, array('super_admin','admin','operador','financeiro','consulta'), true)) resposta(array('error' => 'Perfil inválido.'), 422);

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

            if (mb_strlen($nome) < 3 || mb_strlen($nome) > 160) resposta(array('error' => 'Informe um nome válido.'), 422);
            if (($id <= 0 && strlen($senha) < 10) || ($senha !== '' && strlen($senha) < 10)) resposta(array('error' => 'A senha deve ter pelo menos 10 caracteres.'), 422);
            if ($id === (int)$u['sub'] && (!$ativo || $perfil !== 'super_admin')) resposta(array('error' => 'Você não pode desativar ou reduzir o perfil da própria conta.'), 409);

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
                $id = (int)$db->lastInsertId();
            }

            auditLog($db, (int)$u['sub'], 'USUARIO_ADMIN_SALVO', 'usuarios_admin', $id, 'Usuário administrativo salvo com perfil ' . $perfil . '.');

            resposta([
                'ok' => true
            ]);
        }

        resposta([]);
    }

    if ($acao === 'search-registration') {
        $email = participantSessionEmail($db, true);
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
                CONCAT(LEFT(p.cpf,3), '.***.***-', RIGHT(p.cpf,2)) AS cpf,
                p.email,
                p.telefone AS phone,
                p.data_nascimento AS birth_date,
                p.tamanho_camiseta AS shirt_size,
                c.nome AS category_name,
                d.distancia_km,
                d.distancia_km AS distance_km,
                CASE WHEN i.tipo='colaborador_mpl' THEN 'ISENTO' WHEN i.status IN ('paga','confirmada') THEN 'APPROVED' ELSE CASE pg.status
                    WHEN 'PAID' THEN 'APPROVED'
                    WHEN 'FAILED' THEN 'DECLINED'
                    WHEN 'EXPIRED' THEN 'DECLINED'
                    WHEN 'CANCELLED' THEN 'DECLINED'
                    ELSE 'PENDING'
                END END AS payment_status,
                pg.id AS payment_id,
                pg.metodo AS payment_method,
                pg.order_id AS gateway_order_id,
                pg.status AS payment_local_status,
                pg.status_gateway AS gateway_status,
                pg.payment_link_url AS checkout_url,
                rk.retirado_em AS kit_at,
                ch.realizado_em AS checkin_at
             FROM inscricoes i
             JOIN participantes p ON p.id = i.participante_id
             JOIN categorias c ON c.id = i.categoria_id
             JOIN distancias d ON d.id = i.distancia_id
             LEFT JOIN pagamentos_gateway pg ON pg.inscricao_id = i.id
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
        $confirmed = in_array($registration['status'], ['paga', 'confirmada'], true);
        if ($confirmed) {
            $sent = enviarIngressoMpl($db, $config, $registrationId, $registration['tipo'] === 'colaborador_mpl');
            if (!$sent) {
                auditLog($db, null, 'EMAIL_INGRESSO_SOLICITADO_FALHOU', 'inscricoes', $registrationId, 'Falha no envio solicitado pela área Minha inscrição.');
                resposta(array('error' => mensagemFalhaEmailMpl('enviar o ingresso')), 503);
            }
            $db->prepare('UPDATE pagamentos_gateway SET email_confirmacao_em = COALESCE(email_confirmacao_em, NOW()), email_tentativa_em = NULL, ultimo_erro_email = NULL WHERE inscricao_id = ?')->execute(array($registrationId));
            auditLog($db, null, 'EMAIL_INGRESSO_SOLICITADO', 'inscricoes', $registrationId, 'Ingresso e QR Code enviados pela área Minha inscrição.');
            resposta(array('ok' => true, 'email_sent' => true, 'has_qr' => true));
        }

        $site = rtrim((string) ((isset($config['site_url']) ? $config['site_url'] : '')), '/');
        $message = "Olá, {$registration['nome_completo']}!\n\n"
            . "Acesse sua área da 4ª Corrida MPL pelo link:\n"
            . $site . '/minha-inscricao' . "\n\n"
            . "Número da inscrição: {$registration['numero']}\n"
            . "Categoria: {$registration['categoria_nome']}\n"
            . "Distância: {$registration['distancia_km']} km\n"
            . "Status: Pagamento pendente\n\n";
        $message .= "Se você não solicitou este acesso, ignore esta mensagem.\n\n4ª Corrida MPL";
        $sent = enviarEmailCorredor($config, $registration['email'], 'Seu acesso à inscrição, 4ª Corrida MPL', $message);
        if (!$sent) resposta(array('error' => mensagemFalhaEmailMpl('enviar o acesso')), 503);
        auditLog($db, null, 'EMAIL_ACESSO_SOLICITADO', 'inscricoes', $registrationId, 'Acesso enviado pela área Minha inscrição; pagamento ainda pendente.');
        resposta(array('ok' => true, 'email_sent' => true, 'has_qr' => false));
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
        if (!$sent) resposta(array('error' => mensagemFalhaEmailMpl('reenviar os dados')), 503);
        auditLog($db, null, 'EMAIL_INSCRICAO_REENVIADO', 'inscricoes', $registrationId, 'Dados da inscrição reenviados pela área Minha inscrição.');
        resposta(array('ok' => true, 'email_sent' => true));
    }

    if ($acao === 'create-registration') {
        $invitationToken = trim((string) (isset($dados['invitation_token']) ? $dados['invitation_token'] : ''));
        $isCollaborator = $invitationToken !== '';
        if ($isCollaborator && !preg_match('/^[a-f0-9]{64}$/', $invitationToken)) resposta(array('error' => 'Convite inválido.'), 404);

        $requiredRegistration = array('name','cpf','birth_date','gender','email','phone','shirt_size');
        foreach ($requiredRegistration as $field) {
            if (trim((string) (isset($dados[$field]) ? $dados[$field] : '')) === '') resposta(array('error' => 'Preencha todos os campos obrigatórios da inscrição.'), 422);
        }
        $acceptedValues = array('1', 'true', 'on', 'yes');
        $rulesAccepted = in_array(strtolower(trim((string) (isset($dados['rules_accepted']) ? $dados['rules_accepted'] : ''))), $acceptedValues, true);
        $lgpdAccepted = in_array(strtolower(trim((string) (isset($dados['lgpd_accepted']) ? $dados['lgpd_accepted'] : ''))), $acceptedValues, true);
        if (!$rulesAccepted || !$lgpdAccepted) resposta(array('error' => 'Aceite o regulamento e os termos da LGPD para continuar.'), 422);
        $hasEmergencyContact = in_array(strtolower(trim((string) (isset($dados['has_emergency_contact']) ? $dados['has_emergency_contact'] : ''))), $acceptedValues, true);
        $emergencyName = trim((string) (isset($dados['emergency_name']) ? $dados['emergency_name'] : ''));
        $emergencyPhone = trim((string) (isset($dados['emergency_phone']) ? $dados['emergency_phone'] : ''));
        $emergencyRelationship = trim((string) (isset($dados['emergency_relationship']) ? $dados['emergency_relationship'] : ''));
        if ($hasEmergencyContact && ($emergencyName === '' || strlen(preg_replace('/\D/', '', $emergencyPhone)) < 10)) resposta(array('error' => 'Confira o nome e o celular do contato de emergência.'), 422);
        $cpf = preg_replace('/\D/', '', (string) $dados['cpf']);
        $email = strtolower(trim((string) $dados['email']));
        $birth = DateTime::createFromFormat('Y-m-d', (string)$dados['birth_date']);
        $birthValid = $birth && $birth->format('Y-m-d') === (string)$dados['birth_date'] && $birth->getTimestamp() < time();
        $shirt = strtoupper(trim((string)$dados['shirt_size']));
        if (!cpfValido($cpf) || !filter_var($email, FILTER_VALIDATE_EMAIL)) resposta(array('error' => 'Confira o CPF e o e-mail informados.'), 422);
        if (!$birthValid || !in_array($shirt, array('PP','P','M','G','GG','XG','XGG'), true)) resposta(array('error' => 'Confira a data de nascimento e o tamanho da camiseta.'), 422);
        if (!in_array(strtoupper(trim((string)$dados['gender'])), array('F','M','OUTRO'), true)) resposta(array('error' => 'Selecione uma opção de gênero válida.'), 422);
        if (mb_strlen(trim((string)$dados['name'])) < 3 || strlen(preg_replace('/\D/','',(string)$dados['phone'])) < 10) resposta(array('error' => 'Confira o nome e o celular com DDD.'), 422);

        $db->beginTransaction();
        try {
            $invite = null;
            if ($isCollaborator) {
                $q = $db->prepare("SELECT cv.*, co.nome AS event_name, co.status AS event_status, ca.nome AS category_name, ca.distancia_id, ca.ativa AS category_active, ca.idade_minima, ca.idade_maxima, d.distancia_km FROM convites_colaboradores cv JOIN corridas co ON co.id=cv.corrida_id JOIN categorias ca ON ca.id=cv.categoria_id JOIN distancias d ON d.id=ca.distancia_id WHERE cv.token_hash=? FOR UPDATE");
                $q->execute(array(hash('sha256', $invitationToken))); $invite = $q->fetch();
                if (!$invite || $invite['cancelado_em'] || $invite['utilizado_em'] || strtotime($invite['expira_em']) <= time()) {
                    $db->rollBack(); resposta(array('error' => 'Este convite não está mais disponível.'), 410);
                }
                if ($invite['event_status'] !== 'publicada' || !(int) $invite['category_active']) {
                    $db->rollBack(); resposta(array('error' => 'A corrida ou categoria deste convite não está disponível.'), 409);
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

            $lotId = null;
            if ($isCollaborator) {
                $cat = array('id'=>(int)$invite['categoria_id'],'nome'=>$invite['category_name'],'distancia_id'=>(int)$invite['distancia_id'],'distancia_km'=>$invite['distancia_km'],'preco'=>0,'idade_minima'=>$invite['idade_minima'],'idade_maxima'=>$invite['idade_maxima']);
            } else {
                $q=$db->prepare("SELECT c.*,d.distancia_km FROM categorias c JOIN distancias d ON d.id=c.distancia_id WHERE c.corrida_id=? AND c.ativa=1 AND d.distancia_km=5 ORDER BY c.ordem,c.id LIMIT 1");
                $q->execute(array($eventId)); $cat=$q->fetch(); if(!$cat) throw new RuntimeException('A prova de 5 km não está disponível.');
                $q=$db->prepare("SELECT * FROM lotes WHERE corrida_id=? AND categoria_id=? AND ativo=1 AND (inicio IS NULL OR inicio<=NOW()) AND (fim IS NULL OR fim>=NOW()) AND (quantidade=0 OR vendidos<quantidade) ORDER BY inicio,id LIMIT 1 FOR UPDATE");
                $q->execute(array($eventId,(int)$cat['id'])); $lot=$q->fetch(); if(!$lot) throw new RuntimeException('Não há lote disponível para esta categoria.');
                $lotId=(int)$lot['id']; $cat['preco']=$lot['preco'];
            }

            $age = (int)$birth->diff(new DateTime('today'))->y;
            if ($age < (int)$cat['idade_minima'] || $age > (int)$cat['idade_maxima']) throw new RuntimeException('A idade não atende aos critérios da categoria selecionada.');

            $numero='MPL'.date('Y').str_pad((string)$eventId,2,'0',STR_PAD_LEFT).str_pad((string)$pid,7,'0',STR_PAD_LEFT);
            $status=$isCollaborator?'confirmada':'pendente_pagamento'; $type=$isCollaborator?'colaborador_mpl':'publico'; $value=$isCollaborator?0:$cat['preco'];
            $q=$db->prepare('INSERT INTO inscricoes (numero,corrida_id,participante_id,categoria_id,distancia_id,lote_id,status,tipo,valor) VALUES (?,?,?,?,?,?,?,?,?)');
            $q->execute(array($numero,$eventId,$pid,$cat['id'],$cat['distancia_id'],$lotId,$status,$type,$value)); $id=(int)$db->lastInsertId();

            $db->prepare('DELETE FROM contatos_emergencia WHERE participante_id=?')->execute(array($pid));
            if ($hasEmergencyContact) {
                $db->prepare('INSERT INTO contatos_emergencia (participante_id,nome,telefone,parentesco) VALUES (?,?,?,?)')->execute(array($pid,$emergencyName,$emergencyPhone,$emergencyRelationship !== '' ? $emergencyRelationship : null));
            }
            if (!$isCollaborator) {
                $q=$db->prepare('UPDATE lotes SET vendidos=vendidos+1 WHERE id=? AND (quantidade=0 OR vendidos<quantidade)'); $q->execute(array($lotId));
                if ($q->rowCount() !== 1) throw new RuntimeException('As vagas deste lote acabaram. Selecione outro lote.');
            }

            if ($isCollaborator) {
                $db->prepare("INSERT INTO pagamentos (inscricao_id,provedor,metodo,valor,status,pago_em) VALUES (?,'interno','isento',0,'isento',NOW())")->execute(array($id));
                garantirTicketMpl($db,$id);
                $q=$db->prepare('UPDATE convites_colaboradores SET utilizado_em=NOW(),participante_id=?,inscricao_id=?,atualizado_em=NOW() WHERE id=? AND utilizado_em IS NULL AND cancelado_em IS NULL AND expira_em>NOW()');
                $q->execute(array($pid,$id,(int)$invite['id'])); if($q->rowCount()!==1) throw new RuntimeException('O convite foi utilizado por outra inscrição.');
            }

            $db->commit();
            $participantToken = participantSessionCreate($db, $email);
            if ($isCollaborator) {
                $emailSent=enviarIngressoMpl($db,$config,$id,true);
            } else {
                $registrationEmail=array('nome_completo'=>$dados['name'],'email'=>$email,'numero'=>$numero,'categoria_nome'=>$cat['nome'],'distancia_km'=>$cat['distancia_km'],'valor'=>$value);
                $emailSent=enviarEmailCorredor($config,$email,'Inscrição recebida, 4ª Corrida MPL',emailInscricao($registrationEmail));
            }
            auditLog($db, null, 'INSCRICAO_CRIADA', 'inscricoes', $id, ($isCollaborator ? 'Inscrição de colaborador criada por convite. ' : 'Inscrição pública criada. ') . 'Regulamento e LGPD aceitos; contato de emergência ' . ($hasEmergencyContact ? 'informado.' : 'não informado.'));
            resposta(array('id'=>$id,'number'=>$numero,'email_sent'=>$emailSent,'type'=>strtoupper($type),'payment_status'=>$isCollaborator?'ISENTO':'PENDING','is_collaborator'=>$isCollaborator,'participant_token'=>$participantToken,'participant_email'=>$email));
        } catch (Exception $z) {
            if ($db->inTransaction()) $db->rollBack();
            throw $z;
        }
    }

    if ($acao === 'generate-ticket') {
        $id = (int) (
            (isset($dados['registration_id']) ? $dados['registration_id'] : 0)
        );

        if ($id <= 0) {
            resposta(['error' => 'Inscrição inválida.'], 422);
        }

        $admin = usuarioAtual($config);
        if (!$admin) {
            $participantEmail = participantSessionEmail($db, true);
            $ownerQuery = $db->prepare('SELECT i.id FROM inscricoes i JOIN participantes p ON p.id = i.participante_id WHERE i.id = ? AND LOWER(TRIM(p.email)) = ? LIMIT 1');
            $ownerQuery->execute(array($id, $participantEmail));
            if (!$ownerQuery->fetch()) resposta(array('error' => 'Você não tem acesso a esta inscrição.'), 403);
        }

        // O ticket só é emitido após a confirmação do pagamento.
        $statusQuery = $db->prepare(
            "SELECT i.status, pg.status AS pagamento
             FROM inscricoes i
             LEFT JOIN pagamentos_gateway pg ON pg.inscricao_id = i.id
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

    if (in_array($acao, ['card-hash-key', 'create-payment', 'payment-status', 'belluno-webhook'], true)) {
        resposta(['error' => 'Integração Belluno desativada. Utilize o Checkout Pagar.me/Stone.'], 410);
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
                CONCAT(LEFT(p.cpf,3), '.***.***-', RIGHT(p.cpf,2)) AS cpf_masked,
                c.nome AS category_name,
                p.tamanho_camiseta AS shirt_size,
                t.token,
                rk.retirado_em AS kit_at,
                ch.realizado_em AS checkin_at,
                UPPER(i.tipo) AS type,
                CASE
                    WHEN i.tipo='colaborador_mpl' THEN 'ISENTO'
                    WHEN i.status IN ('paga','confirmada') OR pg.status = 'PAID' THEN 'APPROVED'
                    WHEN pg.status IN ('FAILED','EXPIRED','CANCELLED') THEN 'DECLINED'
                    ELSE 'PENDING'
                END AS payment_status
             FROM tickets t
             JOIN inscricoes i
               ON i.id = t.inscricao_id
             JOIN participantes p
               ON p.id = i.participante_id
             JOIN categorias c
               ON c.id = i.categoria_id
             LEFT JOIN pagamentos_gateway pg
               ON pg.inscricao_id = i.id
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
                        pg.status AS pagamento_status,
                        rk.status AS retirada_status
                 FROM inscricoes i
                 LEFT JOIN pagamentos_gateway pg ON pg.inscricao_id = i.id
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
                "SELECT i.status AS inscricao_status, pg.status AS pagamento_status
                 FROM inscricoes i
                 LEFT JOIN pagamentos_gateway pg ON pg.inscricao_id = i.id
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


        auditLog($db, (int)$u['sub'], $acao === 'withdraw-kit' ? 'KIT_RETIRADO' : 'CHECKIN_REALIZADO', $acao === 'withdraw-kit' ? 'retiradas_kit' : 'checkins', $id, 'Operação registrada no painel administrativo.');

        resposta([
            'ok' => true
        ]);
    }

    resposta([
        'error' => 'Ação desconhecida.'
    ], 404);

} catch (Exception $e) {
    apiHandleException($config, $e);
}

