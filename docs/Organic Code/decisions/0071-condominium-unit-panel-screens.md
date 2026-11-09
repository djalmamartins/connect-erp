# 0071 — Telas dos painéis do condomínio (AP-002) e da unidade (AP-003)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ciclo 21 (ADR 0054) criou o `PanelSummaries` e os endpoints `GET /api/v1/panels/condominium/{id}` e
`GET /api/v1/panels/unit/{id}`. As duas páginas do catálogo ainda mostravam demonstração. A frente de
layout ficou com as telas.

## Decisão

- **Os mesmos dados da API, lidos no servidor.** `Panel::condominiumPanelData` e
  `Panel::unitPanelData` chamam o `PanelSummaries`, como as outras telas próprias (ADR 0049 e 0070). A
  permissão também é a mesma da API: `erp.units.view`. Sem banco ou sem essa permissão, a página
  continua com a demonstração.
- **Painel do condomínio (`pages/condominiums/panel.php`):**
  - usa o condomínio da barra superior;
  - em "Todos os condomínios", lista os condomínios do escopo, com links que trocam o contexto;
  - indicadores: unidades com ocupação, saldo em bancos, inadimplência com encargos e resultado da
    competência com a situação do fechamento;
  - alertas da rotina (os do dashboard, só deste condomínio);
  - inadimplência por faixa de atraso, contas bancárias com o saldo e contagens da rotina;
  - cada contagem leva à sua tela.
- **Painel da unidade (`pages/condominiums/unit-panel.php`):**
  - a unidade é escolhida por `?unidade=`, entre as do condomínio da barra superior (ou de todos);
  - o seletor aplica a escolha sozinho (`data-erp-autosubmit`), e o botão continua para quem navega
    sem JavaScript;
  - indicadores: em atraso com encargos, a vencer, pessoas (com veículos e animais) e rotina;
  - listas: débitos em atraso (com o botão "Fazer acordo"), acordos ativos ou quebrados, lançamentos
    a vencer, pessoas com o vínculo e os documentos emitidos;
  - unidade fora do escopo mostra um aviso, como o 404 da API.

## Consequências

- Não foi testado com MariaDB real. Os testes cobrem a renderização com dados de exemplo e as
  capturas de tela em 1366 px e 390 px, sem rolagem horizontal.
- Os gráficos, a tabela de condomínios e as atividades do dashboard (AP-001) continuam de
  demonstração até terem um serviço com dados reais.
