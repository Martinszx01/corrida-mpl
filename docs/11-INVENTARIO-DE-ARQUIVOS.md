# Inventário de arquivos e responsabilidades

Este inventário descreve os artefatos próprios do projeto. Imagens são agrupadas por finalidade e a biblioteca de terceiros é tratada como unidade para evitar confundir código vendorizado com código MPL.

## Raiz

| Arquivo | Responsabilidade |
|---|---|
| `.env.example` | modelo de configuração sem credenciais reais |
| `.gitignore` | impede versionamento de segredos, logs e ZIPs |
| `.htaccess` | bloqueios, headers e rewrite da SPA |
| `index.php` | documento HTML, CSP, SEO e configuração pública |
| `app.js` | SPA completa, cliente da API e interação das telas |
| `style.css` | identidade e componentes visuais principais |
| `ux-modern.css` | ajustes institucionais e responsivos adicionais |
| `config.php` | parser de `.env` e configuração central |
| `compat.php` | polyfills necessários no PHP 5.5 |
| `api.php` | dispatcher e actions preservados por compatibilidade |
| `pagarme_checkout.php` | cliente Core v5, Payment Link, webhook e worker |
| `mail_service.php` | transporte SMTP/mail |
| `router.php` | roteamento no servidor embutido PHP |
| `README.md` | entrada da documentação |
| `SECURITY_NOTES.md` | notas curtas da refatoração que afetam segurança |

O `.env` real existe apenas no ambiente e não faz parte do inventário versionado.

## Backend modular `api/`

| Caminho | Responsabilidade |
|---|---|
| `api/index.php` | entrada alternativa que delega ao contrato atual |
| `api/config/config.php` | ponte para configuração raiz |
| `api/core/bootstrap.php` | carrega módulos na ordem necessária |
| `api/core/response.php` | lê JSON e encerra resposta JSON |
| `api/core/database.php` | conexão PDO |
| `api/core/security.php` | IP, CPF, rate limiting e auditoria |
| `api/core/auth.php` | JWT e autorização administrativa |
| `api/core/participant-auth.php` | sessão e propriedade do participante |
| `api/core/error-handler.php` | resposta segura para exceções |
| `api/database/schema.php` | compatibilidade incremental do schema |
| `api/services/event.php` | dados e confirmação do evento |
| `api/services/registration.php` | propriedade/consulta de inscrição |
| `api/services/invitation.php` | token, criptografia e link de convite |
| `api/services/ticket.php` | criação idempotente do ticket |
| `api/services/qrcode.php` | renderização PNG do QR |
| `api/services/mail.php` | templates e chamadas de mensagens |
| `api/REFACTOR_MAP.md` | mapa entre funções extraídas e destino |

## Rotas públicas

`inscricao/index.php`, `minha-inscricao/index.php`, `consultar-inscricao/index.php`, `pagamento/index.php`, `pagamento/sucesso/index.php`, `patrocinio/index.php`, `privacidade/index.php`, `regulamento/index.php` e `auth/atualizar-senha/index.php` carregam o shell raiz com o caminho correto. A interface real é renderizada por `app.js`.

## Rotas administrativas

`admin/index.php` e as entradas `categorias`, `checkin`, `colaboradores`, `configuracoes`, `inscricoes`, `login`, `lotes`, `patrocinadores`, `relatorios`, `retirada-kit` e `usuarios` usam o mesmo shell. Autorização não depende desses arquivos; é aplicada na API.

## Banco

| Arquivo | Uso |
|---|---|
| `database/mariadb.sql` | instalação consolidada e evento mínimo |
| `database/20260911_patrocinadores.sql` | migração histórica do módulo de patrocínio |
| `database/20260916_convites_colaboradores.sql` | migração histórica de convites |
| `database/atualizar_lotes_categoria.sql` | compatibilidade para instalações sem categoria no lote |
| `database/corrigir_admin_users.sql` | migra tabela legada `admin_users` para `usuarios_admin` |
| `database/seed_admin.sql` | bootstrap local com credencial conhecida; não publicar |

Para instalação nova, use o consolidado. Scripts históricos só devem ser executados após comparar o schema do ambiente; repetir migrações fora de ordem pode produzir divergência.

## Assets

| Diretório | Conteúdo |
|---|---|
| `assets/images/banners` | imagens principais e metadado social |
| `assets/images/event` | cartazes e foto institucional do evento |
| `assets/images/gallery` | fontes originais da galeria |
| `assets/images/gallery/web` | nove versões otimizadas usadas no frontend |
| `assets/images/logo` | favicon, logo geral e logo do checkout |
| `assets/images/sponsors/editions` | artes das três edições anteriores |
| `assets/images/sponsors` | arte consolidada e uploads publicados |
| `assets/vendor/qrcode.min.js` | geração de QR no navegador quando necessária |
| `assets/vendor/html5-qrcode.min.js` | leitura de QR pela câmera |

Uploads administrativos recebem nome aleatório dentro de `assets/images/sponsors`; arquivos sem referência devem ser removidos somente após auditoria no banco.

## Terceiros

`vendor/phpqrcode` é a biblioteca PHP QR Code incorporada ao projeto, com licença própria em `vendor/phpqrcode/LICENSE`. Não altere internamente durante manutenção comum. Atualizações exigem teste de compatibilidade PHP 5.5, comparação visual e leitura dos QR gerados.

## Arquivos gerados em runtime

`storage` pode receber logs e segredos operacionais e está bloqueado por Apache e ignorado pelo Git. ZIPs, dumps e relatórios exportados não devem permanecer no webroot. A pasta de upload de patrocinadores é exceção porque os arquivos precisam ser públicos, mas aceita somente imagens validadas.
