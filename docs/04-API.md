# Contrato da API

## Convenções

- Entrada principal atual: `POST /api.php?action=<ação>`.
- Entrada modular equivalente: `POST /api/index.php?action=<ação>`.
- Corpo padrão: JSON UTF-8; upload de logo usa `multipart/form-data`.
- Resposta padrão: JSON. Sucesso usa o status coerente com a operação; erro usa `{ "error": "mensagem" }`.
- A API aceita `OPTIONS` para preflight. Em produção, `CORS_ORIGIN` deve conter a origem exata.
- Nunca registrar `Authorization`, token de participante, senha, chave Pagar.me ou código de acesso.

## Autenticação

Administração usa `Authorization: Bearer <jwt>` ou `X-MPL-Token`. O JWT expira em 24 horas e cada requisição confirma que usuário, e-mail, perfil e flag ativo ainda correspondem ao banco.

Participante usa `X-MPL-Participant-Token`. O token aleatório só é entregue ao cliente; o banco armazena SHA-256. A sessão dura 12 horas, pode ser revogada e é vinculada ao e-mail proprietário das inscrições.

## Endpoints públicos

| Action | Entrada essencial | Resultado |
|---|---|---|
| `health` | nenhuma | disponibilidade da aplicação |
| `diagnostico` | nenhuma | diagnóstico controlado do ambiente |
| `public-event` | slug configurado | corrida, prova de 5 km, lote e estado de inscrição |
| `public-sponsors` | evento | patrocinadores aprovados e publicados |
| `sponsor-interest` | empresa, contato, e-mail, telefone, modalidade | registra proposta de patrocínio |
| `invitation-info` | `token` de 64 hex | dados públicos de convite válido |
| `create-registration` | dados pessoais e aceites; token opcional de convite | cria inscrição comum ou colaborador |
| `request-participant-access` | `email` | envia código temporário se houver inscrição, sem enumerar conta |
| `verify-participant-access` | `email`, `code` | cria sessão do participante |
| `participant-logout` | token no header | revoga sessão |

Campos da inscrição pública: `name`, `cpf`, `birth_date`, `gender`, `email`, `phone`, `shirt_size`, `rules_accepted`, `lgpd_accepted`, `has_emergency_contact` e, quando habilitado, `emergency_name`, `emergency_phone`, `emergency_relationship`. Categoria, tipo e valor são calculados no servidor.

## Área do participante

| Action | Autorização | Finalidade |
|---|---|---|
| `membership` | sessão participante | lista inscrições pertencentes ao e-mail autenticado |
| `search-registration` | sessão participante ou contexto administrativo conforme operação | localiza inscrição permitida |
| `generate-ticket` | participante proprietário ou admin | cria/retorna ingresso apenas se confirmado/isento/pago |
| `send-participant-email` | participante proprietário | envia acesso ou ingresso conforme estado |
| `resend-registration-email` | participante proprietário | reenvia dados da inscrição |
| `create-checkout` | participante proprietário | cria/reutiliza Payment Link para inscrição cobrável |
| `pagarme-payment-status` | participante proprietário | consulta a Pagar.me e aplica estado verificado |

## Pagamentos e processamento assíncrono

| Action | Autorização | Observação |
|---|---|---|
| `pagarme-webhook` | chamada externa, limitada por IP | ignora o status recebido e relê o pedido na API Pagar.me |
| `mail-worker` | `X-MPL-Mail-Worker` | tenta até 20 e-mails pendentes por execução, máximo de 10 tentativas por registro |

`create-checkout` aceita somente inscrições públicas com valor positivo e ainda não confirmadas. O servidor gera `order_code`, valor, itens, parcelamentos e métodos `credit_card` e `pix`; o navegador recebe apenas a URL HTTPS validada do domínio Pagar.me.

As actions legadas `card-hash-key`, `create-payment`, `payment-status` e `belluno-webhook` respondem HTTP 410 e não processam pagamento.

## Administração

`auth-login` recebe e-mail e senha e retorna o JWT. `create-admin` é exclusivamente o bootstrap inicial e só funciona quando configurado e ainda não existe administrador. `admin-api` recebe no JSON um segundo campo `action`:

| Subaction | Perfis autorizados no backend | Operação |
|---|---|---|
| `overview` | usuário administrativo ativo | indicadores do evento |
| `categories`, `lots`, `registrations` | usuário administrativo ativo | consultas operacionais |
| `report` | super_admin, admin, financeiro, consulta | relatório consolidado |
| `settings` | super_admin, admin | configuração da corrida |
| `users` | super_admin | lista usuários |
| `save-user` | super_admin | cria/edita usuário e perfil |
| `save-category`, `save-lot`, `save-settings` | super_admin, admin | grava configurações |
| `invitation-options`, `invitations` | super_admin, admin | consulta convites e opções |
| `create-invitation`, `cancel-invitation`, `send-invitation` | super_admin, admin | ciclo do convite |
| `resend-invitation-confirmation` | super_admin, admin | reenvia ingresso do convite consumido |
| `sponsors`, `update-sponsor` | super_admin, admin | moderação e publicação |

`sponsor-logo-upload` exige admin/super_admin e arquivo JPG, PNG ou WebP de até 5 MB e 5000 × 5000 pixels.

## Operação por QR

| Action | Perfis | Regra |
|---|---|---|
| `validate-ticket` | super_admin, admin, operador | consulta ticket, pagamento, kit e check-in |
| `withdraw-kit` | super_admin, admin, operador | exige inscrição paga/confirmada e grava uma única retirada |
| `checkin` | super_admin, admin, operador | exige inscrição paga/confirmada e grava um único check-in |

O mesmo token de ticket atende consulta, retirada e check-in; não existe fluxo paralelo para colaborador.

## Códigos HTTP esperados

| Código | Uso |
|---:|---|
| 200/201 | consulta ou gravação concluída |
| 400 | regra de negócio ou solicitação malformada |
| 401 | credencial ausente, inválida ou expirada |
| 403 | identidade válida sem permissão ou sem propriedade |
| 404 | recurso inexistente ou token público inválido |
| 409 | conflito, duplicidade, convite consumido ou estado incompatível |
| 410 | convite indisponível/action removida |
| 422 | validação de campos |
| 429 | limite de requisições |
| 500 | incidente interno com identificador; detalhe somente em debug |
| 503 | dependência indisponível, principalmente e-mail ou gateway |

## Compatibilidade

O frontend atual usa nomes e formatos existentes em `api.php`; mudanças devem ser aditivas ou versionadas. A migração futura das actions para controladores deve manter testes de contrato entre `api.php` e `api/index.php` até a remoção planejada do ponto legado.
