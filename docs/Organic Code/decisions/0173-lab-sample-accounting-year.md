# 0173 — Ano contábil de exemplo no laboratório

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0162 (dados de exemplo da inadimplência), 0165 a 0172 (relatórios contábeis)

## Contexto

As telas novas (Balanço, DRE, Razão, Razão bancário, Prestação de contas, Balancete anual) aparecem quase vazias
no laboratório. Os dados de exemplo do ADR 0162 só criam cadastros e cinco contas a receber.

## Decisão

Um gerador novo, `Source\Services\Erp\LabSampleYear`, cria no condomínio "EXEMPLO Residencial Aurora" um ano
coerente. Ele roda pelo mesmo comando do ADR 0162, com a mesma trava:

```
ERP_DEMO_DATA=true MOVESOS_ENV=lab php tools/lab-dados-exemplo.php --usuario=ID
```

O `--usuario` é o id de um usuário com acesso a todos os condomínios, porque os services exigem escopo. Sem a
opção, o comando faz só o que fazia antes.

### Escolha do ano

- De agosto em diante, o ano corrente até o mês passado.
- Até julho, o ano anterior inteiro.
- Assim são sempre de 7 a 12 meses completos, todos antes de hoje.

### O que o ano tem

- **Abertura (AP-161):** banco de 50.000,00 = fundo de reserva (8.2) de 10.000,00 + resultados acumulados (9.1)
  de 40.000,00. O balanço abre fechado.
- **Meses:**
  - taxa condominial das 6 unidades;
  - taxa de fundo de reserva, com apropriação ao 8.2 no mesmo dia;
  - energia;
  - limpeza (a do último mês fica a pagar e aparece em 8.1).
- **Uso do fundo:** pintura do hall paga pelo caixa e coberta pelo fundo (8.2 → 9.2).
- **Aplicação (CDB):** ligada à conta movimento, com aplicação, rendimento e IR todo mês e um resgate.
- **Inadimplência:**
  - a unidade 106 deixa de pagar os 3 últimos meses;
  - as contas vencidas do ADR 0162 continuam abertas.
- **Acordo:** um acordo de 3 parcelas para a conta de 120 dias da unidade 103 (a candidata a acordo do ADR 0162).
- **Estorno:** um lançamento patrimonial errado (8.2 → 8.3) e o estorno dele.
- **Importação:** uma importação patrimonial aplicada (8.2 → 8.3, 1.000,00), com chave fixa.

### Por que pelos services

Todo movimento passa pelo service que já existe, para as validações (escopo, mês fechado, natureza da conta,
datas) e a auditoria rodarem:

- `RegistryService` para os lançamentos;
- `ChartOpenings`;
- `PatrimonialEntries` (criar e estornar);
- `Investments` (ligar, aplicar e resgatar);
- `Agreements`;
- `PatrimonialImports`.

Só o cadastro de apoio é gravado direto, como o ADR 0162 já fazia:

- as contas do plano com a marca (1.91, 1.92, 2.91, 2.92 e 2.93);
- a conta de aplicação;
- o saldo de abertura da conta movimento.

### Idempotência

Cada passo procura o seu marcador antes e só cria o que falta:

- descrição com "EXEMPLO";
- a chave `EXEMPLO-ano-lote-1` da importação;
- as observações do acordo.

Rodar de novo não duplica nada.

## Consequências

- **Teste.** `LabSampleYearTest` (unit, SQLite) confere:
  - que gerar duas vezes dá o mesmo estado;
  - que o balanço fecha com diferença zero;
  - que há conta a pagar, saldo aplicado e inadimplência;
  - que o estorno anulou o lançamento errado.

  O PDO do teste traduz `FOR UPDATE`, `INSERT IGNORE` e `<=>`, que são do MySQL; a regra não muda.
- **Remoção.** O `--remover` do ADR 0162 não sabe apagar o ano: os lançamentos patrimoniais, as transferências e
  o acordo seguram as linhas por chave estrangeira, e a remoção desfaz tudo e avisa ("Nada foi removido"). Apagar
  o ano de exemplo fica para depois, se for preciso.
- **Aviso do acordo.** O acordo grava o aviso na fila (ADR 0056), como qualquer acordo. Os moradores de exemplo
  não têm e-mail, então ninguém recebe nada.
- **Sem migration, rota ou tela nova.**
