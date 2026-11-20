# 0603 — Tela da verificação em duas etapas igual à do login, com o código em 6 campos

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0029 (QR code do 2FA), 0315 (2FA com o nome Connect Condomínios), 0141 (Central de Ajuda)

## Contexto
Em 20/11/2026 o Djalma pediu: ao passar para o segundo fator, a tela perdia o layout do login (o cartão saía do lugar e a
foto com os textos sumia). Ele quer tudo igual ao login, trocando só o formulário; os textos do painel viram dicas de
segurança; o código em 6 campos separados que se juntam e enviam.

CONFIRMADO no repositório: uma única tela (`container/cms/studio/v1/components/login/two-factor.php`, rota
`/studio/login/2fa`) atende o ERP (`?next=erp`), o Studio, o Help Desk (`?next=helpdesk`) e o app do morador na web
(`?next=app`). O app React Native não tem tela de 2FA própria.

## Decisão
1. A tela usa a mesma moldura do login (`connect-login`: foto, painel, cartão, rodapé "Ambiente seguro e monitorado" e
   "Voltar ao site"). O cabeçalho do cartão mostra o ambiente (CONNECT ERP, MOVES HELP DESK, CONNECT MORADOR ou MOVESOS).
2. O painel troca a apresentação por dicas de segurança: nunca compartilhar o código, código novo a cada 30 segundos,
   códigos de recuperação; e o aviso "não foi você? troque a senha". Na primeira configuração as dicas falam do
   aplicativo autenticador. O QR code e a chave ficam num quadro dentro do cartão.
3. O código vai em 6 campos (`fieldset` com `legend`, rótulo "Dígito N de 6", `inputmode=numeric`, o primeiro com
   `autocomplete=one-time-code`). `assets/js/login-otp.js` avança sozinho, aceita colar ou o preenchimento automático do
   código inteiro, volta com a tecla de apagar, anda com as setas, junta os dígitos no campo oculto `code` e envia ao
   completar o sexto. Código recusado limpa os campos e volta ao primeiro.
4. "Usar código de recuperação" troca os 6 campos por um campo de texto (formato xxxxx-xxxxx); o valor também vai em
   `code`. O servidor não muda: `Auth::completeTwoFactor` continua decidindo entre TOTP (6 dígitos) e recuperação.
5. Envio incompleto é barrado no navegador (o servidor continua validando, com o limite de tentativas de sempre).

## Consequências
- Teste `TwoFactorLoginScreenTest` (moldura do login, 6 campos acessíveis, script e CSS no pacote do Studio).
- Artigo "Entrar no Connect ERP" da Central de Ajuda explica os 6 quadradinhos e o código de recuperação.
- `studio.min.css` regerado (`php service/commands/build-studio-assets.php`).
- PENDENTE: o app do morador na web ainda abre essa tela com a identidade Connect do ERP, não a do app; e as telas de
  gerenciar o 2FA (Segurança no ERP e no Studio) seguem com um campo só.
