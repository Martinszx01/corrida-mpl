# Visão geral e arquitetura

## Contexto funcional

O sistema atende uma única edição de evento identificada pelo slug `4-corrida-mpl`. A experiência pública oferece informações da corrida, prova fixa de 5 km, inscrição, pagamento, consulta do ingresso e patrocínio. A experiência administrativa cobre configuração, participantes, colaboradores convidados, lotes, patrocinadores, relatórios, retirada de kit, check-in e usuários.

Embora a interface exponha somente a prova de 5 km, o banco mantém `distancias` e `categorias` por compatibilidade histórica. Na inscrição pública, a categoria ativa de 5 km é escolhida no backend; o navegador não define gratuidade, tipo ou valor.

## Diagrama de componentes

```mermaid
flowchart LR
    U[Participante] --> A[Apache / index.php]
    O[Operador ou ADM] --> A
    A --> F[app.js SPA]
    F -->|POST JSON action| API[api.php]
    API --> C[api/core]
    API --> S[api/services]
    API --> DB[(MariaDB 5.5)]
    API --> PG[Pagar.me / Stone]
    API --> SMTP[SMTP ou mail()]
    S --> QR[PHP QR Code]
    F --> SCAN[html5-qrcode]
```

## Ciclo de uma requisição

1. O Apache entrega `index.php` para rotas amigáveis por meio do `.htaccess`.
2. `index.php` carrega `config.php`, calcula `base_url`, publica apenas configurações não sensíveis em `window.MPL_CONFIG` e aplica CSP.
3. `app.js` resolve a rota no cliente e chama `api.php?action=<nome>` com JSON.
4. `api.php` carrega `api/core/bootstrap.php`, abre PDO sob demanda e executa as verificações de schema existentes.
5. O dispatcher valida entrada, sessão e perfil, executa SQL preparado e encerra por `resposta()`.
6. Exceções são registradas com identificador de incidente; detalhes só aparecem quando `APP_DEBUG=1`.

## Estrutura do backend

```text
api.php                         compatibilidade e dispatcher atual
api/index.php                   nova entrada equivalente
api/config/config.php           ponte para a configuração raiz
api/core/bootstrap.php          ordem de carregamento
api/core/database.php           PDO
api/core/response.php           entrada JSON e saída JSON
api/core/auth.php               JWT e autorização administrativa
api/core/participant-auth.php   sessão do participante
api/core/security.php           rate limit, auditoria, IP e CPF
api/core/error-handler.php      exceções e incidentes
api/database/schema.php         garantias de schema existentes
api/services/event.php          corrida e categorias administrativas
api/services/registration.php   propriedade da inscrição
api/services/invitation.php     tokens de convite
api/services/ticket.php         token do ingresso
api/services/qrcode.php         PNG do QR Code
api/services/mail.php           mensagens transacionais
pagarme_checkout.php            Checkout, consulta e webhook Pagar.me
mail_service.php                transporte SMTP/mail
```

## Dependências entre módulos

- `bootstrap.php` carrega configuração, response, banco, schema, segurança, sessões, serviços, autenticação e tratamento de erro nessa ordem.
- `auth.php` depende de `database.php` e `response.php`.
- `participant-auth.php` depende de PDO e response.
- `mail.php` depende de `mail_service.php`, `ticket.php` e `qrcode.php`.
- `pagarme_checkout.php` depende de propriedade da inscrição, rate limit, ticket, QR, e-mail e auditoria.
- `api.php` ainda contém todas as actions. A modularização dos endpoints é trabalho futuro documentado no roadmap.

## Frontend

O frontend é uma SPA sem framework. O PHP fornece somente o shell; `app.js` constrói as telas, usa History API e mantém estado em memória e `sessionStorage`. CSS base e ajustes institucionais estão em `style.css` e `ux-modern.css`. Bibliotecas de QR ficam vendorizadas em `assets/vendor`.

## Roteamento e hospedagem

- `.htaccess` preserva arquivos e diretórios reais e envia outras rotas para `index.php`.
- Cada diretório de rota contém um `index.php` compatível com acesso direto pelo Apache.
- `router.php` emula o rewrite para `php -S` durante desenvolvimento.
- O projeto funciona na raiz ou em subdiretório; `index.php` calcula o prefixo usando `DOCUMENT_ROOT`.

## Compatibilidade PHP 5.5

`compat.php` fornece implementações condicionais de `hash_equals`, `random_bytes`, `str_starts_with` e `str_ends_with`. Não devem ser introduzidos tipos escalares, retorno tipado, attributes, match, arrow functions ou sintaxe exclusiva de PHP moderno.

## Limites arquiteturais atuais

- O dispatcher de endpoints ainda está concentrado em `api.php`.
- `pagarme_checkout.php` concentra cliente HTTP, persistência, webhook e envio de ingresso.
- Verificações `CREATE/ALTER` são executadas durante a inicialização da API.
- Não há suíte automatizada versionada; a homologação é majoritariamente por lint, HTTP e fluxos manuais.
