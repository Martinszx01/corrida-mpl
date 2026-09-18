# Notas de segurança para etapa posterior

Estas observações não foram corrigidas nesta refatoração para preservar o comportamento atual.

- A API executa `CREATE TABLE` e alguns `ALTER TABLE` durante a inicialização. Isso exige privilégios de alteração no usuário do banco e aumenta o impacto de uma credencial comprometida.
- O valor padrão de CORS é `*` quando `CORS_ORIGIN` não está configurado. Produção deve definir explicitamente a origem permitida.
- Permanecem nomes de actions legadas da Belluno, mas elas retornam HTTP 410 e não processam pagamentos. A integração ativa continua sendo Pagar.me/Stone.
- O endpoint inicial de criação de administrador permanece condicionado a `ALLOW_BOOTSTRAP_ADMIN`, como na implementação existente.
