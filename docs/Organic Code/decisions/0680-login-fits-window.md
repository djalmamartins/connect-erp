# 0680 — Tela de entrada sem barra de rolagem

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026 07:40: "https://app.connect.lab/login esta dando barra de rolagem pode ajustar para não ter".

## Contexto

O cartão do login do morador (ADR 0657) tinha 811 px de altura, mais 96 px de margem: em janelas com
menos de ~910 px de altura a página rolava. No ERP o mesmo acontecia abaixo de ~850 px. No celular a
foto (285 px) somada à altura mínima do cartão (`100vh - 285px`) e ao texto da foto passava da tela.

## Decisão

Só CSS da tela de entrada (`admin.css` e `studio.min.css`, regras acrescentadas no fim), valendo para
ERP, Studio, Help Desk e app do morador (login, recuperação, senha nova e 2FA):

- Computador com janela de até 940 px de altura: cartão e painel da foto mais compactos (margens
  menores, campos e botão com 46 px, logo do cartão menor). Telas altas continuam iguais.
- Celular (até 860 px de largura): a foto encolhe e mostra só a logo e o título; a página vira uma
  coluna flexível com 100% da altura, sem a soma de alturas mínimas. Em celular baixo (até 720 px de
  altura) somem a logo do cartão, a nota do primeiro acesso e o "Voltar ao site".

Conferido sem rolagem em 1440×900, 1440×760, 1280×720, 1366×640, 390×844 e 375×667. Em janelas
ainda menores a rolagem volta, de propósito, para nada ficar escondido.

O app nativo (React Native) continua rolando de propósito: as dicas de segurança ficam abaixo do
cartão e o teclado precisa de rolagem.
