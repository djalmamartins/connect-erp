# Guia do financeiro e do operacional

Para quem opera o Connect ERP ou integra pela API (`/api/v1`). Descreve o ciclo da cobrança até a
prestação de contas e as rotinas do Operacional, na ordem em que o operador faz. Cada passo aponta a
rota, a regra que barra o erro comum e a ADR que decidiu. Os textos de erro citados são os do código
(`source/Services/Erp/`).

Situação: **CONFIRMADO** por código e testes no `claude-dev` de 09/11/2026 (até o ciclo 28: atalhos, importações e comunicação). O cenário de ponta a ponta
está em `tests/legacy/Integration/FinanceCycleIntegrationTest.php`, que roda só no lab
(`composer test:integration` com banco `*_test`). Banco real ainda não foi usado: o boleto roda no
adaptador sandbox (ADR 0035).

## Regras que valem para tudo

- **Sessão elevada.** Toda rota exige sessão com 2FA, e `X-CSRF-Token` em escrita (`ApiAuthenticated`).
- **Permissão.** Cada rota exige `erp.access` + `erp.<módulo>.view`, e `erp.<módulo>.manage` para escrever.
- **Escopo por condomínio.** O usuário só vê os condomínios liberados para ele (ADR-002). Um id de outro condomínio responde 404, como se não existisse. Quem tem `erp.condominiums.all` vê todos.
- **Valores em centavos.** Toda resposta traz inteiros em centavos (`50000` = R$ 500,00). A entrada aceita centavos ou texto em reais (`"500,00"`).
- **Competência.** O formato é `AAAA-MM` e as datas são `AAAA-MM-DD`, com validação estrita.
- **Auditoria.** Toda ação que muda algo é auditada.
- **Motivo.** Cancelar, reabrir ou quebrar pede um motivo de 5 a 255 caracteres.
- **Fechamento.** A competência fechada trava os lançamentos daquele mês. Nada que mexa nela passa sem reabrir (passo 7).

## Ciclo financeiro

### 0. Cadastros de base

O motor de cadastros (`/registries/...`, ADR 0014) cuida da base:
- plano de contas;
- tipos de fatura (conta do plano, recorrência e valor padrão);
- unidades com fração ideal;
- contas bancárias;
- lançamentos fixos.

Contas a pagar e a receber avulsas são `entries` no mesmo motor.

As regras de cobrança ficam em `GET/PUT /billing-rules` (ADR 0040). O padrão é:
- multa de 2%;
- juros de 1% ao mês, pro rata dia;
- correção pelo IPCA;
- quebra de acordo com parcela mais de 30 dias atrasada ou 2 parcelas em aberto.

Cada condomínio pode ter a própria regra. A resposta diz de onde ela veio em `source`.

### 1. Lançamentos fixos do mês

`POST /recurring-entries/generate` com `competence` gera os lançamentos dos modelos vigentes (ADR 0027):
- o vencimento cai no dia do modelo;
- gerar de novo não duplica;
- um modelo com problema vai para `failed` sem barrar os outros.

### 2. Fatura

Uma fatura cobra um tipo de fatura de todas as unidades ativas na competência (ADR 0033):

1. `POST /invoices` prepara o rascunho, com um item por unidade, pelo valor fixo (`fixed`) ou pelo rateio da fração ideal (`fraction`). Só existe uma fatura ativa por condomínio, tipo e competência (409).
2. `PATCH /invoices/{id}/items/{item}` ajusta o valor de uma unidade enquanto é rascunho.
3. `POST /invoices/{id}/issue` transforma cada item numa conta a receber da unidade. É tudo ou nada, e não emite duas vezes. Cada item deixa o aviso `invoice.issued` na fila.
4. `POST /invoices/{id}/cancel` só é aceito se:
   - nenhuma conta da fatura foi paga;
   - nenhuma conta da fatura está em acordo;
   - a competência está aberta.

   As contas geradas ficam canceladas.

As leituras de consumo (água, gás) também viram contas a receber (ADR 0045):
- `POST /meter-readings` grava a leitura e calcula o consumo pelas faixas do tipo. Uma leitura menor que a anterior é recusada.
- `POST /meter-readings/bill` cobra as leituras ainda não cobradas da competência.

### 3. Boleto

`POST /bank-charges` com `entry_id` e `bank_account_id` registra o título no banco (ADR 0035):
- A conta bancária precisa ser do mesmo condomínio da conta a receber.
- Pedir de novo devolve o mesmo título. Só um título cancelado libera um novo.
- O nosso número tem 10 dígitos, em sequência.

Os estados do título:
- `pending` (aguardando o banco);
- `registered`;
- `rejected`;
- `paid`;
- `cancelled`.

Se o banco não responde, o título fica `pending` e guarda o erro, sem virar registrado por suposição. `POST /bank-charges/{id}/refresh` consulta o banco de novo. `POST /bank-charges/{id}/cancel` cancela, mas não um título pago.

O registro deixa o aviso `bank_charge.issued` (ADR 0056).

### 4. Retorno do banco e conciliação

`POST /bank-returns` recebe o arquivo de retorno de uma conta bancária. Cada evento termina em um destes resultados:

| Resultado | Quando |
|---|---|
| `applied` | Pagamento do valor cheio, com data. Dá baixa na conta a receber com a data e a conta bancária do retorno, e o título vira `paid` |
| `divergence` | Valor diferente (o pagamento parcial não dá baixa), conta cancelada ou já baixada à mão, competência fechada ou pagamento sem data |
| `unknown_charge` | Nosso número que não é desta conta bancária |
| `duplicate` | Evento já processado |

O mesmo arquivo enviado de novo é reconhecido pelo hash e não muda nada (`duplicate_file`). O mesmo evento num arquivo novo é ignorado.

As divergências entram na fila `GET /bank-events/divergences`. A correção é feita à mão, no lançamento ou no título. Depois, `POST /bank-events/{id}/resolve` registra a nota de conferência.

O saldo de cada conta bancária está em `GET /bank-accounts/balances`.

### 5. Inadimplência e acordo

As contas vencidas em aberto, agrupadas por unidade e por faixa de atraso (1 a 30, 31 a 60, 61 a 90 e mais de 90 dias), aparecem no painel e na tela de acordos (ADR 0039).

`GET /agreements/suggestion?unit_id=..&entry_ids=1,2` simula os encargos de cada conta pela regra de cobrança (ADR 0040):
- a correção usa o índice dos meses entre o vencimento e o mês anterior;
- a multa incide sobre o valor corrigido;
- os juros são pro rata, também sobre o valor corrigido.

Um mês sem índice publicado entra como zero e aparece em `missing_index`.

`POST /agreements` cria o acordo com até 60 parcelas e até 120 contas. Na mesma transação:
- as contas originais ficam canceladas;
- as parcelas viram contas a receber novas;
- sai o aviso `agreement.created`.

A criação recusa conta:
- de outra unidade;
- de competência fechada;
- com boleto ativo;
- já paga;
- já em outro acordo.

Um id de outro condomínio responde como inexistente: "Alguma conta escolhida não existe." (revisão de segurança de 09/11).

Para encerrar um acordo:
- **Cancelar** (`POST /agreements/{id}/cancel`): só sem parcela paga e sem boleto ativo. As contas originais voltam a valer.
- **Quebrar** (`POST /agreements/{id}/breach`): é sempre manual, com motivo, e só é aceito quando a regra de quebra indica. O sistema não quebra o acordo sozinho. As parcelas ficam como estão, e sai o aviso `agreement.breached`.

### 6. Documentos da unidade

`POST /unit-documents` emite um dos documentos abaixo (ADR 0044):

| Documento | Regra |
|---|---|
| Certidão de Débitos | Sempre emite: "nada consta", ou a lista das contas vencidas com encargos |
| Declaração de Inadimplência | Só com conta vencida em aberto |
| Comprovante de Quitação | Só com tudo pago até a competência |
| Carta de Anuência | Só sem conta vencida |

O conteúdo fica gravado com um código de 16 caracteres e não muda depois de emitido. `GET /unit-documents/verify/{code}` confere a autenticidade, ainda com login (a verificação pública está pendente, issue #46).

### 7. Fechamento da competência

`GET /closings?condominium_id=..&competence=AAAA-MM` mostra o resumo calculado:
- receitas, despesas e o que está em aberto;
- o resultado do mês;
- o total por conta do plano e por conta bancária.

`POST /closings` fecha a competência (ADR 0031): grava a foto do resumo e trava os lançamentos do mês. A partir daí:
- não se fatura nem se cancela fatura nessa competência ("A competência ... está fechada");
- não se cria acordo com conta dessa competência;
- o retorno do banco que pagaria conta desse mês vira divergência.

`POST /closings/reopen` reabre a competência, com motivo.

### 8. Relatórios e prestação de contas

Os relatórios ficam em `GET /reports/...` (ADR 0037):

- **`GET /reports/trial-balance?from=AAAA-MM&to=AAAA-MM`** é o balancete por competência, com no máximo 24 meses. Para cada conta do plano, mostra o previsto, o realizado e o em aberto.
- **`GET /reports/statement?from=AAAA-MM-DD&to=AAAA-MM-DD`** é a prestação de contas por caixa, com no máximo 366 dias. Entra o que foi pago no período, pela data de pagamento. Mostra o total por conta do plano e a conciliação de cada conta bancária: saldo anterior, entradas, saídas e saldo final.

Sem `condominium_id`, os relatórios somam todos os condomínios do escopo do usuário. Fora do escopo, a resposta é acesso negado. Um usuário sem nenhum condomínio recebe a lista vazia.

### Atalhos de lançamento

São atalhos sobre os lançamentos (ADR 0057 e 0062), e todos respeitam o fechamento (passo 7):

- **Desconto** (`POST /entries/{id}/discount`, com valor e motivo): reduz uma conta a receber em aberto e guarda o antes e o depois em `GET /entries/{id}/adjustments`. Não zera a conta (para perdoar, cancele). Também não vale em parcela de acordo nem com boleto ativo, porque o valor no banco ficaria diferente. Para o boleto sair com desconto, dê o desconto antes de emitir.
- **Multa avulsa e cobrança extra** (`POST /extra-charges`, `kind` `fine` ou `extra`): cria uma conta a receber por unidade escolhida, até 1.000 unidades. O valor pode ser fixo por unidade, o total dividido igualmente ou o total rateado pela fração ideal. As contas ficam travadas no cadastro e se cancelam pela origem (`POST /extra-charges/{id}/cancel`).
- **Transferência entre contas** (`POST /bank-transfers`): move saldo entre duas contas bancárias do mesmo condomínio. Não é receita nem despesa. Entra nos saldos e na conciliação da prestação de contas, onde soma zero.

O recibo de uma conta paga sai em Documentos da unidade (passo 6), e o código confere o recibo.

### Importações

Planilhas e extratos entram em duas etapas (ADR 0058 e 0063):

1. **`POST /imports/preview`** recebe o arquivo (`multipart`, até 2 MB) e o tipo. Lê e valida todas as linhas contra os cadastros do condomínio e mostra o erro de cada uma. Nada é gravado.
2. **`POST /imports/{id}/apply`** relê o arquivo guardado, valida tudo de novo e, sem nenhum erro, grava tudo numa transação só. Uma linha com erro bloqueia o arquivo inteiro.

| Tipo | Colunas obrigatórias | Permissão de destino |
|---|---|---|
| `entries` | natureza, descricao, valor, competencia, vencimento, conta | `erp.entries.manage` |
| `unit_entries` | unidade, descricao, valor, competencia, vencimento, conta | `erp.entries.manage` |
| `payables` | descricao, valor, competencia, vencimento, conta | `erp.entries.manage` |
| `delinquents` | unidade, descricao, valor, competencia, vencimento, conta | `erp.entries.manage` |
| `fractions` | unidade, fracao (o arquivo traz todas as unidades ativas e a soma dá 1) | `erp.units.manage` |
| `readings` | relogio, tipo, competencia, leitura (e `data_leitura`, opcional) | `erp.meter_readings.manage` |
| `ofx` | extrato do banco, com `bank_account_id` de uma conta do condomínio | `erp.entries.manage` |

Regras que valem para todos os tipos:
- CSV com `;` ou XLSX, com no máximo 5.000 linhas (o OFX, 5.000 transações). O XLSX tem no máximo 200 colunas e é lido pelo valor gravado: fórmula nunca é calculada, e `DOCTYPE` é recusado.
- Valores com até 10 dígitos inteiros.
- O mesmo arquivo (mesmo conteúdo) não entra duas vezes no mesmo condomínio e tipo.
- O `condominium_id` vem da requisição, nunca do arquivo.

Por tipo:
- **Leituras.** Seguem as regras do lançamento manual (passo 2, ADR 0045): a leitura não pode ser menor que a anterior e não pode haver leitura posterior. Reenviar a mesma competência substitui a leitura (`replace` na prévia). Leitura já cobrada não muda mais.
- **Extrato OFX.** Não cria nem baixa lançamento: só concilia cada linha com uma conta já paga da mesma conta bancária e do mesmo valor, ou com uma transferência. Um FITID já importado é ignorado (`already`). O que não bate fica em `GET /bank-statement-lines`, para resolver à mão com `POST /bank-statement-lines/{id}/resolve` (vincular a um lançamento ou ignorar, com nota). Quando o arquivo traz `LEDGERBAL`, a prévia mostra a diferença entre o saldo do banco e o calculado, sem ajustar nada (`balance_check`).

## Operacional

### Cadastros do dia a dia

Áreas comuns, reservas, manutenções, seguros, contratos, ocorrências e entregas da portaria são cadastros do motor (ADR 0042, 0050 e 0051). Eles seguem as mesmas regras de permissão e escopo, e aceitam anexos do Disco Virtual do mesmo condomínio (ADR 0052).

### Dashboard e painéis

- **`GET /dashboard`** mostra os números do dia (ADR 0053):
  - manutenções atrasadas e da semana;
  - seguros e contratos a renovar em 30 dias ou vencidos;
  - reservas aguardando aprovação;
  - ocorrências abertas;
  - entregas aguardando retirada;
  - reservas canceladas com lista de espera.

  Os cartões de valores (a receber vencido e contas da semana) só aparecem para quem tem `erp.entries.view` (ADR 0061).
- **`GET /panels/condominium/{id}` e `/panels/unit/{id}`** reúnem num lugar só o resumo do condomínio e o da unidade (ADR 0054). Cada painel exige a permissão de cada módulo que mostra, senão responde 403 (ADR 0061):
  - o painel do condomínio pede unidades, contas bancárias, acordos e fechamento;
  - o painel da unidade pede unidades, moradores, acordos, lançamentos e documentos da unidade.

### Continuações

- **`POST /reservations/{id}/call-waitlist`** chama o primeiro da lista de espera de uma reserva cancelada e registra quem chamou e quando. Não cria a reserva sozinho. É recusado se:
  - a data já tem outra reserva válida;
  - não há ninguém na fila.

  Sai o aviso `waitlist.called`.
- **`POST /maintenances/{id}/next`** gera a próxima manutenção de uma concluída, pela periodicidade (mensal, trimestral, semestral ou anual), uma vez só. Uma manutenção única não tem próxima.
- **`POST /occurrences/{id}/ticket`** abre um chamado no Help Desk a partir da ocorrência, com condomínio, unidade, assunto e prioridade (ADR 0034). Cada ocorrência abre um chamado só.

### Disco Virtual

`/documents` guarda atas, contratos, plantas, notas fiscais e outros arquivos do condomínio (ADR 0047):
- o tipo é conferido pelo conteúdo, até 20 MB;
- o arquivo fica fora da pasta pública;
- o download sai como anexo;
- a exclusão é lógica.

### Comunicação

Comunicados e avisos de cobrança saem pela fila de avisos, nunca direto (ADR 0059 e 0065). Cada unidade vira um aviso na fila.

- **Comunicado geral** (`POST /announcements`): assunto de 5 a 120 caracteres, em uma linha só, e texto de 10 a 5.000. Vai para todas as unidades ativas ou para as escolhidas (até 2.000). Recebem as pessoas ativas da unidade com e-mail que não recusaram comunicado (campo "Não quer receber comunicados por e-mail" no cadastro de pessoas).
- **Aviso de cobrança** (`POST /announcements/collection`): vai para as unidades com conta vencida há pelo menos `min_days_late` dias (padrão 1), e só para quem autorizou cobrança por e-mail. O e-mail traz a quantidade de contas, o valor sem encargos e o vencimento mais antigo. A unidade avisada nos últimos 7 dias fica de fora (`skipped_recent`). A unidade em que ninguém autorizou não conta como avisada, mas também não recebe nada. Exige também `erp.agreements.view`.
- **Agendamento:** `send_at` vazio é agora; agendado, vai até 60 dias à frente. `GET /announcements?scheduled=1` lista os que ainda vão sair. `POST /announcements/{id}/cancel`, com motivo, cancela o comunicado e o que ainda não saiu; os já enviados ficam como estão.
- **Endereços** (`GET /communication-addresses`): por unidade, quem recebe comunicado e quem recebe cobrança, com o e-mail mascarado. Os totais mostram as unidades sem ninguém para cada um. A correção é feita no cadastro de pessoas. Exige também `erp.residents.view`.
- **Limite:** até 20 comunicados e avisos por condomínio em 24 horas. Passou disso, a resposta é 409.

Cada comunicado mostra a situação da entrega: pendentes, enviados, com falha, cancelados e o total de destinatários.

### Fila de avisos por e-mail

Os eventos do ERP entram na fila (`erp_notification_outbox`) sem enviar nada na hora (ADR 0054, 0055 e 0056):

| Evento | Quem recebe |
|---|---|
| `invoice.issued`, `bank_charge.issued`, `agreement.created`, `agreement.breached`, `collection.reminder` | Quem autorizou cobrança por e-mail na unidade |
| `announcement.published` | Quem não recusou comunicado |
| `reservation.approved`, `waitlist.called` | As pessoas da unidade com e-mail |

São até 5 pessoas por unidade. A mesma pessoa não recebe o mesmo aviso duas vezes, e a trilha guarda o e-mail em hash e mascarado. Aviso sem ninguém para receber termina como `cancelled`, com o motivo.

Modo de entrega:
- **`log`** (padrão): só registra no log, nada sai.
- **`sandbox`:** tudo vai para a caixa de teste (`ERP_MAIL_SANDBOX_TO`), com `[TESTE]` no assunto. Sem essa caixa, cai para `log`.
- **`production`:** só com `APP_ENV=production` e `ERP_MAIL_DELIVERY=production`. Fora de produção, nenhum e-mail sai para morador, qualquer que seja a configuração.

O despacho pega até 500 avisos por rodada, só os que já chegaram na hora (`available_at`). Ele tenta até 5 vezes, com espera crescente de 1, 5, 15, 60 e 240 minutos; esgotadas as tentativas, o aviso fica `failed`. As credenciais SMTP vêm do ambiente (`MOVESOS_SMTP_*`, ADR 0060).

Para acompanhar:
- `GET /notifications?status=` lista os avisos do escopo (`pending`, `processing`, `sent`, `failed`, `cancelled`);
- `GET /notifications/{id}` mostra a trilha de entrega;
- `POST /notifications/{id}/retry` devolve à fila um aviso que falhou ou foi cancelado;
- `POST /notifications/dispatch` processa a fila de todos os condomínios e exige `erp.condominiums.all` (ADR 0061).

## Onde conferir

| Tema | Teste de integração (lab) |
|---|---|
| Ciclo completo | `FinanceCycleIntegrationTest` |
| Fatura | `InvoicesIntegrationTest` |
| Boleto e retorno | `BankingIntegrationTest`, `BankReturnFilesIntegrationTest` |
| Acordos e encargos | `AgreementsIntegrationTest`, `LateChargesIntegrationTest` |
| Fechamento | `MonthClosingIntegrationTest` |
| Relatórios | `FinancialReportsIntegrationTest` |
| Leituras e documentos | `MeterReadingsIntegrationTest`, `UnitDocumentsIntegrationTest` |
| Operacional | `OperationalDashboardIntegrationTest`, `OperationalRegistriesIntegrationTest`, `GatehouseIntegrationTest` |
| Painéis e avisos | `PanelSummariesIntegrationTest`, `NotificationDispatcherIntegrationTest` |
| Atalhos e recibo | `EntryShortcutsIntegrationTest`, `ShortcutsCycleIntegrationTest` |
| Importações e extrato | `ImportsIntegrationTest`, `ImportsCycleIntegrationTest` |
| Comunicação | `AnnouncementsIntegrationTest`, `CommunicationsCycleIntegrationTest` |
| Operacional de ponta a ponta | `OperationsCycleIntegrationTest` |
| Disco Virtual | `VirtualDiskIntegrationTest` |

O contrato completo das rotas está em `docs/api/openapi.yaml`. Os riscos de segurança que ainda
ficam estão em `docs/security/REVIEW_API_FINANCEIRO.md` e `docs/security/REVIEW_COMUNICACAO.md`.

## Lacunas

- **Banco real.** Ainda não há banco real homologado. O boleto e o retorno usam o adaptador sandbox, e emitir título real depende de aprovação explícita.
- **Pix e CNAB.** Pix e CNAB de um banco específico ainda não estão implementados (`docs/banking/BANKING_INTEGRATIONS.md`).
- **Integração no CI.** A suíte de integração não roda no CI. Ela precisa rodar no lab antes de cada entrega na main.
