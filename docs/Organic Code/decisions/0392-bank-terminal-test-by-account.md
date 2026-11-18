# 0392 — Teste da credencial do banco pelo terminal escolhe o banco da conta

- Status: Aceito
- Data: 18/11/2026
- Contexto: ROADMAP_V1 §3.4 ("Saída com certificado (mTLS) para o Inter e a Cora ... testar logo no começo da etapa 4"),
  ADR 0156 (credenciais pelo terminal) e ADR 0322 (Cora).

## Contexto
`php tools/bank-credentials.php testar --conta=ID` é o jeito de conferir, na hospedagem, que o cURL do PHP fala com o
banco usando o certificado. Mas ele montava sempre o adaptador do Inter: numa conta da Cora pedia o token no endereço do
Inter com a credencial da Cora e falhava sem dizer por quê. A tela de Integrações já escolhia o banco pelo código da conta.

## Decisão
`BankCredentialSetup::testFromTerminal()` faz o mesmo teste da tela (só token, nada é emitido), sem usuário, porque quem
tem o terminal do servidor já tem o `.env`. O banco sai do código da conta (077 Inter, 403 Cora) e a mensagem de erro é
a mesma explicação da tela. O comando sai com 0 quando o banco respondeu e 1 com o motivo; conta inexistente ou sem
credencial dá erro claro.

## Consequências
- Roteiro em HOSPEDAGEM.md §7: rodar o teste antes do webhook, no começo da etapa 4.
- Testes: `CoraBankingIntegrationTest::testTerminalTestUsesTheCoraNotTheInter` (o token é pedido à Cora; conta
  inexistente recusada); integração do Inter, da Cora e da tela de credenciais passando.
