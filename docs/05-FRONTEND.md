# Frontend e rotas

## Organização

O frontend é uma SPA escrita em JavaScript sem framework. `index.php` gera o documento base, metadados, CSP com nonce e `window.MPL_CONFIG`. `app.js` contém roteamento, templates, eventos, validações e cliente HTTP. `style.css` mantém a base visual; `ux-modern.css` concentra ajustes institucionais posteriores.

```text
index.php                 shell e configuração pública
app.js                    aplicação e telas
style.css                 estilos principais
ux-modern.css             refinamentos visuais
assets/images             banners, galeria, logos e patrocinadores
assets/vendor             QR encoder e leitor
admin/                    entrada direta do painel
auth/                     login
inscricao/                formulário público
minha-inscricao/          acesso e ingresso
consultar-inscricao/      consulta compatível
pagamento/                retorno/acompanhamento do checkout
patrocinio/               apresentação e formulário de interesse
privacidade/              aviso LGPD
regulamento/              regulamento
```

Os diretórios de rota possuem `index.php` de compatibilidade. O `.htaccess` entrega arquivos reais e encaminha as demais URLs ao shell.

## Configuração disponível no navegador

Somente `api_base`, `site_url`, `event_slug` e `base_url` são publicados. Chaves de banco, JWT, SMTP e Pagar.me permanecem exclusivamente no servidor.

## Estado e autenticação

- JWT administrativo: `sessionStorage.mpl_token`.
- Sessão do participante: `sessionStorage.mpl_participant_token` e e-mail associado.
- O código remove/migra vestígios antigos de token em `localStorage`.
- Estado de tela e dados carregados ficam em memória; recarregar solicita novamente os dados da API.
- A History API controla navegação, e o retorno do histórico força a atualização necessária para evitar dados operacionais antigos.

## Principais experiências

O site público apresenta corrida, informações, galeria, patrocinadores e chamada de inscrição. A inscrição tem uma única prova de 5 km; o usuário fornece dados pessoais, camiseta, dois aceites e contato de emergência opcional.

A área do participante usa código enviado por e-mail, lista inscrições, acompanha pagamento e permite solicitar o ingresso. O painel administrativo mostra indicadores, inscrições, configuração, lotes, convites, patrocinadores, relatórios, retirada, check-in e usuários conforme perfil.

## Regras de implementação

- Toda decisão financeira ou de autorização pertence ao backend; validação JavaScript é apenas usabilidade.
- Dados inseridos em HTML devem passar pelas funções de escape existentes.
- Botões assíncronos devem validar a existência do elemento antes de alterar `disabled`, expor sucesso/erro e restaurar o estado em `finally`.
- Novas rotas precisam funcionar tanto na raiz quanto em subdiretório usando `base_url`.
- Assets locais devem ser referenciados com o prefixo calculado; URLs absolutas hardcoded quebram a implantação interna.
- Não introduzir dependência que exija Node/build enquanto a restrição operacional permanecer.

## Responsividade e acessibilidade

O layout usa breakpoints no CSS, menu adaptado, grades que colapsam e controles de toque. Alterações devem ser verificadas em 360, 768, 1024 e 1440 pixels. Campos precisam de rótulo, foco visível, mensagens associadas e área clicável suficiente. O scanner requer permissão de câmera concedida pelo usuário e contexto HTTPS, exceto `localhost`.

## Cache e versão de assets

`index.php` envia `Cache-Control: no-store, private` para o shell e inclui query string de versão em CSS, JS e banner social. Ao publicar uma mudança de frontend, incremente a versão do asset afetado para evitar cache antigo, sem renomear rotas públicas.
