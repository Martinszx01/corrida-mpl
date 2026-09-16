-- Corrige a instalação antiga que criou admin_users.
-- Execute no banco corrida_mpl antes de usar o pacote novo.
USE corrida_mpl;
CREATE TABLE IF NOT EXISTS usuarios_admin (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nome VARCHAR(150) NOT NULL,
 email VARCHAR(180) NOT NULL UNIQUE,
 senha_hash VARCHAR(255) NOT NULL,
 perfil VARCHAR(30) NOT NULL DEFAULT 'consulta',
 ativo TINYINT(1) NOT NULL DEFAULT 1,
 ultimo_login_em DATETIME NULL,
 criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Se você já tinha usuários na tabela antiga, copie os registros para o modelo em português:
INSERT IGNORE INTO usuarios_admin (id,nome,email,senha_hash,perfil,ativo,ultimo_login_em)
SELECT id,name,email,password_hash,
 CASE LOWER(role)
  WHEN 'super_admin' THEN 'super_admin'
  WHEN 'admin' THEN 'admin'
  WHEN 'operator' THEN 'operador'
  WHEN 'financial' THEN 'financeiro'
  ELSE 'consulta'
 END,
 is_active,last_login_at
FROM admin_users;
