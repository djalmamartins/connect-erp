# 0084 — Relatórios cadastrais e administrativos (AP-085 a AP-099)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

Depois dos relatórios financeiros e dos gráficos (ADR 0081 a 0083), faltavam os relatórios cadastrais
e administrativos do catálogo (AP-084 a AP-101). A coordenação pediu: só consulta, o mesmo escopo, o
mesmo CSV seguro e, quando houver dados pessoais, documento mascarado e `erp.residents.view`.

## Decisão

- **`RegistryReports`**, somente leitura, até 5.000 linhas (acima disso, pede para reduzir o filtro):
  - **AP-085 Reservas** (`/reports/reservations`): por data, área, unidade e situação, com a taxa;
  - **AP-086 Unidades** (`/reports/units`): tipo, andar, fração, área privativa e quantas pessoas,
    relógios e contas vencidas cada unidade tem. Os totais trazem as unidades sem fração e a soma das
    frações;
  - **AP-087 Listagem de pessoas** (`/reports/people`): por unidade e vínculo, com **documento, e-mail
    e telefone mascarados**. Exige `erp.residents.view`;
  - **AP-089 Condomínios** (`/reports/condominiums`): os do escopo, com cidade, unidades, pessoas e
    contas bancárias;
  - **AP-090 Ocorrências** (`/reports/occurrences`): no período, com prioridade, situação e chamado do
    Help Desk;
  - **AP-093 Pagamentos pendentes** (`/reports/pending-payments`): contas a pagar em aberto até a data
    (padrão: hoje + 30 dias), com o fornecedor e se venceram;
  - **AP-094 Listagem de prestações de contas** (`/reports/closings`): os fechamentos mensais na faixa;
  - **AP-095 Mudanças** (`/reports/moves`): as agendadas no período;
  - **AP-096 Endereços** (`/reports/addresses`): o endereço e o contato de cada condomínio (dados da
    administração, não de pessoas);
  - **AP-097 Logs** (`/reports/logs`): a auditoria das entidades `erp_*`, com filtro por entidade e
    ação. O contexto vem cortado em 500 caracteres. Como os logs não têm condomínio, **exigem acesso a
    todos os condomínios**, além da permissão nova `erp.audit.view`;
  - **AP-098 Pessoas com acesso** (`/reports/access-people`): as concessões por condomínio do escopo,
    com o e-mail mascarado, também sob `erp.audit.view`. Quem acessa todos os condomínios não aparece,
    porque não tem concessão por condomínio;
  - **AP-099 Boletos enviados** (`/reports/sent-boletos`): os avisos de boleto da fila (ADR 0055/0056)
    no período, com a situação, o modo de entrega e os destinatários.
- **CSV:** o do ADR 0081, com apóstrofo nas células que começam com `=`, `+`, `-` ou `@`. A exportação
  fica na auditoria.
- **Permissão nova:** `erp.audit.view` (migration 20261108_0539). Os demais relatórios usam a permissão
  de leitura dos dados que mostram.

## Fora deste ADR

- **AP-084 Previsão orçamentária:** depende da configuração AP-163, que ainda não existe.
- **AP-088 Listagem de presenças** e **AP-092 Parecer:** dependem da assembleia e do conselho, que
  ficaram com o Djalma.
- **AP-091 Administração** e **AP-100 Relatórios enviados:** sem definição na ficha. Ficam para quando a
  regra for confirmada na issue #46.
- **AP-101 Prestação de contas:** já existe (ADR 0037, `/reports/statement`).

## Consequências

- Telas (layout): cada relatório usa a sua rota, com "Exportar CSV" na mesma URL com `format=csv`.
