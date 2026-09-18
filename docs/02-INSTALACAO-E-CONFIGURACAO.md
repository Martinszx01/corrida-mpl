# Instalação e configuração

## Requisitos

| Componente | Requisito do projeto |
|---|---|
| Servidor | Apache 2.4 com `mod_rewrite` e `mod_headers` |
| PHP | 5.5 ou superior, preservando compatibilidade com 5.5 |
| Extensões PHP | `pdo`, `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo` e `zlib` |
| Banco | MariaDB 5.5.62, InnoDB e `utf8mb4` |
| E-mail | Saída SMTP com TLS/SSL ou função `mail()` configurada |
| Rede | HTTPS para o público e saída HTTPS para `api.pagar.me` |

Não há Composer, npm, Node.js ou etapa de compilação. As bibliotecas necessárias estão em `vendor` e `assets/vendor`.

## Instalação inicial

1. Publique todos os arquivos no diretório configurado no Apache.
2. Habilite `AllowOverride All` para que o `.htaccess` seja aplicado.
3. Crie o banco com `database/mariadb.sql` usando uma conta de implantação.
4. Crie um usuário MariaDB exclusivo da aplicação e conceda somente os privilégios necessários.
5. Copie `.env.example` para `.env` e preencha os valores do ambiente.
6. Garanta escrita do usuário do Apache somente em `storage` e `assets/images/sponsors`.
7. Configure o VirtualHost com HTTPS e a URL pública indicada em `SITE_URL`.
8. Valide `GET/POST api.php?action=health`, a página pública e o login administrativo.

O arquivo `.env` deve permanecer na raiz, fora do versionamento e bloqueado pelo Apache. Ele não deve ser incluído em chamados, documentação, capturas ou pacotes destinados a terceiros.

## Variáveis de ambiente

| Variável | Obrigatória | Finalidade |
|---|---:|---|
| `APP_DEBUG` | sim | `0` em produção; detalhes de exceção somente em desenvolvimento |
| `SITE_URL` | sim | URL canônica completa, incluindo subdiretório quando houver |
| `CORS_ORIGIN` | sim em produção | Origem autorizada; não usar `*` em produção |
| `DB_HOST`, `DB_PORT` | sim | Servidor e porta MariaDB |
| `DB_DATABASE`, `DB_USER`, `DB_PASSWORD` | sim | Banco e credencial exclusiva da aplicação |
| `JWT_SECRET` | sim | Segredo aleatório com pelo menos 64 caracteres |
| `ALLOW_BOOTSTRAP_ADMIN` | sim | Deve permanecer `0` após provisionar o primeiro administrador |
| `PAGARME_SECRET_KEY` | sim para cobrança | Chave secreta da conta Pagar.me; nunca vai ao navegador |
| `PAGARME_BASE_URL` | sim | Base da API Core v5 |
| `PAGARME_CHECKOUT_EXPIRES_MINUTES` | não | Entre 15 e 1440 minutos |
| `PAGARME_MAX_INSTALLMENTS` | não | Entre 1 e 12; o exemplo usa 3 |
| `MAIL_ENABLED` | sim | Ativa mensagens transacionais |
| `MAIL_FROM` | sim | Remetente válido do domínio |
| `MAIL_TRANSPORT` | sim | `smtp` ou `mail` |
| `MAIL_SMTP_*` | para SMTP | Host, porta, criptografia, usuário, senha e timeout |
| `MAIL_WORKER_SECRET` | sim | Segredo de ao menos 32 caracteres para o worker de reenvio |
| `INVITATION_EXPIRES_HOURS` | não | Validade dos convites; padrão 168 horas |

## Banco e permissões

Use uma conta administrativa apenas para importar e migrar o schema. O código atual executa garantias de schema em runtime e, por isso, ainda requer `CREATE` e `ALTER` para a conta da aplicação. Isso é uma dívida técnica: após transformar essas garantias em migrações versionadas, reduza a conta para `SELECT`, `INSERT`, `UPDATE` e `DELETE` no schema da aplicação.

## Administrador inicial

`database/seed_admin.sql` contém uma credencial inicial conhecida e deve ser tratado apenas como material de bootstrap local. Antes de produção, gere outro hash, troque a senha no primeiro acesso, remova o arquivo do pacote publicado e mantenha `ALLOW_BOOTSTRAP_ADMIN=0`.

## Desenvolvimento local sem Node.js

No XAMPP, acesse o diretório pelo Apache, por exemplo `http://localhost/corrida-mpl-php-js-html-css/`. Como alternativa, o roteador incluído permite `php -S 127.0.0.1:8080 router.php`. O servidor embutido é somente para desenvolvimento e não substitui Apache/HTTPS.

## Verificação mínima

```powershell
php -l index.php
php -l api.php
php -l pagarme_checkout.php
php -l mail_service.php
```

Depois, confirme pelo navegador: assets sem 404, CSP sem bloqueios inesperados, criação de inscrição, retorno do Checkout, acesso do participante, leitura de QR, retirada e check-in.
