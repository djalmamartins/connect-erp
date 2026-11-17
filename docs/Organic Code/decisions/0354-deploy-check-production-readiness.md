# ADR 0354 — Conferência de produção no deploy-check

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0325 (cron e backup na hospedagem), ADR 0333 (vigia), ADR 0023/0028 (2FA), ADR 0156 (cofre dos bancos)

## Contexto

O `service/commands/deploy-check.php`, que o roteiro da hospedagem manda rodar na primeira implantação e a cada versão
(HOSPEDAGEM.md §3 e §6), conferia só PHP, pastas, nomes do `.env`, debug e migrations. O ROADMAP pede para a produção
"só usuários reais, sem conta de teste, todos com 2FA", dados de exemplo desligados e chaves novas. Nada disso era
conferido: um `.env` copiado do laboratório subiria sem erro.

## Decisão

`Source\Support\DeployReadiness`, chamado pelo deploy-check só com `APP_ENV=production`:

- **Erro (impede a publicação):** `APP_DEBUG` ligado; `ERP_DEMO_DATA` ligado; `APP_URL` vazio, do laboratório (`.lab`,
  `.test`, `localhost`, IP) ou de exemplo; `CONNECT_2FA_KEY` ou `CONNECT_BANK_VAULT_KEY` ausente ou sem 32 bytes em
  base64; as duas iguais, ou `TALK_KEY` igual a uma delas; conta no banco com e-mail do laboratório ou de exemplo
  (`connect.lab`, `exemplo.test`, `example.com`...).
- **Aviso:** `CONNECT_2FA_ENFORCE_FROM` vazio ou no futuro; `ALERT_EMAIL` vazio; `ERP_MAIL_DELIVERY` diferente de
  `production`; sem `MOVESOS_SMTP_HOST`; pessoa da equipe (com papel em `access_user_roles`) sem 2FA confirmado; credencial
  de banco em ambiente de teste.
- A saída mostra só ids e quantidades, nunca e-mail ou nome. Tabela que ainda não existe (antes da primeira migration) é
  ignorada; banco fora do ar vira aviso.

## Consequências

- O primeiro deploy-check na hospedagem vai listar o que falta configurar, com o nome de cada variável.
- Conta de teste precisa ser excluída (ou ter o e-mail trocado) antes de abrir; a conta do Djalma e da equipe precisam do
  2FA confirmado para o aviso sumir.

## Testes

- `DeployReadinessTest` (5 testes): `.env` bom sem nada; debug, demonstração, endereço do lab e chave curta são erro;
  chaves repetidas; itens do roteiro são aviso; no banco, contas de teste, equipe sem 2FA e banco de teste, sem e-mail na
  saída; o deploy-check chama as duas conferências.
- Rodado de verdade com um `.env` de produção apontando para o banco de teste: acusou as 2 contas de teste e os avisos.
