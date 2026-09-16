USE corrida_mpl;

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
