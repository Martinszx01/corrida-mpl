-- =====================================================================
-- 4ª Corrida MPL - Estrutura completa do banco
-- Compatível com MariaDB 5.5.62 / MySQL 5.5
--   * DATETIME nunca usa DEFAULT CURRENT_TIMESTAMP (não suportado no 5.5)
--   * Apenas UMA coluna TIMESTAMP por tabela usa CURRENT_TIMESTAMP
--   * Colunas de atualização automática usam NOW() explícito no backend
-- O banco pode iniciar vazio: os dados de negócio (categorias, lotes,
-- valores, etc.) são criados pelo painel administrativo.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS corrida_mpl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE corrida_mpl;

CREATE TABLE IF NOT EXISTS corridas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    edicao INT UNSIGNED DEFAULT 4,
    data_corrida DATE NOT NULL,
    horario_largada TIME DEFAULT '07:00:00',
    local_corrida VARCHAR(255),
    cidade VARCHAR(100),
    inscricoes_abertas TINYINT(1) DEFAULT 0,
    status VARCHAR(30) DEFAULT 'publicada',
    regulamento TEXT,
    aviso_privacidade TEXT,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS distancias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    corrida_id INT UNSIGNED NOT NULL,
    nome VARCHAR(80) NOT NULL,
    distancia_km DECIMAL(6,2) NOT NULL,
    ativa TINYINT(1) DEFAULT 1,
    KEY idx_distancias_corrida (corrida_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    corrida_id INT UNSIGNED NOT NULL,
    nome VARCHAR(100) NOT NULL,
    genero VARCHAR(30) DEFAULT 'misto',
    idade_minima INT DEFAULT 0,
    idade_maxima INT DEFAULT 120,
    distancia_id INT UNSIGNED NOT NULL,
    ativa TINYINT(1) DEFAULT 1,
    ordem INT DEFAULT 0,
    KEY idx_categorias_corrida (corrida_id),
    KEY idx_categorias_distancia (distancia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lotes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    corrida_id INT UNSIGNED NOT NULL,
    categoria_id INT UNSIGNED NULL,
    nome VARCHAR(100) NOT NULL,
    preco DECIMAL(10,2) DEFAULT 0,
    quantidade INT DEFAULT 0,
    vendidos INT DEFAULT 0,
    inicio DATETIME NULL,
    fim DATETIME NULL,
    ativo TINYINT(1) DEFAULT 1,
    KEY idx_lotes_corrida (corrida_id),
    KEY idx_lotes_categoria (categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participantes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome_completo VARCHAR(180) NOT NULL,
    email VARCHAR(180) NOT NULL,
    cpf VARCHAR(14) NOT NULL UNIQUE,
    telefone VARCHAR(30),
    data_nascimento DATE NULL,
    genero VARCHAR(30),
    tamanho_camiseta VARCHAR(10),
    consentiu_lgpd TINYINT(1) DEFAULT 0,
    consentiu_lgpd_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enderecos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    cep VARCHAR(9),
    rua VARCHAR(180),
    numero VARCHAR(20),
    complemento VARCHAR(100),
    bairro VARCHAR(100),
    cidade VARCHAR(100),
    estado CHAR(2),
    KEY idx_enderecos_participante (participante_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contatos_emergencia (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    nome VARCHAR(180) NOT NULL,
    telefone VARCHAR(30) NOT NULL,
    parentesco VARCHAR(60),
    KEY idx_contatos_participante (participante_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inscricoes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(30) UNIQUE NOT NULL,
    corrida_id INT UNSIGNED NOT NULL,
    participante_id INT UNSIGNED NOT NULL,
    categoria_id INT UNSIGNED NOT NULL,
    distancia_id INT UNSIGNED NOT NULL,
    lote_id INT UNSIGNED NULL,
    status VARCHAR(30) DEFAULT 'pendente_pagamento',
    tipo VARCHAR(30) DEFAULT 'publico',
    valor DECIMAL(10,2) DEFAULT 0,
    criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizada_em TIMESTAMP NULL DEFAULT NULL,
    KEY idx_inscricoes_corrida (corrida_id),
    KEY idx_inscricoes_participante (participante_id),
    KEY idx_inscricoes_categoria (categoria_id),
    KEY idx_inscricoes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagamentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inscricao_id INT UNSIGNED NOT NULL,
    provedor VARCHAR(50),
    metodo VARCHAR(30) DEFAULT 'pix',
    valor DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(30) DEFAULT 'pendente',
    id_externo VARCHAR(150),
    pago_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pagamentos_inscricao (inscricao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inscricao_id INT UNSIGNED NOT NULL UNIQUE,
    token VARCHAR(100) UNIQUE NOT NULL,
    qr_code_data VARCHAR(255) NOT NULL,
    status VARCHAR(20) DEFAULT 'ativo',
    gerado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retiradas_kit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inscricao_id INT UNSIGNED NOT NULL UNIQUE,
    ticket_id INT UNSIGNED NOT NULL,
    status VARCHAR(20) DEFAULT 'pendente',
    retirado_em DATETIME NULL,
    operador_id INT UNSIGNED NULL,
    local_retirada VARCHAR(150)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inscricao_id INT UNSIGNED NOT NULL UNIQUE,
    ticket_id INT UNSIGNED NOT NULL,
    corrida_id INT UNSIGNED NOT NULL,
    realizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    operador_id INT UNSIGNED NULL,
    KEY idx_checkins_corrida (corrida_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios_admin (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(180) UNIQUE NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    perfil VARCHAR(30) DEFAULT 'consulta',
    ativo TINYINT(1) DEFAULT 1,
    ultimo_login_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs_auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    acao VARCHAR(80) NOT NULL,
    tabela_afetada VARCHAR(80),
    registro_id INT UNSIGNED NULL,
    descricao TEXT,
    ip VARCHAR(45),
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS convites_colaboradores (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ledger próprio da integração Belluno.
-- Não armazena número de cartão, CVV, validade ou card_hash.
CREATE TABLE IF NOT EXISTS pagamentos_belluno (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solicitações e publicação de patrocinadores da 4ª Corrida MPL.
CREATE TABLE IF NOT EXISTS patrocinadores (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuração mínima obrigatória: a corrida/evento usada pelo sistema.
-- (Categorias, lotes e valores são cadastrados pelo painel administrativo.)
INSERT INTO corridas (nome, slug, edicao, data_corrida, horario_largada, status, inscricoes_abertas)
SELECT '4ª Corrida MPL', '4-corrida-mpl', 4, '2026-11-29', '07:00:00', 'publicada', 0
WHERE NOT EXISTS (SELECT 1 FROM corridas WHERE slug = '4-corrida-mpl');
