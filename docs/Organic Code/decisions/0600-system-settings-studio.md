# ADR 0600 — Configuração geral do sistema no Studio, com senha SMTP cifrada e e-mail de teste

- Status: Aceito
- Data: 20/11/2026
- Pedido: Djalma, 20/11/2026 ("criar no CMS uma configuração para todo o sistema ... domínio, envio de e-mail pela AWS com senha ... título ... tudo que o Realizze tem").

## Contexto

CONFIRMADO no repositório: o Studio já tinha `/studio/settings` (`Studio::settings`, `components/settings/home.php`) gravando a linha única da tabela `settings`, lida em `source/Boot/Settings.php`. Faltavam:

- usuário e senha SMTP eram gravados **em texto puro** na tabela (`mail_user`, `mail_pass`);
- não havia como testar o e-mail pela tela (só `tools/hosting/email-teste.php`, no terminal);
- campos que o Realizze configura (`source/Boot/Config.php` do zip) e o Connect lê, mas a tela não mostrava: domínio sem https, idioma/HTML/autenticação do e-mail, Facebook app/autor e tema da manutenção;
- nenhuma validação de domínio, porta ou remetente.

Do Realizze ficaram de fora, de propósito: Pagar.me (Connect não é SaaS, ADR-002), Google+ (serviço encerrado) e credenciais do banco de dados (ficam só no `.env`).

## Decisão

1. `mail_user` e `mail_pass` são gravados com `MailConfig::seal` (AES-256-GCM do `SecretBox`, chave `CONNECT_2FA_KEY`, contexto `settings.<coluna>`), prefixo `enc1:`. Sem a chave, a tela recusa guardar e não grava nada. Valor antigo em texto puro continua lido e é cifrado na próxima gravação. Limite de 128 caracteres (cabe cifrado em varchar(255), sem migration).
2. `MailConfig::resolve` abre os valores cifrados; o `.env` (`MOVESOS_SMTP_*`, ADR 0060) continua valendo sobre a tela, e a tela mostra quais campos vêm do `.env`.
3. Provedor **Amazon SES** com lista de regiões que preenche `email-smtp.<região>.amazonaws.com`, 587 e TLS.
4. Botão **Enviar e-mail de teste** (`action=mail_test`): manda direto pelo SMTP salvo (sem fila, tempo máximo 20 s), confere SPF/DKIM/DMARC com `MailDns`, registra no log só o hash do destinatário, no máximo 5 por usuário a cada 10 min (`IpRateLimit`).
5. `SystemSettingsRules::validate` recusa domínio sem https, servidor/porta/segurança/remetente inválidos.

## Consequências

- A senha nunca volta para a tela nem para o log; `AppLogger`/`Audit` já mascaravam `mail_pass`/`mail_user`.
- Trocar a `CONNECT_2FA_KEY` torna usuário/senha da tela ilegíveis: a tela avisa para digitar de novo.
- Testes: `tests/legacy/Unit/SystemSettingsTest.php`, `tests/legacy/Integration/SystemSettingsSealIntegrationTest.php`.
- Artigo: `configuracoes/configuracao-geral-do-sistema.md`.
- PENDENTE (outra conversa, thread LGPD/CDC): razão social, CNPJ e encarregado de dados nas Configurações.
