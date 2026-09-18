# Mapa da refatoração da API

O arquivo público `api.php` permanece compatível com todas as URLs atuais. A separação é executada por fases para permitir comparação de respostas e reversão simples.

## Fase 1 — concluída

- `core/response.php`: entrada e resposta JSON.
- `core/database.php`: conexão PDO.
- `database/schema.php`: verificações de schema já existentes.
- `core/security.php`: IP, auditoria, rate limit e CPF.
- `core/participant-auth.php`: sessão do participante.
- `core/auth.php`: JWT, autenticação e perfis administrativos.
- `services/event.php`: consulta da corrida e categorias administrativas.
- `services/invitation.php`: proteção e link de convites.
- `services/ticket.php`: emissão do token de ingresso.
- `services/qrcode.php`: geração do PNG do QR Code.
- `services/mail.php`: mensagens e envio de e-mails.
- `services/registration.php`: propriedade da inscrição.
- `core/error-handler.php`: resposta de exceções.
- `core/bootstrap.php`: carregamento ordenado dos módulos.

## Actions mapeadas

### Públicas e autenticação

`health`, `diagnostico`, `public-event`, `request-participant-access`, `verify-participant-access`, `participant-logout`, `invitation-info`, `public-sponsors`, `sponsor-interest`, `auth-login`, `create-admin`, `membership`, `sponsor-logo-upload`.

### Administração (`admin-api`)

`overview`, `categories`, `lots`, `registrations`, `users`, `report`, `settings`, `invitations`, `invitation-options`, `create-invitation`, `cancel-invitation`, `send-invitation`, `resend-invitation-confirmation`, `sponsors`, `update-sponsor`, `save-category`, `save-lot`, `save-settings`, `save-user`.

### Participante, inscrição e operação

`search-registration`, `send-participant-email`, `resend-registration-email`, `create-registration`, `generate-ticket`, `validate-ticket`, `withdraw-kit`, `checkin`.

### Pagamento

O fluxo Pagar.me/Stone continua carregado por `pagarme_checkout.php`. As actions legadas `card-hash-key`, `create-payment`, `payment-status` e `belluno-webhook` permanecem desativadas com HTTP 410.

## Próximas fases

1. Separar endpoints públicos e autenticação.
2. Separar o dispatcher administrativo e suas operações.
3. Separar inscrição, ticket, retirada de kit e check-in.

Cada fase deve manter parâmetros, JSON, códigos HTTP, autenticação, SQL e regras atuais.
