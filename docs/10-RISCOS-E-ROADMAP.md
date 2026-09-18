# Riscos, dívida técnica e roadmap

## Registro priorizado

| Prioridade | Achado | Impacto | Ação recomendada |
|---|---|---|---|
| Crítica | senha inicial legível em `database/seed_admin.sql` | acesso administrativo se usada em produção | retirar do release, rotacionar conta e gerar bootstrap por processo seguro |
| Alta | PHP 5.5 e Ubuntu 14.04 fora de suporte | vulnerabilidades sem correção do fornecedor | isolar agora e aprovar migração para plataforma suportada |
| Alta | DDL executado em requests | usuário web privilegiado e risco operacional | criar migrações versionadas e remover `ensure*` do bootstrap |
| Alta | dispatcher com actions em `api.php` | revisão difícil e alto acoplamento | continuar extração para controladores por domínio com testes de contrato |
| Alta | ausência de suíte automatizada | regressão em pagamento, permissão e evento | criar testes de integração PHP compatíveis e fixtures isoladas |
| Média | permissões distribuídas | possível divergência entre UI e backend | matriz central de policy por action e testes por perfil |
| Média | CORS aceita fallback amplo | exposição desnecessária da API | exigir origem explícita em produção |
| Média | retenção LGPD não automatizada | dados mantidos além da finalidade | política aprovada e job de expurgo/anonimização |
| Média | vínculos sem FK em grande parte do schema | registros órfãos | auditar dados e adicionar FKs/índices gradualmente |
| Média | cliente Pagar.me, webhook e e-mail juntos | manutenção e teste difíceis | separar gateway, serviço de pagamento e fila de notificação |
| Baixa | tabela `enderecos` e `pagamentos` legadas | ambiguidade para manutenção | confirmar ausência de uso e migrar/arquivar com plano |
| Baixa | CSS dividido entre base e overrides | cascata difícil de prever | consolidar tokens e componentes sem reescrever a interface |

## Plano incremental

### Antes da produção

1. Rotacionar todas as credenciais e eliminar o seed conhecido do artefato.
2. Definir `APP_DEBUG=0`, CORS exato, HTTPS, backup e alertas.
3. Homologar Pagar.me, webhook, SMTP, QR, retirada e check-in.
4. Executar teste de autorização com todos os perfis.
5. Restaurar um backup e registrar RPO/RTO real.

### Curto prazo

1. Extrair as actions de `api.php` para controladores por domínio.
2. Criar runner de migrações e reduzir privilégios do banco.
3. Centralizar policies e formatos de erro.
4. Automatizar testes de contrato, pagamento, convite e idempotência.
5. Criar limpeza de códigos, sessões, rate limit e dados conforme retenção.

### Médio prazo

1. Atualizar sistema operacional, PHP e MariaDB para versões suportadas mediante homologação.
2. Adicionar observabilidade estruturada com correlação de incidente.
3. Formalizar fila de e-mails e política de retry/dead letter.
4. Adicionar constraints relacionais após saneamento dos dados.
5. Documentar e testar continuidade durante indisponibilidade do gateway/internet.

## Decisões arquiteturais que devem ser preservadas

- Pagar.me Checkout hospedado para evitar tratamento de cartão pela aplicação.
- Confirmação do pedido diretamente na API do gateway.
- Gratuidade derivada exclusivamente do convite no backend.
- Um único fluxo de inscrição, ticket, retirada e check-in.
- QR com token opaco, sem CPF ou outros dados pessoais.
- Compatibilidade de URL em raiz e subdiretório enquanto o servidor interno exigir.
- Evolução incremental, mantendo contratos existentes durante a modularização.

## Critérios para remover legado

Uma action, tabela ou ponto de entrada só deve ser removido depois de: pesquisa de uso no frontend e operações; observação dos logs; migração de dados; período de compatibilidade; plano de rollback; aprovação da TI. Os endpoints Belluno podem ser retirados numa versão maior após confirmar ausência de clientes antigos, pois hoje respondem 410 de forma intencional.
