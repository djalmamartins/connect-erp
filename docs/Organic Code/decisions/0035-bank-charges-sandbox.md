# 0035 — Boletos, retorno e conciliação pelo adaptador bancário (só sandbox)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O passo 5 do `docs/process/IMPLEMENTATION_ORDER.md` pede um adaptador bancário piloto em sandbox,
boleto, eventos, remessa e retorno, e conciliação. `docs/banking/BANKING_INTEGRATIONS.md` proíbe
inventar endpoints ou padrões CNAB, exige credenciais em cofre e pede homologação antes de qualquer
banco real. O banco piloto, a modalidade de cobrança e as credenciais de sandbox ainda não foram
decididos. As contas a receber das faturas existem (ADR 0033).

## Decisão

- **Interface `BankAdapter`**, um subconjunto da lista do documento: `capabilities`,
  `registerCharge`, `getCharge`, `cancelCharge` e `parseReturn`. `BankAdapters::get()` só conhece o
  `sandbox`; um nome desconhecido é recusado, nunca trocado por um adaptador padrão. Remessa
  (CNAB), PDF do boleto, webhook e Pix ficam para o primeiro banco homologado.
- **`SandboxBankAdapter`**:
  - não faz rede, não usa credencial e não imita CNAB;
  - a linha digitável e o código de barras começam com `SANDBOX` e não são pagáveis;
  - o retorno tem formato próprio e documentado (`SANDBOX-RETORNO;v1`, linhas de evento e
    `TOTAL;quantidade;soma`);
  - simula recusa e timeout de forma determinística (`[sandbox:rejeitar]`, `[sandbox:pendente]` na
    descrição).
- **Boleto** (`erp_bank_charges`, migration 0516) de uma conta a receber válida e em aberto:
  - um título ativo por lançamento (coluna gerada `active_key` com chave única), e pedir de novo
    devolve o mesmo;
  - nosso número sequencial por conta bancária, sob lock da conta e com chave única;
  - o título é gravado `pending` **antes** da chamada ao banco. Sem confirmação (erro, timeout),
    continua `pending`, com `last_error`, e é verificável por `refresh`. Só vira `registered` com
    confirmação.
  - Cancelar só vale para `pending` ou `registered` e exige confirmação do adaptador.
- **Retorno** (`erp_bank_return_files` e `erp_bank_events`, migration 0517):
  - o adaptador valida o arquivo inteiro (layout, campos, datas, eventos repetidos, trailer e
    soma) antes de gravar qualquer coisa;
  - o mesmo arquivo (sha256 por conta) não é reprocessado, e o mesmo evento (`event_key` por conta)
    é ignorado;
  - a regra pura `BankReturns::decide()` dá baixa automática só para pagamento de valor igual ao do
    título registrado ou pendente;
  - vão para a **fila de divergências**, nunca para uma baixa silenciosa: pagamento parcial ou a
    maior, pagamento repetido, título cancelado, conta a receber já baixada à mão ou cancelada,
    competência fechada e nosso número desconhecido.
  - A baixa grava `paid_on` e a conta bancária no lançamento. A trava de fatura (ADR 0033) deixa a
    baixa.
  - Resolver uma divergência só registra a revisão, com nota; a correção é manual.
- **Permissões** `erp.bank_charges.view` e `erp.bank_charges.manage` (migration 0518). Escopo por
  condomínio (404 fora dele). Tudo auditado.
- **API**: `/api/v1/bank-charges`, `/bank-returns`, `/bank-events/divergences` e
  `/bank-events/{id}/resolve` (`docs/api/openapi.yaml`).

## Consequências

- Nada aqui envia cobrança real, e os testes usam só o sandbox ou um adaptador falso.
- Um banco real precisa de: decisão do banco piloto e da modalidade, credenciais de sandbox do banco
  no cofre (nunca no `.env` commitado), adaptador próprio com o layout oficial (API ou CNAB 240/400
  homologado), webhook autenticado e aprovação para produção.
- A frente de layout monta as telas AP-035 (boletos), AP-036 e AP-038 (retornos) e a fila de
  divergências sobre a API.
