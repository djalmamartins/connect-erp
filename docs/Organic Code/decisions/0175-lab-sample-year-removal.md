# 0175 — O --remover apaga também o ano contábil de exemplo

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0162 (dados de exemplo e `--remover`), 0173 (ano contábil de exemplo)

## Contexto

Depois do ano de exemplo (ADR 0173), o `--remover` recusava. Os lançamentos patrimoniais, as transferências, o
acordo, a importação e os saldos iniciais seguravam as linhas por chave estrangeira, e a remoção desfazia tudo.

## Decisão

`LabSampleYear::purge()` tira o ano antes, na mesma transação do `LabSampleData::remove()`. O `remove()` passou a
aceitar um passo `$before`, e o comando usa:

```php
$service->remove(fn () => $year->purge())
```

Se qualquer passo falhar ou recusar, nada é apagado.

### Antes de apagar: o que é conferido

No condomínio "EXEMPLO Residencial Aurora", tudo precisa ser de exemplo. O primeiro registro que não for faz
recusar, com o tipo e o id (por exemplo, "lançamento #9001"):

| Registro | Conta como de exemplo quando |
|---|---|
| Lançamento | a descrição tem a marca, ou é parcela de um acordo de exemplo |
| Lançamento patrimonial | a descrição tem a marca, ou é estorno de um lançamento com a marca |
| Transferência | a descrição tem a marca |
| Importação | tem a chave `EXEMPLO-ano-lote-1` |
| Saldo inicial | as observações têm a marca |
| Acordo | as observações são as do acordo de exemplo |
| Recibo e boleto | nenhum sobre os lançamentos do condomínio |

### Ordem de remoção

1. Os avisos do acordo na fila.
2. Os estornos e depois os lançamentos patrimoniais.
3. A importação.
4. O acordo:
   - a conta renegociada volta a ser a conta vencida do ADR 0162;
   - depois saem os originais, as parcelas e o acordo.
5. As transferências.
6. Os saldos iniciais.
7. A ligação da aplicação (`parent_bank_account_id`).
8. Por fim, o `remove()` do ADR 0162 apaga, pela marca, o resto:
   - os lançamentos;
   - os moradores;
   - as unidades;
   - as contas bancárias (inclusive a aplicação);
   - as contas do plano;
   - os condomínios.

### Auditoria

Duas linhas de auditoria, dentro da mesma transação:

- `lab_sample_year`: quantos registros saíram por tipo e os ids dos acordos;
- `lab_sample_data`: quantos registros saíram por tipo e os ids dos condomínios.

### Travas

As de sempre do comando: `ERP_DEMO_DATA` ligado, ambiente lab ou dev, e banco com marca de laboratório ou o nome
exato de `ERP_DEMO_DB`. O plano padrão (contas sem condomínio) nunca é tocado.

## Consequências

- **Teste.** `LabSampleYearRemovalTest` (unit, SQLite, com chaves estrangeiras ligadas para conferir a ordem;
  `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) cobre:
  - gerar, remover e gerar de novo: nada sobra com a marca, o plano padrão fica e a segunda geração cria tudo de
    novo, com as mesmas contagens;
  - a recusa com um lançamento sem a marca, sem apagar nada;
  - remover sem o ano, que continua funcionando.
- **Sem migration, rota ou tela.**
