# 0657 — Login do app do morador no estilo do ERP (web e celular)

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 19:25: "o login do app morador poderia ser personalizado no estilo do erp".

## Contexto

O login do app do morador na web (`/app/login`, ADR 0302) era um cartão simples no fundo claro, e o
app React Native (`apps/morador/app/login.tsx`, ADR 0303) um cartão branco no verde-escuro. O ERP já
usa a tela de entrada do Studio (foto do prédio, dicas, cartão, link verde), também usada na
recuperação de senha (ADR 0655). O Djalma quer os dois apps iguais.

## Decisão

- Web: `AppLogin` desenha `components/login/login` do Studio com `loginEnvironment = app`: painel da
  foto com texto para o morador e três dicas de segurança, cartão com "CONNECT MORADOR", aviso de
  conta sem unidade (`$loginNotice`) e a nota do primeiro acesso (`.connect-login__note`).
- O formulário passa a enviar por AJAX (`login.js`), como no ERP: `AppLogin` responde JSON quando
  vem `X-Requested-With`; sem JavaScript, o POST normal continua com redirecionamento. Mesmas regras
  (CSRF, limite de 8 tentativas em 5 min, 2FA em `/studio/login/2fa?next=app`, só com vínculo).
- "Esqueci minha senha" vai a `/app/recuperar-senha`, que leva a `/forget` (no host do app) ou a
  `/forget?de=app` (fora dele). O link do e-mail (`/forget/{token}`) mostra o cartão do morador
  quando a conta tem unidade e não tem `erp.access`; "Voltar para o login" vai ao login do app.
- Celular: `login.tsx` refeito no mesmo desenho, adaptado para a tela pequena: foto no alto com
  título e o cartão subindo sobre ela, campos com ícone e olho, link verde sublinhado, botão
  verde-escuro com seta dourada, "Ambiente seguro e monitorado" e as dicas de segurança abaixo do
  cartão. Os textos de cada etapa (entrar, esqueci, link enviado, 2FA) ficam em
  `src/lib/loginScreen.ts`. Foto recortada em `assets/login-cover.jpg`.
- O aceite dos Termos e da Política (LGPD, ADR 0604) continua logo depois da entrada, nos dois apps.

## Consequências

A view antiga `container/app/connect/v1/pages/login.php` fica sem uso (mantida, sem remoção de
arquivos). `studio.min.css` recebeu só as regras novas: `.connect-login__note` e, no celular, espaço abaixo do texto da foto para o cartão não cobrir (vale também para o ERP e a recuperação de senha).

## Atualização (26/11/2026, 22:54): foto de dia

O Djalma pediu a foto de fundo "como se fosse dia" e mandou uma imagem gerada. Ela entra só no
morador: `container/web/default/v1/assets/images/bkg_login_morador_dia.jpg` no login, recuperação,
senha nova e 2FA quando o ambiente é o app (`next=app`), e recortada em
`apps/morador/assets/login-cover.jpg` no celular. ERP, Studio e Help Desk continuam com a foto de noite.
