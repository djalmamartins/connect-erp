# 0688 — Caixinha: aplicação no mesmo banco da conta corrente

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026 18:06 (Brasília), teste 6.2 do manual: "na aplicação ele pede o número do banco
  e aí o banco é o mesmo. Só que aí embaixo pede para selecionar qual é o banco também. E aí eu não consigo
  criar essa aplicação. [...] E se for no mesmo banco, for uma caixinha? Fazer igual hoje os bancos criam
  caixinhas para poder fazer investimento."

## Contexto

A conta de tipo Aplicação (ADR 0170 e 0149) pedia banco, agência e conta e, mais abaixo, a conta corrente
ligada. Quando a aplicação é no mesmo banco (as caixinhas e cofrinhos do Inter, Nubank, Itaú etc.), banco,
agência e conta são os mesmos da conta corrente, e a regra "Esta conta já está cadastrada neste condomínio"
(condomínio + banco + agência + conta) recusava a gravação. Não havia como cadastrar a caixinha.

## Decisão

- Coluna `erp_bank_accounts.is_pocket` (migration `20261127_2200_bank_account_pockets`, com `.down.sql`,
  só acrescenta). No formulário, caixa **Caixinha no mesmo banco da conta corrente**, só para Aplicação.
- Caixinha marcada: banco, agência e conta somem do formulário e o servidor copia os da conta corrente ligada
  (mesmo condomínio, dentro do escopo); o que vier digitado é ignorado. A conta corrente passa a ser
  obrigatória. Quando a conta corrente muda banco, agência ou conta, as caixinhas dela acompanham, com
  auditoria. `POST /bank-accounts/{id}/link` (Investments::link) não desliga uma caixinha e, ao trocar de
  conta corrente, copia os dados da nova.
- A regra de duplicidade não vale para caixinhas e não as conta: duas caixinhas na mesma conta podem existir,
  e uma conta comum repetida continua recusada. A mensagem de duplicidade explica a caixinha.
- Aplicação em outro banco continua igual: tipo Aplicação, caixinha desmarcada, dados do outro banco e a
  conta corrente ligada.
- Motor de cadastros (Definitions): `hide_if` (campo escondido e não exigido com a caixa marcada),
  `inherit` (campos copiados do registro apontado) e `unless` na regra `unique`. `only_if` trata caixa
  desmarcada como vazia, e o JavaScript do `only_if` desmarca a caixa em vez de apagar o valor dela.
- Saldos, transferências de aplicar/resgatar, rendimento, DRE e Balanço não mudam: a caixinha é uma
  aplicação como as outras.

## Junto (testes 6.3 e 6.4 do manual)

`tools/testar-main.sh` cria `CONNECT_BANK_VAULT_KEY` no `.env` do lab quando falta (como já fazia com a do
2FA) e nunca troca uma existente. Sem ela, "Testar e gravar" do Banco Inter falhava.

## Testes

- Integração `PatrimonialEntryScreensIntegrationTest::testPocketUsesTheCheckingAccountNumbers`.
- Unitário `InvestmentsTest::testPocketFollowsItsCheckingAccount`.
