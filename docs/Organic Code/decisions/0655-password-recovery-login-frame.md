# 0655 — Recuperação de senha na mesma moldura do login

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 17:55, com o link https://erp.connect.lab/forget:
  "ela tá sem layout, tá muito feio".

## Contexto

O "Esqueci minha senha" do login do ERP leva a `/forget` (`Web:forget`, ADR 0009). A página e o
"Criar senha nova" (`/forget/{token}`) usavam as views antigas do site (`pages/login/forget` e
`pages/login/reset`, layout `layouts/error`), com classes que o CSS atual não estiliza mais. No ERP,
Studio e app do morador a tela aparecia sem layout, diferente do login (ADR 0620).

## Decisão

- `forget` e `reset` passam a usar a moldura da tela de entrada do Studio (`layouts/login`, foto,
  painel com dicas de segurança e cartão): `components/login/forget.php`, `components/login/reset.php`
  e a parte da foto em `components/login/_recovery-visual.php`. 
- O cartão mostra o ambiente pelo host (ERP, app do morador ou Connect) e "Voltar para o login" leva
  ao login de onde a pessoa veio; depois de salvar a senha nova, o redirecionamento também.
- CSS (vale também para o login): o campo com o olho não passa mais da largura do cartão e os links
  do cartão ("Esqueci minha senha", "Voltar para o login") ficam no verde da marca, não no azul
  padrão do navegador. `studio.min.css` gerado de novo.
- O carregador mostra "Enviando..." / "Salvando..." em vez de "Entrando..." (`$loadingText`).
- Regras sem mudança: CSRF, limite de tentativas, mesma resposta para conta existente ou não, token
  de uso único com hash, rotas e API.

## Consequências

As views antigas `pages/login/forget.php` e `pages/login/reset.php` do tema do site ficam sem uso
(mantidas, sem remoção de arquivos). O teste `PasswordRecoveryViewTest` confere a moldura.
