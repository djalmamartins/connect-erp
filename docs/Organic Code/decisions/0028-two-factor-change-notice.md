# 0028 — Aviso por e-mail quando o 2FA da conta muda

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

Risco 1 da revisão de segurança da Fase 1 (issue #7): depois de `CONNECT_2FA_ENFORCE_FROM`, quem
só tem a senha de uma conta sem 2FA cai no modo `setup` e cadastra o próprio autenticador. O dono
da conta não fica sabendo. Fechar o modo `setup` depois de uma data trancaria quem ainda não
cadastrou, e hoje não existe cadastro do 2FA feito por administrador.

## Decisão

- **Aviso por e-mail a cada mudança no 2FA** (`Source\Services\TwoFactorNotice`, template
  `container/send/default/v1/pages/two-factor-changed.php`):
  - ativado durante o login (modo `setup`), em `Auth::completeTwoFactorSetup`;
  - ativado ou desativado pelo próprio usuário nas telas de segurança do Studio e do ERP;
  - redefinido por administrador (`DELETE /api/v1/users/{id}/2fa`, ADR 0025).
- O e-mail vai pela fila `mail_queue` (`Email::queue`), sem SMTP durante o login. Erro ao
  enfileirar fica no log (`TWO_FACTOR_NOTICE_FAILED`) e não interrompe o fluxo.
- O texto diz o que mudou e quando, e orienta trocar a senha e avisar o administrador se não foi
  o dono. Não leva link nem dado da sessão.
- **O modo `setup` continua aberto.** O período de adesão antes de ativar a obrigatoriedade
  continua sendo a mitigação principal (`docs/security/REVIEW_FASE1_2FA.md`).

## Consequências

- Quem rouba a senha ainda consegue cadastrar o autenticador, mas o dono recebe o aviso e o
  administrador pode redefinir o 2FA pela API (ADR 0025).
- O aviso depende do processamento da fila de e-mails no ambiente.
- Testes: `TwoFactorNoticeTest` (template, envio injetado, erros e cobertura de todos os pontos que
  mudam o 2FA) e `MailTemplateTest`.
