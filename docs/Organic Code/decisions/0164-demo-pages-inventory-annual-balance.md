# 0164 — Inventário das páginas em demonstração e API do Balancete Anual

Data: 2026-11-10 · Status: proposto para revisão · Plano normal, depois dos testes do Djalma

## Contexto

Parte das páginas do catálogo do ERP ainda cai nos modelos de `pages/modules/*`, com os dados fixos de
`DemoData`. Faltava um quadro dessas páginas, para separar duas situações: a página que já tem backend e
só precisa ligar a tela, e a página que ainda precisa de backend.

## Decisão

- **Inventário:** `docs/process/PAGINAS-EXEMPLO.md` lista as páginas em demonstração (só leitura).
  - Entram as páginas do catálogo que não são cadastro do motor (`Definitions`) e não têm tela real no
    `Panel` nem nos controllers do ERP.
  - O quadro tem quatro colunas: página, o que mostra, backend que já existe e o que falta.
  - Resultado: 88 páginas. 75 já têm service ou API e só precisam ligar a tela; 13 ainda precisam de backend.
- **Balancete Anual (AP-080):** era a primeira página da lista sem backend, e ela só depende de lançamentos e
  plano de contas. Ganhou o `Services/Erp/AnnualBalance` e o `GET /api/v1/reports/annual-balance`.
  - **Parâmetros:** `condominium_id` (vazio = todos do escopo; fora do escopo = 404), `year` (AAAA, de 2000
    a 2100) e `basis`. Com `competence`, o padrão, soma pela competência; com `cash`, pelo mês do pagamento.
  - **Contas:** os 12 meses e o total por conta. As analíticas sobem para as sintéticas pelo código, como
    no AP-079. Cancelados e excluídos não entram.
  - **Totais:** receitas, despesas e resultado, por mês e no ano.
  - **Limite:** a consulta agrupada tem `LIMIT` de 6000 grupos e devolve `truncated: true` quando passa
    dele.
  - **Permissão:** `erp.entries.view` e `erp.chart.view`, as mesmas dos relatórios financeiros (ADR 0037).
  - **CSV:** `format=csv` exporta com as células saneadas, conta no limite de 30 exportações por hora e é
    auditado como `export` em `erp_reports`.
- **Fora deste ciclo:** o HTML e o JS da página. Ficam com a frente de layout, que liga a tela pelo contrato
  do guia. Nenhuma página nova no catálogo.

## Consequências

- `AnnualBalanceTest` cobre:
  - a regra pura: meses, sintéticas, ordem "2.9" antes de "2.10" e totais;
  - as bases competência e caixa;
  - o escopo: todos do escopo e 404 fora dele;
  - a validação de ano e base;
  - o CSV com fórmula neutralizada;
  - a permissão e o limite no controller.
- Sem migration.
