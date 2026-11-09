# Revisão de segurança: API financeira, chamados e Disco Virtual (09/11/2026)

Escopo: os controllers de `source/Controllers/Api/V1/` para faturas (`Invoices`), boletos e retornos
(`Banking`), inadimplência e acordos (`Agreements`), regras de cobrança e índices (`BillingRules`),
documentos da unidade (`UnitDocuments`), leituras (`MeterReadings`), chamados (`Tickets`) e Disco
Virtual (`VirtualDisk`), com os serviços de `source/Services/Erp/` e `Support/TicketService` que
eles chamam. Revisão por leitura de código, no `claude-dev` de 09/11. Nada foi testado contra um
servidor rodando.

## O que foi conferido

| Ponto | Como está | Situação |
|---|---|---|
| Sessão, 2FA e CSRF | Toda rota de `/api/v1` (exceto `/health` e `/auth/2fa/verify`) passa pelo `ApiAuthenticated`, que exige sessão elevada e `X-CSRF-Token` em método que não é GET, HEAD ou OPTIONS | CONFIRMADO |
| Permissão | Cada controller exige `erp.access` + `<módulo>.view`, e `<módulo>.manage` em toda escrita (a escrita passa por `write()`/`authorize('manage')`) | CONFIRMADO |
| Escopo por condomínio (IDOR) | Busca por id carrega o registro e confere `CondominiumScope::canAccess`. Fora do escopo responde 404, sem dizer que o registro existe. As listagens filtram por `allowedIds` | CONFIRMADO |
| Referências cruzadas | Conta bancária do boleto do mesmo condomínio da conta a receber. Retorno só casa títulos da conta bancária enviada. Item da fatura da mesma fatura. Tipo de fatura do condomínio. Unidade do chamado do condomínio escolhido. Campos `ref` dos cadastros (plano de contas etc.) validados por `RegistryService::refRules` | CONFIRMADO |
| Entrada | Ids por `FILTER_VALIDATE_INT`, datas com `createFromFormat` estrito, competência AAAA-MM, texto com limite de tamanho, SQL parametrizado (os `IN (...)` usam só inteiros já convertidos) | CONFIRMADO |
| Disco Virtual | Tipo pelo conteúdo (finfo) numa lista fechada e batendo com a extensão, limite de 20 MB, `is_uploaded_file`, nome gerado pelo sistema fora da pasta pública, caminho do download validado por regex (sem `..`), download como anexo com `nosniff` e CSP `sandbox`, exclusão lógica auditada | CONFIRMADO no serviço. As rotas `/documents` ainda não estão no `index.php` (o Avançar está ligando) |

## Achado corrigido

**Acordos: id de conta de outro condomínio era distinguível de id inexistente, e a criação travava
linhas de outro condomínio.** `Agreements::suggestion` e `Agreements::create` buscavam as contas
por `WHERE id IN (...)` sem filtrar o condomínio.

O resultado para um usuário com `erp.agreements.view` era este:
- um id de outro condomínio respondia "Conta N não é desta unidade";
- um id inexistente respondia "Alguma conta escolhida não existe".

Isso permite enumerar quais ids de lançamento existem fora do escopo. Os dados em si não vazavam.
Além disso, o `FOR UPDATE` da criação travava essas linhas alheias durante a transação, e a lista
de ids não tinha limite.

Correção:
- as duas consultas agora filtram `condominium_id` da unidade, então id de outro condomínio responde igual a id inexistente;
- os ids passam por `Agreements::entryIds()`: inteiros positivos, sem repetição, no máximo `MAX_ENTRIES` = 120.

Testes: unitário `AgreementsTest::testEntryIdsAreNormalizedAndCapped`. Integração
`AgreementsIntegrationTest::testRefusalsAndScope`, que precisa rodar no lab.

## Riscos e lacunas que ficam

- **Verificação de documento da unidade** (`GET /unit-documents/verify/{code}`): exige sessão, permissão e escopo. Não é verificação pública. Se um dia virar pública, vai precisar de limite de tentativas.
- **Chamados**: `GET /tickets/{id}` também mostra o chamado a quem o abriu ou é o solicitante, mesmo sem escopo no condomínio. É o comportamento atual (`TicketService::findFor`) e fica registrado aqui: confirmar se é o desejado.
- **Mensagens de validação de outros serviços** podem ter oráculos parecidos com o dos acordos. A revisão olhou os pontos com lista de ids. Uma varredura por teste automático (escopo × id alheio para cada rota) seria mais segura e fica para os testes de integração dos ciclos 8 a 16.
- **Limite de requisições na API**: não existe no `ApiAuthenticated`. Upload e importação de retorno têm limite de tamanho, mas não de frequência.

## Segunda rodada: Disco Virtual ligado, anexos, Operacional, dashboard e avisos (ciclos 18 a 22)

Escopo: rotas `/documents` (agora no `index.php`); anexos dos cadastros
(`RegistryService::attachmentRules`); `POST /occurrences/{id}/ticket`; `GET /dashboard`;
`POST /reservations/{id}/call-waitlist` e `/maintenances/{id}/next`; `GET /panels/*`; e
`/notifications`.

| Ponto | Situação |
|---|---|
| Disco Virtual com rotas no `ApiAuthenticated`, `view`/`manage` e escopo em toda busca por id | CONFIRMADO |
| Anexo de cadastro só aceita arquivo não excluído do mesmo condomínio do registro | CONFIRMADO |
| Ocorrência → chamado, lista de espera e próxima manutenção: carregam o registro e conferem o escopo antes de agir | CONFIRMADO |
| Avisos: listar, ver e reenviar por escopo. Destinatários vêm de moradores da mesma unidade e condomínio do evento | CONFIRMADO |

### Achados corrigidos (ADR 0061)

1. **Painéis com dados de módulos sem permissão.** O painel do condomínio e o da unidade pediam só
   `erp.units.view`, mas mostravam saldos bancários, inadimplência, acordos, fechamento, contas a
   vencer, moradores com contato e documentos emitidos. Agora exigem a permissão de cada um desses
   módulos (403 / mensagem na tela).
2. **Dashboard com valores do financeiro para qualquer usuário do ERP.** Os cartões de contas a
   receber e a pagar agora só aparecem para quem tem `erp.entries.view`.
3. **Disparo da fila de avisos de todos os condomínios por usuário com escopo parcial.** Agora
   exige `erp.condominiums.all`.

### Lacunas

- A OpenAPI ainda não descreve o 403 novo de `/panels/*` e de `/notifications/dispatch`.
- `GET /dashboard` continua mostrando contagens operacionais (manutenções, ocorrências, entregas)
  a quem só tem `erp.access`. São contagens sem dado pessoal, mas seguem sem permissão de módulo.

## Terceira rodada: lançamentos avulsos, recibo e importações (ciclo 24)

Escopo: as rotas `/entries/{id}/discount`, `/entries/{id}/adjustments`, `/extra-charges` e
`/bank-transfers` (`Controllers/Api/V1/EntryShortcuts`, `Services/Erp/EntryShortcuts`), o recibo
(`UnitDocuments::receipt`) e a soma das transferências nos saldos e na prestação de contas. As
importações de OFX e de planilha ainda não estão no código; para elas fica a lista de requisitos
abaixo.

| Ponto | Situação |
|---|---|
| Rotas no `ApiAuthenticated`. Desconto e transferência pedem `erp.entries.*`, multa e cobrança extra pedem `erp.invoices.*`, e toda escrita pede `.manage` | CONFIRMADO |
| Escopo em toda busca por id e na criação (condomínio, unidades, contas bancárias, conta de referência da multa e conta do plano do padrão ou do próprio condomínio) | CONFIRMADO |
| Desconto: só conta a receber em aberto, nunca zera a conta, recusado em acordo, com boleto ativo ou com competência fechada. Trilha com antes, depois e motivo | CONFIRMADO |
| Cobrança e transferência canceláveis só pela origem, com motivo, respeitando o fechamento | CONFIRMADO |
| Transferência só entre duas contas ativas do mesmo condomínio, com data até hoje. Soma zero na conciliação | CONFIRMADO |
| Verificação do recibo pelo código: exige sessão, permissão e escopo. Não é pública | CONFIRMADO (verificação pública segue pendente, issue #46) |

### Achados corrigidos (ADR 0062)

1. **Recibo com oráculo de id de outro condomínio**, o mesmo padrão já corrigido nos acordos. A busca agora filtra o condomínio da unidade.
2. **`Invoices::cents` sem teto.** Um texto longo virava erro 500 e um inteiro enorme passava pela validação. Agora vale o teto do motor de cadastros.
3. **Cobrança extra sem limite de unidades.** Agora são no máximo 1000 unidades.

### Lacunas

- **Fechamento contra transferência simultânea.** Transferência e cancelamento de transferência conferem o fechamento fora de transação. Um fechamento no mesmo instante pode deixar passar uma transferência na competência recém-fechada. A janela é pequena e o caso fica na auditoria.
- **Desconto sem limite próprio.** Quem tem `erp.entries.manage` pode dar desconto de qualquer valor menor que a conta. Isso segue a regra aprovada na ADR 0057, e a trilha registra cada desconto. Se a Connect quiser alçada (teto por perfil), será uma decisão nova.

### Requisitos para as importações de OFX e planilha (ainda não implementadas)

Para revisar quando o código chegar:

- **Upload:**
  - `is_uploaded_file`;
  - tipo pelo conteúdo (finfo) numa lista fechada;
  - limite de tamanho (o retorno bancário usa o do PHP; definir um próprio);
  - arquivo guardado fora da pasta pública, ou só processado em memória.
- **Repetição:** o mesmo arquivo enviado de novo é reconhecido pelo hash, como no retorno do banco, e uma linha já importada é ignorada.
- **OFX 2 (XML):**
  - parser sem entidades externas e sem rede (XXE);
  - limite de profundidade e de número de transações.
- **OFX 1 (SGML):** parser próprio com limite de linhas, nunca `eval` nem regex com retrocesso exponencial.
- **Planilhas:**
  - XLSX é um ZIP, então limitar o tamanho descompactado e o número de entradas (zip bomb);
  - ler só os valores, nunca avaliar fórmulas;
  - limitar o número de linhas.
- **Fórmulas na exportação:** células que começam com `=`, `+`, `-`, `@`, tabulação ou retorno de carro saem com `'` na frente em CSV e XLSX gerados pelo sistema. Vale também para o texto vindo de importação.
- **Escopo:**
  - a importação vale para uma conta bancária ou um condomínio no escopo do usuário;
  - toda linha cai nesse condomínio, sem aceitar `condominium_id` vindo do arquivo;
  - o resultado é auditado com arquivo, hash, quem importou e quantas linhas.
- **Frequência:** continua não havendo limite de requisições na API (lacuna da primeira rodada). Importação é o caso mais caro e o primeiro a precisar dele.

## Quarta rodada: importações de OFX e planilha (ciclo 26)

Escopo: as rotas `/imports`, `/imports/preview`, `/imports/{id}/apply` e `/bank-statement-lines`
(`Controllers/Api/V1/Imports`) e os serviços `Services/Erp/Imports` (`Imports`, `OfxParser`,
`SpreadsheetReader` e `ImportRules`), conferidos contra a lista de requisitos da terceira rodada.

| Requisito | Situação |
|---|---|
| Upload: `is_uploaded_file`, até 2 MB, extensão por tipo e assinatura do conteúdo (`PK` no XLSX, sem byte nulo em CSV e OFX). Nome do arquivo limpo | CONFIRMADO |
| Arquivo em `storage/private/imports`, com `.htaccess` negando acesso. Caminho gravado validado por regex ao reler | CONFIRMADO |
| Repetição: hash único por condomínio e tipo. Arquivo já aplicado é recusado. A aplicação relê e confere o hash, revalida tudo e grava numa transação, com trava na linha da importação | CONFIRMADO |
| OFX: lido por expressão, sem parser XML (sem XXE), com até 5000 transações. Repetição de FITID por conta é barrada por chave única | CONFIRMADO |
| XLSX: `DOCTYPE` recusado e `LIBXML_NONET`, com até 5000 linhas. Fórmulas entram só pelo valor gravado, nunca avaliadas. Zip bomb | CONFIRMADO, com os ajustes do achado 2 |
| Escopo: condomínio do usuário, conta bancária do mesmo condomínio, linhas validadas contra os cadastros daquele condomínio (`condominium_id` não vem do arquivo). Busca por id fora do escopo dá 404 | CONFIRMADO |
| Permissão: `erp.imports.*` mais a do destino (`erp.entries.manage` ou `erp.units.manage`) na prévia e na aplicação. Conciliar uma linha pede `erp.entries.manage` | CONFIRMADO |
| O OFX não cria nem baixa lançamento: só concilia com conta paga da mesma conta bancária, e o que não bate fica pendente | CONFIRMADO |

### Achados corrigidos (ADR 0063)

1. **Valores do OFX e da planilha sem teto.** Um número de 20 dígitos dava erro 500, como em `Invoices::cents`. Agora valem até 10 dígitos e `MAX_CENTS`.
2. **XLSX esparso e tamanho do ZIP.** Uma célula `XFD1` ou uma linha `1048576`, em poucos bytes, esgotava a memória. Agora a planilha tem limite de colunas (200) e de posição de linha (20.000), e a leitura das partes do ZIP para em 30 MB, mesmo que o arquivo declare menos.

### Lacunas

- **Injeção de fórmula na exportação.** O ERP ainda não exporta CSV ou XLSX. Quando exportar, as células que começam com `=`, `+`, `-`, `@`, tabulação ou retorno de carro precisam sair com `'` na frente, inclusive o texto que veio de uma importação (descrição, histórico do OFX).
- **Quem vê o extrato importado.** As linhas pendentes e o detalhe da importação pedem só `erp.imports.view`, e mostram o histórico do extrato (nomes de quem pagou). Se `erp.imports.view` for dado a perfis sem acesso ao financeiro, vale exigir também `erp.entries.view`. Fica como decisão de perfil.
- **Duas conciliações ao mesmo tempo para o mesmo lançamento.** A chave única em `entry_id` barra a segunda, mas ela responde 500 em vez de 409.
- **XML em UTF-16.** A checagem de `DOCTYPE` é por texto e um XML em UTF-16 passaria por ela. Fica protegido pelo padrão do libxml 2.9 ou mais novo, que não carrega entidades externas (não se usa `LIBXML_NOENT`).
- **Frequência.** Continua sem limite de requisições na API. A prévia lê e valida o arquivo inteiro a cada envio.

### Ciclo 27: importação de leituras e conferência do saldo do OFX

| Ponto | Situação |
|---|---|
| Importação de leituras: tipo `readings` com permissão de destino `erp.meter_readings.manage`. Relógio buscado só entre os ativos do condomínio da importação | CONFIRMADO |
| Leitura com até 9 dígitos inteiros e 3 casas (`MeterReadings::parseReading`), dentro do `decimal(12,3)` | CONFIRMADO |
| Mesmas regras do lançamento manual: não menor que a anterior, competência não cobrada e aberta, sem leitura posterior, data até hoje. A aplicação grava por `MeterReadings::record`, que revalida e confere o escopo, na transação da importação (tudo ou nada) | CONFIRMADO |
| Saldo do OFX: só mostra a diferença entre o saldo do banco e o calculado, nunca ajusta. O valor do `BALAMT` passa pelo teto do achado 1 | CONFIRMADO |

Nada novo a corrigir. Fica uma lacuna junto à de "quem vê o extrato importado": a conferência do saldo grava o saldo calculado da conta no resumo da importação. Esse resumo aparece a quem tem `erp.imports.view`, mesmo sem `erp.bank_accounts.view`. Na prévia, o saldo só aparece com `erp.entries.manage`, porque o tipo OFX exige essa permissão.

Teste de ponta a ponta: `ImportsCycleIntegrationTest`.
- O saldo do extrato bate com o calculado depois da baixa, da transferência e da despesa.
- As leituras por XLSX viram conta a receber da unidade na cobrança.

## Quinta rodada — Cobrança e multas (ADR 0080)

Data: 2026-11-09 · Código revisado: `claude-dev` em 49eda4f · Correções: ADR 0066

Escopo: `source/Services/Erp/Collections.php`, `source/Controllers/Api/V1/Collections.php`, a origem
da multa em `EntryShortcuts::createCharge` e a migration 20261108_0538.

| Ponto | Situação |
|---|---|
| Permissões: `erp.access` + `erp.collections.view` para ler e `.manage` para gravar. A fila e a abertura do caso pedem também `erp.agreements.view`. CSRF e 2FA no `ApiAuthenticated` | CONFIRMADO |
| Escopo: unidade, caso e contatos buscados pelo id e conferidos com `CondominiumScope::canAccess`; fora do escopo dá 404. As listas filtram pelos condomínios do usuário | CONFIRMADO |
| O caso judicial não envia nada a advogado ou tribunal, não cria lançamento nem encargo e não trava conta. O teste confere a contagem de lançamentos e de avisos na fila antes e depois | CONFIRMADO |
| Um caso aberto por unidade, por chave única com coluna gerada; a segunda abertura responde 409, não 500 | CONFIRMADO |
| Transições de situação por tabela fixa; encerrado é final e guarda o motivo | CONFIRMADO, com a trava do achado 2 |
| Origem da multa: ocorrência do mesmo condomínio (da unidade ou sem unidade) ou reserva da própria unidade, conferidas ao lançar. A multa é de uma unidade só | CONFIRMADO |
| Contato é histórico (sem rota de edição ou exclusão). Promessa até 90 dias, próxima ação até 180, valor prometido pelo teto de `Invoices::cents` | CONFIRMADO |
| Advogado do cadastro AP-016 (global, sem condomínio), só ativo | CONFIRMADO |
| SQL: valores por parâmetro; as listas de ids interpoladas são inteiros já convertidos; os nomes de coluna do `UPDATE` do caso vêm de chaves fixas do código | CONFIRMADO |

### Achados corrigidos (ADR 0066)

1. **Conta de outro condomínio na abertura do caso.** A busca era só pelo id, com `FOR UPDATE`, e a
   mensagem para conta de outra unidade era diferente da conta inexistente: dava para saber que um id
   existe em outro condomínio, e a linha dele ficava travada. Agora a busca filtra pela unidade e pelo
   condomínio, e a lista de ids é validada antes (1 a 200, inteiros positivos).
2. **Dois andamentos ao mesmo tempo.** A situação era lida fora da transação, e um encerramento
   podia ser desfeito por um andamento simultâneo. Agora a situação é relida com trava e, se mudou,
   a resposta é 409.
3. **Nome do contato com quebra de linha e caracteres de controle.** Agora é uma linha só.

### Lacunas

- **Dado pessoal no atendimento.** As notas do contato e o nome de quem atendeu aparecem a quem tem
  `erp.collections.view`. É dado de cobrança de pessoa física (LGPD): vale dar a permissão só ao
  perfil de cobrança.
- **Valor prometido sem relação com a dívida.** A promessa aceita qualquer valor até o teto, mesmo
  acima do que está vencido. Não muda nada no financeiro; fica só como registro.
- **Conta em caso e em acordo.** Por decisão do ADR 0080, o caso não trava as contas. Uma conta do
  caso pode entrar depois num acordo; o detalhe do caso mostra a situação de cada conta.
- **Frequência.** Continua sem limite de requisições na API.

Teste de ponta a ponta: `CollectionsCycleIntegrationTest`.

## Sexta rodada — Relatórios de cobrança (ADR 0081)

Data: 2026-11-09 · Código revisado: `claude-dev` em e653430 · Correções: ADR 0067

Escopo: `source/Services/Erp/CollectionReports.php` e `source/Controllers/Api/V1/CollectionReports.php`.

| Ponto | Situação |
|---|---|
| Permissões: `erp.access` mais `erp.agreements.view` (acordos e inadimplentes) ou `erp.entries.view` (recebimentos). A visão por morador exige também `erp.residents.view`, inclusive no CSV | CONFIRMADO |
| Escopo: o condomínio informado passa por `assertAccess` (404 fora do escopo). Sem condomínio, entram só os do escopo do usuário. Inadimplentes de um condomínio de fora voltam vazios, pela mesma regra da inadimplência, sem vazar dados. As consultas por conta do plano e por morador usam só ids que vieram da inadimplência já filtrada | CONFIRMADO; o teste confere as 5 visões e os 3 agrupamentos |
| Máscara: o CPF ou CNPJ do responsável sai com só os 2 últimos dígitos, no JSON e no CSV | CONFIRMADO |
| CSV: BOM, `;`, CRLF, apóstrofo na célula perigosa, inclusive no cabeçalho; `nosniff`, `no-store` e anexo com nome fixo; a exportação fica na auditoria | CONFIRMADO, com o achado 1 |
| SQL: período e filtros por parâmetro; as listas de ids interpoladas são inteiros já convertidos; período de no máximo 366 dias | CONFIRMADO |
| Custo: acordos pelo índice `(condominium_id, status, created_at)`, recebimentos pelo `(condominium_id, paid_on)` do ADR 0064 | CONFIRMADO, com o achado 2 |

### Achados corrigidos (ADR 0067)

1. **Fórmula depois de espaço, em largura total ou depois de LF.** `" =1+1"`, `"\u{FEFF}=x"`,
   `"＝HYPERLINK(...)"` e uma célula que começa com quebra de linha passavam sem apóstrofo. Agora
   todos recebem o apóstrofo.
2. **Recebimentos sem teto.** Um ano de todos os condomínios era lido inteiro na memória. Agora o
   teto é de 20.000 linhas, e acima disso a resposta é 422.

### Lacunas

- **E-mail e telefone completos na visão por morador.** Só o documento é mascarado. Quem tem
  `erp.residents.view` já vê esses dados no cadastro, e a exportação fica na auditoria. Mesmo
  assim, um CSV com contatos de inadimplentes é dado pessoal sensível de cobrança (LGPD): vale
  limitar a visão ao perfil de cobrança.
- **Leitura em JSON não é auditada.** Só o CSV deixa trilha.
- **Sem paginação.** Inadimplentes e acordos voltam inteiros; o volume esperado de uma administradora
  cabe, e o teto ficou só nos recebimentos.
- **Frequência.** Continua sem limite de requisições na API.

Teste com dois condomínios: `CollectionReportsScopeIntegrationTest`.

## Sétima rodada — Relatórios gerenciais (ADR 0082)

Data: 2026-11-09 · Código revisado: `claude-dev` em 19bd187 · Correções: ADR 0068

Escopo: `source/Services/Erp/ManagementReports.php` e `source/Controllers/Api/V1/ManagementReports.php`.

| Ponto | Situação |
|---|---|
| Permissões: cada relatório pede a leitura dos dados que mostra (`erp.entries.view` mais `erp.cost_centers.view`, `erp.chart.view`, `erp.bank_accounts.view` ou `erp.units.view`; `erp.meter_readings.view` no consumo e `erp.bank_charges.view` nos boletos) | CONFIRMADO |
| Escopo: condomínio informado por `assertAccess`; sem condomínio, só os do escopo. O rateio exige condomínio, e o extrato confere o condomínio da conta | CONFIRMADO, com o achado 1; o teste com dois condomínios cobre todos os relatórios |
| CSV: `CollectionReports::csv` e `csvCell`, então a correção do ADR 0067 (espaço, controle, LF, largura total) vale aqui; auditoria da exportação | CONFIRMADO |
| Tetos: 24 competências ou 366 dias; 5.000 linhas nos relatórios em linhas (demonstrativo, extrato, lançamentos, boletos), com aviso em vez de corte silencioso. Os agregados (consumo, centros de custo, comparativo) ficam limitados pelo número de grupos | CONFIRMADO, com o achado 2 no rateio |
| SQL: período e filtros por parâmetro; listas de ids são inteiros já convertidos | CONFIRMADO, com o achado 3 |

### Achados corrigidos (ADR 0068)

1. **Oráculo de id no extrato.** Conta inexistente dava 422 e conta de outro condomínio dava 404.
   Agora as duas dão 404.
2. **Rateio sem teto na matriz.** A visão unidade × conta não tinha limite de linhas, e a matriz
   despesas × unidades era montada inteira. Agora são até 5.000 despesas e 500.000 células, e a visão
   para em 5.000 linhas.
3. **Data concatenada no SQL do extrato.** A data de abertura da conta agora vai por parâmetro.

### Lacunas

- **Comparativo entre condomínios.** Sem condomínio, o comparativo soma por conta do plano todos os
  condomínios do escopo. É o esperado para a administradora, mas a linha não diz de qual condomínio
  veio cada valor.
- **Frequência.** Continua sem limite de requisições na API.

Teste com dois condomínios: `ManagementReportsScopeIntegrationTest`.

## Oitava rodada — Dados dos gráficos (ADR 0083)

Data: 2026-11-09 · Código revisado: `claude-dev` em 2610241 · Correções: ADR 0069

Escopo: `source/Services/Erp/ChartReports.php` e `source/Controllers/Api/V1/ChartReports.php`.

| Ponto | Situação |
|---|---|
| Permissões: `erp.access` e a leitura dos dados de cada gráfico (`erp.entries.view`, mais `erp.chart.view` ou `erp.bank_accounts.view`; `erp.meter_readings.view`, `erp.agreements.view` e `erp.reservations.view`) | CONFIRMADO |
| Escopo: condomínio informado por `assertAccess`; sem condomínio, só os do escopo. Unidade de fora no consumo responde igual à inexistente | CONFIRMADO, com o achado 1 |
| CSV: os gráficos não exportam; só JSON | NÃO SE APLICA |
| Custo: até 24 meses, agregado com `GROUP BY`; contas do plano e áreas comuns com 8 séries e "Outras" | CONFIRMADO, com os achados 1 e 2 nas contas bancárias |
| SQL: período e natureza por parâmetro; listas de ids são inteiros já convertidos | CONFIRMADO |

### Achados corrigidos (ADR 0069)

1. **Subconsulta sem filtro de condomínio.** O saldo por conta bancária varria os lançamentos e as
   transferências de todos os condomínios no período antes de filtrar pela conta. Agora cada parte
   filtra o condomínio, e um lançamento de outro condomínio que aponte para a conta não entra no saldo.
2. **Séries sem teto.** Uma série por conta bancária, sem limite. Agora são as 8 de maior movimento
   e o resto em "Outras".

### Lacunas

- **Frequência.** Continua sem limite de requisições na API; cada gráfico faz uma consulta agregada.
- **Inadimplência "hoje".** O vencido em aberto usa `CURDATE()` do banco; o mesmo período consultado
  em dias diferentes dá números diferentes. É o comportamento esperado e está no rótulo da série.

Teste com dois condomínios: `ChartReportsIntegrationTest::testBankAccountsCountOnlyMovementsOfTheSameCondominium`.

## Nona rodada — Fechamento mensal e concorrência (ADR 0031)

Data: 2026-11-09 · Código revisado: `claude-dev` em a3dfc80 · Correções: ADR 0100

Escopo: `source/Services/Erp/MonthClosing.php`, a trava `closing_lock` de
`source/Services/Erp/Registry/RegistryService.php` e os serviços que chamam `isClosed`.

| Ponto | Situação |
|---|---|
| Escopo: fechar, reabrir e consultar exigem `assertAccess`; a visão "Todos" usa só os condomínios do escopo | CONFIRMADO |
| Reabertura com motivo de 5 a 255 caracteres, espaços colapsados; fechar e reabrir auditados | CONFIRMADO |
| Trava da competência em todos os caminhos que gravam lançamento: cadastro, faturas, acordos, atalhos, importações, retorno bancário, lançamentos fixos | CONFIRMADO, com o achado 1 |
| Lançamentos fixos: um por modelo e competência, com `FOR UPDATE` no modelo e índice único | CONFIRMADO |
| SQL: competência validada como AAAA-MM e por parâmetro; listas de ids são inteiros | CONFIRMADO |

### Achados corrigidos (ADR 0100)

1. **Corrida entre fechar e gravar.** A competência era conferida com leitura simples, antes da gravação
   e, no cadastro, fora de transação. Um lançamento gravado no mesmo instante do fechamento ficava numa
   competência fechada, fora da foto. Agora a conferência é travada e fica na transação da gravação, e o
   fechamento grava a situação antes de somar.
2. **Transferência e fatura conferidas fora da transação.** A transferência entre contas gravava sem
   transação, e a preparação da fatura conferia antes de abri-la. As duas agora reconferem dentro da
   transação da gravação.
3. **Reabertura sem trava.** Duas reaberturas ao mesmo tempo gravavam e auditavam duas vezes. Agora a
   reabertura roda em transação, com a linha travada.

### Lacunas

- **Conferências fora de transação.** Algumas telas conferem a competência antes de abrir a transação,
  só para mostrar o erro mais cedo. Isso é aceitável, porque a conferência que vale é a de dentro da
  transação.
- **Espera de trava.** Duas operações na mesma competência esperam uma pela outra. Se a espera passar
  do `innodb_lock_wait_timeout`, a operação falha e precisa ser repetida.

Teste com duas conexões: `MonthClosingIntegrationTest::testCloseWaitsForAWriteThatAlreadyCheckedTheCompetence`.

## Décima rodada — Ocorrências, saldos bancários e candidatos a recibo

Data: 2026-11-09 · Código revisado: `claude-dev` em 204e171 · Correções: ADR 0101

Escopo: `source/Services/Erp/Occurrences.php`, `BankBalances.php` e `ReceiptCandidates.php`.

| Ponto | Situação |
|---|---|
| Escopo: ocorrência, unidade e contas conferidas pelo condomínio; fora do escopo responde 404 (ocorrência e unidade) ou lista vazia (saldos) | CONFIRMADO |
| Chamado da ocorrência: um por ocorrência | CONFIRMADO, com o achado 1 |
| Saldo da conta: abertura, lançamentos pagos e transferências ativas desde a abertura | CONFIRMADO, com o achado 2 |
| Candidatos a recibo: só receitas pagas da unidade sem recibo válido, até 120 | CONFIRMADO |
| SQL: ids por parâmetro ou inteiros já convertidos | CONFIRMADO |

### Achados corrigidos (ADR 0101)

1. **Chamado órfão.** Dois pedidos ao mesmo tempo abriam dois chamados no Help Desk, e um deles ficava
   sem ocorrência. Agora o vínculo é gravado na transação do chamado, com a ocorrência travada, e o
   chamado a mais é desfeito.
2. **Saldo sem filtro de condomínio.** Lançamentos e transferências de outro condomínio que apontassem
   para a conta (dado inconsistente) entravam no saldo. Agora só entram os do condomínio da conta.

### Lacunas

- A corrida do chamado não tem teste automatizado (ver ADR 0101).

## 11ª rodada — Balancete, prestação de contas e encargos

Data: 2026-11-09 · Código revisado: `claude-dev` em 312a5ce · Correções: ADR 0102

Escopo: `source/Services/Erp/FinancialReports.php` e `LateCharges.php`.

| Ponto | Situação |
|---|---|
| Escopo: condomínio por `assertAccess`; "Todos" só com os do escopo; nomes das contas sintéticas só do padrão e dos condomínios do escopo | CONFIRMADO |
| Tetos: 24 competências no balancete e 366 dias na prestação de contas; agregados por conta | CONFIRMADO |
| Conciliação por conta bancária | CONFIRMADO, com o achado 1 |
| Encargos: regra pura, laço de índices limitado a 600 meses, índice ausente informado em `missing_index` | CONFIRMADO |
| SQL: datas e competências por parâmetro; listas de ids são inteiros | CONFIRMADO |

### Achado corrigido (ADR 0102)

1. **Conciliação sem filtro de condomínio e sem fim.** A conta juntava os lançamentos pagos de qualquer
   condomínio e de qualquer data posterior. Agora junta só os do condomínio da conta e só até o fim do período.

## 12ª rodada — Painéis e dashboard operacional

Data: 2026-11-09 · Código revisado: `claude-dev` em 9c62bbe · Correções: ADR 0103

Escopo: `source/Services/Erp/PanelSummaries.php` e `OperationalDashboard.php`.

| Ponto | Situação |
|---|---|
| Permissões: cada painel exige as leituras que mostra (`missing()`); cartões financeiros só com permissão financeira | CONFIRMADO |
| Escopo: condomínio e unidade por `canAccess`, 404 fora do escopo; contagens só dos condomínios do escopo | CONFIRMADO |
| Custo: contagens agregadas; listas do painel da unidade com `LIMIT` | CONFIRMADO, com o achado 1 |
| Lista de espera: uma chamada por vaga | CONFIRMADO, com o achado 2 |
| Próxima manutenção: uma só por manutenção concluída (índice único em `previous_id`) | CONFIRMADO |

### Achados corrigidos (ADR 0103)

1. **Painel da unidade calculava o condomínio inteiro.** Agora lê só as contas da unidade.
2. **Duas chamadas para a mesma vaga.** Agora a reserva fica travada, e só se chama o próximo depois da
   desistência de quem já foi chamado.

### Lacunas

- **Dados pessoais.** O painel da unidade mostra telefone e e-mail dos moradores sem máscara, a quem
  tem `erp.residents.view`. É a mesma lacuna das telas de moradores, registrada nas rodadas anteriores.

## 13ª rodada — Leituras e consumo

Data: 2026-11-09 · Código revisado: `claude-dev` em dfb33cd · Correções: ADR 0104

Escopo: `source/Services/Erp/MeterReadings.php` e a gravação de leituras da importação.

| Ponto | Situação |
|---|---|
| Escopo: relógio por `canAccess` (404 fora); lista só dos condomínios do escopo, com `LIMIT` | CONFIRMADO |
| Concorrência: relógio travado (`FOR UPDATE`) ao gravar; cobrança trava as leituras e `entry_id` único barra duplicar | CONFIRMADO |
| Leitura cobrada não muda; correção só sem leitura posterior | CONFIRMADO |
| Competência fechada recusa a leitura | CONFIRMADO, com o achado 1 |
| Descrição da conta cobrada | CONFIRMADO, com o achado 2 |

### Achados corrigidos (ADR 0104)

1. **Leitura em mês fechado.** O lançamento manual não conferia o fechamento, e a importação só conferia
   na prévia. Agora `record` confere dentro da transação, com a leitura travada.
2. **Energia descrita em m³.** Agora em kWh.

### Lacunas

- O teste de integração novo roda só no Mac; o CI roda apenas a suíte unitária.

## 14ª rodada — Documentos da unidade e Disco Virtual

Data: 2026-11-09 · Código revisado: `claude-dev` em ebcedbc · Correções: ADR 0105

Escopo: `source/Services/Erp/UnitDocuments.php` e `VirtualDisk.php`.

| Ponto | Situação |
|---|---|
| Escopo: unidade, documento e verificação por `canAccess` (404 fora); listas paginadas | CONFIRMADO |
| Recibo: contas travadas (`FOR UPDATE`), só do condomínio da unidade, um recibo válido por conta | CONFIRMADO |
| Código de verificação: 16 caracteres de 31 símbolos, único, com nova tentativa na colisão | CONFIRMADO |
| Situação na data de referência | CONFIRMADO, com o achado 1 |
| Contas só do condomínio da unidade | CONFIRMADO, com o achado 2 |
| Cancelamento em paralelo | CONFIRMADO, com o achado 3 |
| Disco Virtual: arquivo fora da pasta pública, nome aleatório, tipo por `finfo` com extensão batendo, 20 MB, caminho validado no download | CONFIRMADO |

### Achados corrigidos (ADR 0105)

1. **Documento com data passada usava a situação de hoje.** Agora a conta paga depois da data conta como
   em aberto naquela data.
2. **Conta de outro condomínio apontando para a unidade entrava no documento.** Agora fica de fora.
3. **Dois cancelamentos ao mesmo tempo gravavam dois registros de auditoria.** Agora o segundo é recusado.

### Lacunas

- Conta cancelada ou criada depois da data de referência ainda é lida pelo estado atual.
- Disco Virtual: dois envios simultâneos do mesmo arquivo na mesma pasta podem passar pela checagem de
  duplicidade (não há índice único). O risco é baixo: só gera uma cópia a mais.

## 15ª rodada — Lançamentos fixos

Data: 2026-11-09 · Código revisado: `claude-dev` em 0d0d74b · Correções: ADR 0106

Escopo: `source/Services/Erp/RecurringEntries.php` e `POST /api/v1/recurring-entries/generate`.

| Ponto | Situação |
|---|---|
| Permissão: `erp.recurring_entries.manage` e `erp.entries.manage`; escopo pelos condomínios do usuário | CONFIRMADO |
| Sem duplicar: modelo travado (`FOR UPDATE`), vínculo conferido e chave única (modelo, competência) | CONFIRMADO |
| Cada lançamento pelo `RegistryService` (validações, fechamento e auditoria) | CONFIRMADO |
| Erro num modelo não deixa transação aberta | CONFIRMADO, com o achado 1 |

### Achado corrigido (ADR 0106)

1. **Transação aberta.** Um erro fora de validação e de banco (ex.: dia 31 gravado direto no banco)
   subia sem desfazer a transação do modelo. Agora o modelo vai para `failed`, e qualquer outro erro
   desfaz a transação antes de subir.

### Lacunas

- A geração percorre todos os modelos ativos do escopo sem limite. É aceitável para uma administradora,
  mas uma geração de todos os condomínios de uma vez faz uma transação por modelo na mesma requisição.

## 16ª rodada — Fila de avisos

Data: 2026-11-09 · Código revisado: `claude-dev` em a20bdf7 · Correções: ADR 0108

Escopo: `source/Services/Erp/NotificationOutbox.php` e `source/Services/Erp/NotificationDispatcher.php`.

| Ponto | Situação |
|---|---|
| Fora de produção, nenhum e-mail sai para morador (modo log ou caixa de teste) | CONFIRMADO |
| Destinatários só da unidade e do condomínio do evento; cobrança só com opt-in | CONFIRMADO |
| Payload sem chaves sensíveis; e-mail na trilha em hash e mascarado | CONFIRMADO |
| Consulta e reenvio pelo escopo do usuário (fora do escopo: 404) | CONFIRMADO |
| Aviso não fica parado se o processo cair no meio | CONFIRMADO, com o achado 1 |

### Achados corrigidos (ADR 0108)

1. **Aviso preso.** Um processo que caísse durante o envio deixava o aviso em `processing` para sempre,
   sem falha visível e sem reenvio. Agora a posse tem prazo de 15 minutos e o aviso é retomado.
2. **Reenvio sem condição.** `retry` atualizava sem conferir a situação no `UPDATE`. Agora confere.

### Lacunas

- No modo production, `sent_to` guarda o e-mail do morador em claro na trilha. Ele já está no cadastro
  da pessoa, mas a trilha poderia guardar só o id da `mail_queue`. Fica para decisão com a LGPD (#46).
- Um e-mail enfileirado cuja trilha não foi gravada pode sair duas vezes na retomada.

