-- =====================================================================
-- 4ª Corrida MPL - Administrador inicial
-- Compatível com MariaDB 5.5.62
-- Cria o primeiro super administrador (idempotente).
--
-- Login inicial:
--   E-mail:  j.vmartins0204@gmail.com
--   Senha:   Jv020405!
--
-- IMPORTANTE: o hash abaixo foi gerado com password_hash()/bcrypt.
-- Troque a senha pelo painel após o primeiro acesso.
-- Para gerar um novo hash: abra senha_hash.php no navegador.
-- =====================================================================

USE corrida_mpl;

INSERT INTO usuarios_admin (nome, email, senha_hash, perfil, ativo)
SELECT 'Administrador MPL', 'j.vmartins0204@gmail.com',
       '$2y$10$qFLqszDFvBmUz7bXRomLMebdtAeZTkG/Ty1fAp4ckqum.9VFWRYg2',
       'super_admin', 1
WHERE NOT EXISTS (
    SELECT 1 FROM usuarios_admin WHERE email = 'j.vmartins0204@gmail.com'
);
