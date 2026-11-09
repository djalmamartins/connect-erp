# 0077 — Telas dos relatórios de cobrança: acordos, inadimplentes e recebimentos

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: ADR 0081 e 0067

## Contexto

O ADR 0081 entregou os relatórios de cobrança pela API, e o ADR 0067 fortaleceu o CSV contra
fórmulas. As páginas AP-047, AP-048, AP-057 a AP-061, AP-071 e AP-072 ainda mostravam a demonstração.

## Decisão

- **Uma tela para os nove relatórios** (`pages/reports/collection-report`), com os dados lidos no
  servidor pelo mesmo serviço da API (`CollectionReportScreens`). O controlador entrega as colunas,
  as linhas e a URL do CSV prontas, e a tela só formata. Isso segue a regra que veio da correção do
  smoke: o template não chama cadastro, regra nem banco.
- **Permissões iguais às da API:** acordos e inadimplentes pedem `erp.agreements.view`; a visão por
  morador pede também `erp.residents.view`; recebimentos pedem `erp.entries.view`. Sem permissão ou
  sem banco, a página segue com a demonstração.
- **Filtros (GET):**
  - acordos, taxas e recebimentos: `de` e `ate` (padrão: 1º de janeiro até hoje, até 366 dias,
    validado no serviço);
  - acordos: também `situacao`;
  - inadimplentes: `referencia` (padrão: hoje);
  - recebimentos por conta: `agrupar`, por conta bancária ou conta do plano.
  - Período inválido mostra o erro do serviço, sem a tabela.
- **Tabela:** escapada, com o rodapé somando as colunas de valor. No relatório de acordos, os
  cancelados aparecem esmaecidos e ficam fora da soma, como no serviço.
- **Visão por morador:** aviso de dados pessoais. O documento vem mascarado do serviço, e a
  exportação fica na auditoria.
- **Exportar CSV:** o link aponta para a API com a mesma consulta e `format=csv`. Ele só aparece com
  linhas. As colunas da tela são as mesmas do CSV, ou menos delas (`ErpCollectionReportScreensTest`).
- **Imprimir** pelo navegador, como nos demais relatórios.

## Fora deste ADR

- Os outros relatórios do catálogo e os gráficos (ADR 0083), que vêm depois.

## Consequências

- O smoke cobre os nove relatórios, o erro de período, o vazio e a URL do CSV.
- Nada foi testado contra MariaDB real nesta rodada.
