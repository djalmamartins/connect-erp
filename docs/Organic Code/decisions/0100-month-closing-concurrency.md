# 0100 — Fechamento mensal sem corrida com a gravação de lançamentos

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (nona rodada)

## Contexto

O fechamento mensal (ADR 0031) grava a foto da competência e trava os lançamentos dela. A trava era
conferida por `RegistryService::isClosed`, uma leitura simples, feita antes da gravação e, no cadastro
genérico, fora de transação. Havia uma janela de corrida:

1. alguém grava um lançamento de outubro; a conferência diz "aberto";
2. no mesmo instante, outra pessoa fecha outubro; a foto é somada sem esse lançamento;
3. o lançamento é gravado numa competência já fechada, e a foto não bate com os lançamentos.

A reabertura também lia e gravava sem trava, então duas reaberturas ao mesmo tempo gravavam e
auditavam duas vezes.

## Decisão

- **Leitura travada da competência.** Dentro de uma transação, `isClosed` lê com
  `LOCK IN SHARE MODE`. Ela enxerga o fechamento já gravado por outra sessão e segura a linha do
  fechamento (ou o intervalo do índice único, quando ainda não há linha) até o fim da gravação. Fora de
  transação continua a leitura simples.
- **Cadastro genérico em transação.** `RegistryService::save` e `delete` abrem transação quando o
  cadastro tem `closing_lock` e conferem a competência dentro dela, junto da gravação. Os outros serviços
  que gravam lançamentos (emissão de faturas, acordos, atalhos, aplicação de importações, retorno
  bancário, lançamentos fixos) já conferem dentro da própria transação e passam a ter a leitura travada
  sem mudar o código. Dois caminhos conferiam fora da transação e foram corrigidos: a transferência entre
  contas (criar e cancelar), que agora grava e reconfere na mesma transação, e a preparação da fatura,
  que reconfere dentro da transação (e passa a desfazer a transação em qualquer erro, não só no de
  duplicidade).
- **Fechar grava a situação antes de somar.** `MonthClosing::close` trava a linha, grava "fechada",
  soma os lançamentos com leitura travada e só então grava os totais. Uma gravação que já conferiu a
  competência faz o fechamento esperar e entra na foto. Uma gravação que chega depois espera o
  fechamento e encontra o mês fechado.
- **Reabrir em transação.** `reopen` trava a linha antes de conferir e gravar.

## Consequências

- Duas operações ao mesmo tempo na mesma competência passam a esperar uma pela outra, por no máximo
  o tempo de uma gravação. Se a espera passar do `innodb_lock_wait_timeout`, a operação falha e pode ser
  repetida; a foto nunca fica errada.
- A mensagem de competência fechada no cadastro genérico continua a mesma. Agora ela só aparece depois
  das outras validações do formulário, como antes.
- `MonthClosingIntegrationTest::testCloseWaitsForAWriteThatAlreadyCheckedTheCompetence` usa duas
  conexões e cobre a espera e a foto.
- Primeira ADR da faixa 0100–0119 do Preparar. O `DecisionIndexTest` passa a exigir que o "Próximo
  número livre" fique acima do maior ADR até 0099 e não esteja em uso, em vez de acima do maior ADR de
  todos, porque de 0100 em diante cada frente segue a própria faixa.
