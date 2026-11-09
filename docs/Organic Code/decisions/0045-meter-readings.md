# 0045 — Leituras e consumo: relógios, tarifas por faixa e cobrança individual

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

No passo 7, a seção "Leituras e consumo" do catálogo tem Relógios (AP-041), Leituras (AP-042) e
Tarifas (AP-043). As fichas ainda são scaffold; as regras abaixo seguem `page-specs.php` e são
PROPOSTAS. A cobrança usa as contas a receber por unidade (ADR 0033 e 0039).

## Decisão

- **Relógios (AP-041, `/meters`)**, no motor de cadastros:
  - unidade, tipo (água, gás ou energia), número único por tipo no condomínio, instalação e leitura
    inicial;
  - uma unidade com relógio não pode ser excluída.
- **Tarifas (AP-043, `/tariffs`)**, no motor de cadastros, escopo `optional`:
  - faixas progressivas por tipo: "de", "até" (vazio = sem limite), preço por m³ ou kWh em centavos
    e vigência;
  - sem condomínio é o padrão da Connect; o condomínio com faixas próprias vigentes usa só as dele.
- **Leituras (AP-042, serviço `MeterReadings`)**, uma por relógio e competência:
  - consumo = leitura − anterior. A anterior é a da competência anterior mais recente ou, na primeira,
    a leitura inicial do relógio;
  - **leitura menor que a anterior é recusada** (troca de relógio = relógio novo);
  - valor = faixas progressivas vigentes na data da leitura (`charge`, regra pura). Grava a vigência
    usada. Sem tarifa, o valor fica zero;
  - corrigir a mesma competência só vale sem cobrança e sem leitura posterior.
- **Cobrança** (`POST /api/v1/meter-readings/bill`):
  - uma conta a receber por leitura com valor e ainda não cobrada, na conta de receita e no
    vencimento informados, pelo `RegistryService` (mesmas regras dos lançamentos, inclusive a trava
    do fechamento);
  - tudo ou nada; `entry_id` único barra cobrar duas vezes;
  - a leitura cobrada não muda.
- **Permissões** `erp.meters`, `erp.tariffs` e `erp.meter_readings` (`view` e `manage`), migration
  0527. Escopo por condomínio (404 fora dele). Tudo auditado.

## Consequências

- A frente de layout monta a tela AP-042 (lançar leituras da competência em lote, ver consumo e valor,
  cobrar) sobre a API. Relógios e tarifas já têm tela pelo motor de cadastros.
- Ficam para depois:
  - importação de leituras por arquivo ou app do leiturista;
  - rateio da conta da concessionária (consumo comum);
  - tarifa mínima e alerta de consumo fora do padrão.
- Próximos do passo 7: o resto de Documentos (Disco Virtual com upload fora da pasta pública, Avisos
  de Recebimento, Cópias de Cheques, Empenhos e Malotes).
