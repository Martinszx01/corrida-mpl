# Testes e homologação

## Estratégia atual

O repositório não possui suíte automatizada completa. A homologação combina lint PHP, testes HTTP, inspeção do banco e roteiro manual ponta a ponta. Para um sistema de pagamento e acesso físico, evidências devem registrar data, ambiente, versão, executor, dados mascarados e resultado.

## Verificações estáticas

1. Execute `php -l` em todos os `.php` do projeto.
2. Procure segredos versionados e credenciais em scripts/documentos.
3. Confirme que `.env`, SQL, logs e Markdown retornam 403 pelo Apache.
4. Confirme ausência de sintaxe posterior ao PHP 5.5.
5. Revise queries novas para parâmetros preparados e autorização antes de acesso.

## Smoke test após deploy

| ID | Cenário | Resultado esperado |
|---|---|---|
| S01 | Abrir home e rotas diretas | layout e assets carregam sem 404 |
| S02 | `health` | JSON 200 sem segredo |
| S03 | Evento público | corrida 4, data e 5 km corretos |
| S04 | Login válido/inválido | JWT somente no válido; rate limit no abuso |
| S05 | Painel por perfil | menus e API respeitam permissão |
| S06 | Área do participante | código chega, valida e sessão lista apenas o próprio e-mail |
| S07 | QR scanner | câmera abre em HTTPS e ticket é localizado |

## Inscrição comum

- Validar obrigatórios, CPF inválido, e-mail inválido, telefone sem DDD, nascimento futuro, gênero e camiseta.
- Confirmar que endereço não é solicitado e contato de emergência só é obrigatório quando habilitado.
- Confirmar que regulamento e LGPD são obrigatórios.
- Tentar alterar categoria, tipo e valor pelo DevTools; o backend deve ignorar/rejeitar.
- Repetir CPF com outro e-mail e repetir CPF na mesma corrida; ambos devem falhar com conflito.
- Esgotar lote em concorrência; vendidos não pode ultrapassar quantidade.

## Pagar.me

1. Criar inscrição pendente e Payment Link.
2. Conferir domínio HTTPS Pagar.me, descrição, valor, PIX e cartão.
3. Repetir criação e confirmar reutilização/idempotência.
4. Simular pagamento aprovado; webhook deve reler o pedido, marcar `PAID`, inscrição paga e criar um ticket.
5. Repetir webhook; não deve duplicar ticket ou e-mail concorrente.
6. Testar recusado, expirado, cancelado e valor divergente.
7. Confirmar que inscrição de colaborador não cria linha cobrável/Payment Link.
8. Verificar que nenhum dado de cartão aparece no banco, logs ou navegador da aplicação.

## E-mail

- Testar acesso do participante, confirmação de inscrição, convite e ingresso.
- Validar destinatário, assunto, acentuação, remetente, SPF/DKIM/DMARC e entrega em spam.
- Abrir anexo PNG e ler o QR em outro dispositivo.
- Induzir falha SMTP, conferir persistência do erro e recuperação pelo worker.
- Solicitar reenvio pela área do participante e pelo convite consumido.
- Confirmar que pagamento continua válido mesmo quando o e-mail falha.

## Colaborador MPL

- Criar, copiar e enviar convite.
- Abrir link válido e conferir corrida/5 km.
- Concluir inscrição e confirmar valor zero, tipo colaborador e pagamento isento.
- Confirmar ingresso/e-mail e uso normal na retirada/check-in.
- Reutilizar, cancelar e expirar convites; todas as tentativas inválidas devem falhar.
- Manipular token e payload no navegador; gratuidade não pode ser obtida sem convite válido.

## Operação física

- Ticket válido, inexistente, cancelado e de inscrição pendente.
- Primeira e segunda retirada; a segunda deve informar duplicidade sem novo registro.
- Primeiro e segundo check-in; mesmo comportamento idempotente.
- Operador permitido; financeiro/consulta bloqueados.
- Scanner por câmera e alternativa de busca/token.

## Segurança

- SQL injection e XSS nos campos textuais e filtros.
- Acesso horizontal trocando `registration_id` com outra sessão.
- Acesso vertical chamando subactions diretamente com cada perfil.
- JWT alterado, expirado, usuário inativo e perfil mudado após emissão.
- Código participante errado além do limite e token revogado.
- Upload com extensão falsa, MIME inválido, tamanho/dimensão excessivos e arquivo PHP.
- CORS de origem não autorizada, headers de segurança e CSP no console.
- Webhook inventado: nunca deve confirmar pagamento sem resposta válida da Pagar.me.

## Critério de aceite

Homologação só termina quando todos os cenários críticos passam, não há segredo exposto, backup foi restaurado com sucesso e TI aprovou riscos residuais. Defeitos de pagamento, autorização, duplicidade de entrada, perda de dados ou vazamento bloqueiam produção.
