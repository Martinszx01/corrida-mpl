# Deploy, operação e recuperação

## Ambientes

Mantenha desenvolvimento, homologação e produção separados por banco, `.env`, chave Pagar.me, SMTP e URL. Dados reais não devem ser copiados para desenvolvimento sem anonimização. O Checkout e o webhook precisam ser homologados no ambiente correspondente à chave.

## Publicação recomendada

1. Gere backup verificado do banco e dos logos enviados.
2. Registre versão/commit e janela de implantação.
3. Valide sintaxe PHP em todos os arquivos alterados.
4. Publique os arquivos em diretório de release ou faça cópia atômica; preserve o `.env` do servidor.
5. Execute migrações com conta de implantação, quando forem separadas do runtime.
6. Revise permissões: código somente leitura; escrita apenas onde necessário.
7. Limpe OPcache ou reinicie o serviço PHP/Apache de forma controlada.
8. Execute smoke tests públicos, administrativos e de integração.
9. Monitore logs HTTP, PHP, banco, webhook e e-mail.

Nunca substitua o `.env` de produção por um arquivo do ZIP. Não publique `database`, `storage`, documentação interna, dumps ou scripts de bootstrap em área acessível, mesmo com as regras do `.htaccess`.

## Apache

- `DocumentRoot`/Alias deve apontar para o projeto e permitir `.htaccess`.
- Módulos `rewrite` e `headers` precisam estar ativos.
- HTTPS deve ser obrigatório; HSTS só surte efeito em conexão HTTPS.
- Defina limites de upload compatíveis com 5 MB mais overhead.
- Desative listagem de diretório no VirtualHost além da proteção local.
- Direcione logs de acesso e erro para arquivos com rotação.

## Permissões

O usuário do Apache necessita leitura do código, leitura do `.env` e escrita somente em `storage` e `assets/images/sponsors`. Arquivos de configuração não devem ser graváveis pelo processo web. O usuário MariaDB deve ser exclusivo e não possuir privilégios globais.

## Worker de e-mail

Agende uma chamada HTTP local ou controlada para `api.php?action=mail-worker` com header `X-MPL-Mail-Worker`. Use HTTPS, segredo próprio e frequência compatível com a operação, por exemplo a cada cinco minutos. Registre somente código HTTP e contadores; não escreva o header no log. Alertar quando `sent` permanecer zero com pendências ou houver 401/503 repetido.

## Webhook Pagar.me

Cadastre a URL pública `https://<host>/<base>/api.php?action=pagarme-webhook`. Ela deve ser alcançável pela Pagar.me e não pode depender de sessão. Uma resposta 2xx significa processamento/ignorância controlada; falhas devem ser retentadas pelo provedor. O backend confirma o pedido novamente pela API, portanto precisa de saída HTTPS e chave válida.

## Monitoramento

Monitore pelo menos:

- disponibilidade e latência do endpoint `health`;
- taxa de respostas 4xx/5xx por action;
- falhas de conexão PDO e ocupação do banco;
- pagamentos `PROCESSING` além da expiração;
- pagamentos `PAID` sem `email_confirmacao_em`;
- `tentativas_email` e `ultimo_erro_email`;
- falhas/repetições de webhook;
- logins administrativos falhos e bloqueios;
- espaço em disco, validade TLS e idade do backup.

## Rotinas operacionais

Antes de abrir inscrições, confira corrida publicada, flag aberta, categoria 5 km ativa, lote vigente, preço/capacidade, regulamento, privacidade, Pagar.me e e-mail. Antes da retirada, teste dois dispositivos, câmera, busca manual, perfis de operador e internet. Antes da prova, faça ensaio de check-in, plano de contingência e export autorizado.

## Backup e recuperação

Adote backup diário do MariaDB, cópia de logos e retenção compatível com LGPD. Criptografe em trânsito e repouso e mantenha uma cópia fora do servidor. O procedimento de restore deve documentar: provisionar banco vazio, importar dump, restaurar assets, aplicar `.env` do cofre, conferir permissões, executar testes e trocar DNS/VirtualHost.

Defina RPO e RTO com TI. Uma referência inicial para o período de vendas é RPO de até 24 horas, reduzido durante picos, e RTO aprovado conforme criticidade. Essa meta só é válida após ensaio de recuperação.

## Rollback

Rollback de código deve restaurar o release anterior sem reverter dados já confirmados. Alterações de schema precisam ser retrocompatíveis durante a janela. Se uma migração não puder ser revertida com segurança, avance com correção e restaure backup somente após decisão formal, pois restaurar banco pode apagar inscrições e pagamentos posteriores.

## Diagnóstico do servidor legado

No Ubuntu/Apache, o log usual é `/var/log/apache2/error.log`. O comando correto é `tail -n 100 /var/log/apache2/error.log`. PHP 5.5 e Ubuntu 14.04 estão fora de suporte; isolar a rede, aplicar controles compensatórios e planejar atualização são requisitos de risco, mesmo mantendo compatibilidade atual.
