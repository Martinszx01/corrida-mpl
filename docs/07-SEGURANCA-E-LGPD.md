# Segurança, auditoria e LGPD

## Controles implementados

| Área | Controle atual |
|---|---|
| Senhas | `password_hash`/bcrypt e `password_verify`; senha não retorna pela API |
| SQL injection | PDO com prepared statements nos fluxos de entrada |
| Admin | JWT assinado, expiração de 24 h e revalidação da conta no banco |
| Participante | código temporário, token aleatório, hash no banco, expiração e revogação |
| Autorização | perfis e propriedade da inscrição conferidos no backend |
| Gratuidade | convite validado no backend; valor/tipo do cliente ignorados |
| Pagamento | cartão no Checkout hospedado; confirmação relida na Pagar.me e valor comparado |
| Abuso | rate limit persistente para login, códigos, inscrição de interesse, checkout e webhook |
| Upload | tipo real, dimensões, tamanho, nome aleatório e diretório restrito |
| Navegador | CSP, `nosniff`, frame policy, referrer policy, permissions policy e HSTS em HTTPS |
| Erros | incidente registrado; stack/detalhes apenas com debug |
| Auditoria | ações críticas com usuário, alvo, descrição, IP e data |
| Segredos | `.env` ignorado pelo Git e acesso HTTP bloqueado |

## Matriz de perfis

| Capacidade | super_admin | admin | operador | financeiro | consulta |
|---|:---:|:---:|:---:|:---:|:---:|
| Visão geral/inscrições | sim | sim | sim | sim | sim |
| Categorias, lotes e corrida | sim | sim | não pela UI | não | não |
| Convites e patrocinadores | sim | sim | não | não | não |
| Retirada e check-in | sim | sim | sim | não | não |
| Relatórios | sim | sim | não | sim | sim |
| Usuários administrativos | sim | não | não | não | não |

A tabela descreve intenção funcional e controles específicos existentes. O endpoint genérico `admin-api` permite algumas consultas operacionais a qualquer usuário administrativo ativo antes das restrições específicas; recomenda-se consolidar uma matriz única no backend na próxima fase.

## Limites de requisição atuais

| Operação | Limite aproximado |
|---|---|
| Solicitar código por IP | 5 em 15 minutos |
| Solicitar código por e-mail | 3 em 15 minutos |
| Verificar código por IP | 10 em 15 minutos |
| Interesse em patrocínio | 5 por hora |
| Login administrativo | 5 em 15 minutos |
| Bootstrap de admin | 3 por hora |
| Criar checkout | 5 em 15 minutos por e-mail/inscrição |
| Webhook Pagar.me | 60 por minuto; bloqueio de 5 minutos |

## Auditoria registrada

O código registra, entre outras, as ações `LOGIN_FALHOU`, `LOGIN_SUCESSO`, `PARTICIPANTE_LOGIN`, `INSCRICAO_CRIADA`, `CONVITE_CRIADO`, `CONVITE_CANCELADO`, `CONVITE_ENVIADO`, alterações de corrida/categoria/lote/usuário/patrocinador, `PAGARME_WEBHOOK`, envio/falha de ingresso, `KIT_RETIRADO` e `CHECKIN_REALIZADO`.

Logs devem ser protegidos contra alteração, ter retenção definida e nunca conter senha, segredo, código de acesso completo ou dados do cartão.

## LGPD

O controlador deve documentar finalidade e base legal para nome, CPF, nascimento, gênero, e-mail, telefone, camiseta, contato de emergência, consentimentos e IP. O sistema registra consentimento LGPD, mas a conformidade também depende de processo organizacional.

Medidas necessárias para produção:

1. publicar aviso de privacidade e regulamento versionados;
2. registrar qual versão foi aceita, evolução ainda não modelada no banco;
3. limitar acesso por função e revisar contas periodicamente;
4. definir retenção para inscrições, contatos, logs, exports e backups;
5. estabelecer procedimento para acesso, correção, anonimização e eliminação;
6. formalizar operadores Pagar.me, provedor de e-mail e hospedagem;
7. ter resposta a incidente e canal do encarregado.

## Riscos prioritários constatados

**Crítico — credencial conhecida no seed.** `database/seed_admin.sql` documenta login e senha iniciais. Mesmo com bcrypt, a senha está escrita no comentário. O arquivo deve ser retirado do artefato de produção e a conta deve receber uma senha exclusiva antes da homologação.

**Alto — DDL durante requests.** `api/database/schema.php` exige privilégios de `CREATE/ALTER` para o usuário web. Migrar para scripts versionados de deploy reduz impacto de comprometimento e elimina alterações inesperadas em horário de uso.

**Alto — matriz distribuída.** Restrições aparecem no frontend e em condicionais diferentes do dispatcher. Centralizar autorização por action reduz divergência e facilita evidência de auditoria.

**Médio — CORS permissivo por padrão.** Sem variável definida, a configuração pode aceitar `*`. Produção deve falhar de modo seguro ou exigir origem explícita.

**Médio — CSRF e sessão.** Tokens em headers e `sessionStorage` reduzem CSRF clássico, mas XSS continua capaz de capturar sessão. CSP, escape e revisão de qualquer HTML dinâmico são essenciais.

**Médio — retenção.** Não há rotina automática de expurgo de códigos, sessões, rate limits e dados após o período institucional.

## Checklist de segredo

- `.env` não versionado nem presente em ZIP para terceiros.
- `APP_DEBUG=0`, `ALLOW_BOOTSTRAP_ADMIN=0`.
- JWT e worker com segredos diferentes, longos e aleatórios.
- chave Pagar.me restrita ao servidor e ambiente correto.
- senha SMTP exclusiva, rotacionável e sem reutilização pessoal.
- banco não exposto à internet e usuário limitado ao schema.
