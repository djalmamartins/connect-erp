# 0058 — Importações: extrato OFX e planilhas, com prévia e idempotência

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

O catálogo tem as importações de extrato OFX (AP-140), planilhas de lançamentos (AP-144),
lançamentos por unidade (AP-145), contas a pagar (AP-146), frações ideais (AP-143) e inadimplentes
(AP-142), e as leituras (AP-044). A coordenação definiu as regras:
- cada importação tem prévia;
- o arquivo inteiro é validado antes de gravar;
- o mesmo arquivo não entra duas vezes (hash);
- os arquivos ficam em armazenamento privado.

Nada pode depender de banco real. O vendor não tem biblioteca de planilha, mas tem as extensões zip
e SimpleXML.

## Decisão

- **Duas etapas** (`Source\Services\Erp\Imports\Imports`):
  - `POST /api/v1/imports/preview` (multipart) lê o arquivo inteiro, valida todas as linhas e devolve
    o erro de cada uma. Não lança nada;
  - `POST /api/v1/imports/{id}/apply` relê o arquivo guardado, confere o sha256, valida de novo e,
    sem nenhum erro, grava tudo numa transação. Uma linha com erro, ou uma falha na regra do cadastro
    ao gravar, desfaz tudo.
- **Idempotência:** o par (condomínio, tipo, sha256) é único. Reenviar o mesmo arquivo em prévia
  revalida a mesma importação; arquivo já aplicado é recusado (409).
- **Armazenamento:** `storage/private/imports/{condomínio}/{sha256}.{ext}`, fora da pasta pública,
  com `.htaccess` "Require all denied" na pasta storage, como no Disco Virtual. O caminho guardado é
  validado ao reler.
- **Leitura:**
  - CSV: separador `;` ou `,` detectado pelo cabeçalho, UTF-8 com ou sem BOM, ou ISO-8859-1;
  - XLSX: primeira aba, via ZipArchive e SimpleXML, sem DOCTYPE (XXE), com limite de tamanho
    descompactado;
  - até 2 MB e 5.000 linhas;
  - cabeçalho obrigatório, com nomes normalizados (sem acento, minúsculas). Valores em reais
    (1.234,56), datas DD/MM/AAAA ou AAAA-MM-DD (ou o número de série do Excel), competência MM/AAAA.
- **Planilhas e regras (conservadoras):**
  - **AP-144 Lançamentos:** colunas natureza, descricao, valor, competencia, vencimento e conta (o
    código de uma conta analítica ativa, da mesma natureza). Opcionais: pago_em, conta_bancaria (pelo
    nome; obrigatória se pago), unidade (pelo rótulo), documento e fornecedor (pelo CNPJ/CPF);
  - **AP-145 Por unidade:** receitas com a unidade obrigatória;
  - **AP-146 Contas a pagar:** despesas, com fornecedor opcional;
  - **AP-142 Inadimplentes:** receitas em aberto de unidade, com vencimento no passado (carga do
    histórico de débitos);
  - **AP-143 Frações ideais:** unidade e fracao (0,0125 ou 1,25%). A planilha precisa trazer todas as
    unidades ativas, sem repetir, com soma 1 (tolerância de 0,0001);
  - toda linha passa também pelo `RegistryValidator` do cadastro de lançamentos. A competência
    fechada (ADR 0031) recusa a linha. Os lançamentos são gravados pelo `RegistryService` e levam
    `import_id` para rastreio, sem trava.
- **AP-140 Extrato OFX** (versão 1 SGML ou versão 2 XML, lido por expressão, sem parser de entidades):
  - a conta do OFX (ACCTID) precisa ser a conta bancária escolhida, comparando só os dígitos;
  - FITID repetido no arquivo recusa o arquivo;
  - linha já importada por outro extrato da mesma conta é marcada como "já importada" e não se repete;
  - **conciliação** (`ImportRules::match`): cada linha procura um lançamento pago ou uma
    transferência (ADR 0057) da mesma conta, no mesmo sentido, com o mesmo valor e até 3 dias de
    diferença. Com exatamente um candidato, fica conferida; com nenhum ou mais de um, fica pendente.
    Nunca escolhe sozinha;
  - **o extrato não cria nem baixa lançamento.** As pendentes vão para `GET /bank-statement-lines` e
    se resolvem à mão (`POST /bank-statement-lines/{id}/resolve`): ligando a um lançamento pago da
    mesma conta, valor e sentido, ainda não conciliado, ou ignorando com nota.
- **Permissões:** `erp.imports.view` e `erp.imports.manage` (migration 20261108_0535). Gravar exige
  também a permissão do destino: `erp.entries.manage`, ou `erp.units.manage` nas frações. Tudo
  auditado; escopo por condomínio.

## Extensão (2026-11-09): leituras e conferência do saldo

- **AP-044 Importação de leituras** (tipo `readings`, migration 20261108_0536):
  - colunas relogio (número do medidor), tipo (água, gás ou energia), competencia e leitura;
    data_leitura é opcional, e vazia vale hoje;
  - uma linha por relógio ativo do condomínio, com as regras do lançamento manual (ADR 0045):
    leitura não menor que a anterior (ou a inicial), competência não cobrada, sem leitura posterior,
    data até hoje e competência aberta;
  - corrigir a leitura ainda não cobrada da mesma competência é aceito e aparece como "replace" na
    prévia;
  - a gravação é pelo `MeterReadings::record` (faixas e tarifa), dentro da transação da importação:
    tudo ou nada. O `record` passou a usar a transação de quem chama, quando há uma;
  - permissão do destino: `erp.meter_readings.manage`. A cobrança das leituras continua no AP-042.
- **Conferência do saldo do OFX:**
  - `summary.balance_check` mostra o saldo do extrato (LEDGERBAL), o saldo calculado da conta na
    mesma data e a diferença (banco − calculado), na prévia e na aplicação;
  - o saldo calculado segue a regra do `BankBalances`: saldo inicial + receitas pagas − despesas
    pagas + transferências que entram − que saem, a partir da data do saldo inicial;
  - **só informa: nenhum lançamento de ajuste é criado.**

## Fora deste ADR

- **AP-141 NF-e (XML):** fora, por enquanto.

## Consequências

- Telas (layout): envio do arquivo, prévia com o erro por linha, aplicar e a fila de linhas
  pendentes do extrato.
- Importações novas seguem o mesmo caminho: tipo em `Imports::KINDS`, validação por linha e
  aplicação numa transação.
