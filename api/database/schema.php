<?php
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
        "CREATE TABLE IF NOT EXISTS pagamentos_gateway (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            inscricao_id INT UNSIGNED NOT NULL,
            metodo ENUM('CHECKOUT','PIX','CARD') NOT NULL DEFAULT 'CHECKOUT',
            payment_link_id VARCHAR(120) NULL,
            payment_link_url VARCHAR(500) NULL,
            order_id VARCHAR(120) NULL,
            referencia_externa VARCHAR(160) NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            status ENUM('PROCESSING','PAID','FAILED','EXPIRED','CANCELLED','REFUNDED') NOT NULL DEFAULT 'PROCESSING',
            status_gateway VARCHAR(80) NULL,
            checkout_expira_em DATETIME NULL,
            confirmado_em DATETIME NULL,
            email_confirmacao_em DATETIME NULL,
            email_tentativa_em DATETIME NULL,
            tentativas_email SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ultimo_erro_email VARCHAR(255) NULL,
            erro_tecnico VARCHAR(1000) NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pg_inscricao (inscricao_id),
            UNIQUE KEY uq_pg_link (payment_link_id),
            KEY idx_pg_referencia (referencia_externa),
            CONSTRAINT fk_pg_inscricao FOREIGN KEY (inscricao_id) REFERENCES inscricoes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $columns = array();
    foreach ($db->query('SHOW COLUMNS FROM pagamentos_gateway')->fetchAll() as $column) $columns[(string)$column['Field']] = true;
    if (empty($columns['email_tentativa_em'])) $db->exec('ALTER TABLE pagamentos_gateway ADD COLUMN email_tentativa_em DATETIME NULL AFTER email_confirmacao_em');
    if (empty($columns['tentativas_email'])) $db->exec('ALTER TABLE pagamentos_gateway ADD COLUMN tentativas_email SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER email_tentativa_em');
    if (empty($columns['ultimo_erro_email'])) $db->exec('ALTER TABLE pagamentos_gateway ADD COLUMN ultimo_erro_email VARCHAR(255) NULL AFTER tentativas_email');
}

function ensureConvitesColaboradoresSchema($db) {
    $registrationColumns = array();
    foreach ($db->query('SHOW COLUMNS FROM inscricoes')->fetchAll() as $column) $registrationColumns[(string)$column['Field']] = true;
    if (empty($registrationColumns['tipo'])) $db->exec("ALTER TABLE inscricoes ADD COLUMN tipo VARCHAR(30) NOT NULL DEFAULT 'publico' AFTER status");
    $ticketColumns = array();
    foreach ($db->query('SHOW COLUMNS FROM tickets')->fetchAll() as $column) $ticketColumns[(string)$column['Field']] = true;
    if (empty($ticketColumns['qr_code_data'])) $db->exec("ALTER TABLE tickets ADD COLUMN qr_code_data VARCHAR(255) NULL AFTER token");
    $paymentColumns = array();
    foreach ($db->query('SHOW COLUMNS FROM pagamentos')->fetchAll() as $column) $paymentColumns[(string)$column['Field']] = (string)$column['Type'];
    if (isset($paymentColumns['metodo']) && strpos($paymentColumns['metodo'], "'isento'") === false) $db->exec("ALTER TABLE pagamentos MODIFY metodo ENUM('pix','cartao','boleto','outro','isento') NOT NULL DEFAULT 'pix'");
    if (isset($paymentColumns['status']) && strpos($paymentColumns['status'], "'isento'") === false) $db->exec("ALTER TABLE pagamentos MODIFY status ENUM('pendente','processando','aprovado','recusado','expirado','cancelado','estornado','isento') NOT NULL DEFAULT 'pendente'");
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

function ensureSecuritySchema($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS participante_codigos_acesso (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(180) NOT NULL,
        codigo_hash CHAR(64) NOT NULL,
        expira_em DATETIME NOT NULL,
        tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
        utilizado_em DATETIME NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_codigo_email (email, criado_em),
        KEY idx_codigo_expira (expira_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS participante_sessoes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash CHAR(64) NOT NULL,
        email VARCHAR(180) NOT NULL,
        expira_em DATETIME NOT NULL,
        revogado_em DATETIME NULL,
        ultimo_acesso_em DATETIME NULL,
        criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_participante_token (token_hash),
        KEY idx_sessao_email (email),
        KEY idx_sessao_expira (expira_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS limites_requisicao (
        chave_hash CHAR(64) NOT NULL,
        acao VARCHAR(60) NOT NULL,
        janela_inicio DATETIME NOT NULL,
        tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        bloqueado_ate DATETIME NULL,
        atualizado_em DATETIME NOT NULL,
        PRIMARY KEY (chave_hash),
        KEY idx_limite_atualizado (atualizado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

