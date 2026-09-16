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
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pagamentos_belluno_inscricao (inscricao_id),
    UNIQUE KEY uq_pagamentos_belluno_transaction (transaction_id),
    KEY idx_pagamentos_belluno_status (status),
    KEY idx_pagamentos_belluno_referencia (referencia_externa),
    CONSTRAINT fk_pagamentos_belluno_inscricao FOREIGN KEY (inscricao_id) REFERENCES inscricoes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
