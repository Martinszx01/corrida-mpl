# 4ª Corrida MPL

Sistema web da 4ª Corrida do Grupo MPL, prevista para 14 de novembro de 2026. O projeto reúne site público, inscrição para a prova única de 5 km, Checkout Pagar.me/Stone, área do participante, ingresso com QR Code, convites gratuitos para colaboradores e painel administrativo para operação do evento.

## Stack e restrições

- PHP compatível com 5.5, sem framework e sem Composer em produção.
- Apache 2.4 com `mod_rewrite` e `mod_headers`.
- MariaDB 5.5.62, acesso por PDO/MySQL.
- JavaScript, HTML e CSS próprios; não existe etapa de build e Node.js não é necessário.
- Checkout hospedado Pagar.me/Stone com PIX e cartão.
- SMTP nativo ou `mail()` para mensagens transacionais.
- QR Code gerado no backend pela biblioteca PHP QR Code e lido no navegador por `html5-qrcode`.

## Documentação técnica

1. [Visão geral e arquitetura](docs/01-ARQUITETURA.md)
2. [Instalação e configuração](docs/02-INSTALACAO-E-CONFIGURACAO.md)
3. [Banco de dados](docs/03-BANCO-DE-DADOS.md)
4. [Contrato da API](docs/04-API.md)
5. [Frontend e rotas](docs/05-FRONTEND.md)
6. [Fluxos de negócio e integrações](docs/06-FLUXOS-E-INTEGRACOES.md)
7. [Segurança, auditoria e LGPD](docs/07-SEGURANCA-E-LGPD.md)
8. [Deploy, operação e recuperação](docs/08-OPERACAO-E-DEPLOY.md)
9. [Testes e homologação](docs/09-TESTES-E-HOMOLOGACAO.md)
10. [Riscos, dívida técnica e roadmap](docs/10-RISCOS-E-ROADMAP.md)
11. [Inventário de arquivos e responsabilidades](docs/11-INVENTARIO-DE-ARQUIVOS.md)

O [mapa da refatoração da API](api/REFACTOR_MAP.md) registra o estágio atual da separação do backend. As observações que exigem uma etapa específica de segurança permanecem em [SECURITY_NOTES.md](SECURITY_NOTES.md).

## Entradas principais

| Entrada | Responsabilidade |
|---|---|
| `index.php` | Shell HTML, CSP, metadados e configuração pública do frontend |
| `app.js` | SPA, telas, formulários, navegação e consumo da API |
| `api.php?action=...` | Endpoint público compatível usado pelo frontend atual |
| `api/index.php?action=...` | Nova entrada equivalente, ainda não adotada pelo frontend |
| `pagarme_checkout.php` | Funções e actions do Checkout Pagar.me/Stone |
| `mail_service.php` | Transporte SMTP ou `mail()` |
| `database/mariadb.sql` | Schema de instalação consolidado |

## Estado da arquitetura

A primeira fase da refatoração do backend foi concluída: funções auxiliares foram separadas em `api/core`, `api/services` e `api/database`. O dispatcher e os corpos das actions ainda permanecem em `api.php` para preservar compatibilidade durante a migração incremental.
