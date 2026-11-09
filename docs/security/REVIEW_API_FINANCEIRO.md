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
