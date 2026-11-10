# ADR 0143 — Tela única dos relatórios que já tinham serviço e API

- Situação: aceita (10/11/2026)
- Contexto: inventário do ADR 0164 (`docs/process/PAGINAS-EXEMPLO.md`)

## Contexto

O inventário do ciclo 53 listou 88 páginas do catálogo ainda em demonstração. Conferido na main em
10/11/2026, 24 delas já tinham tela real (cobrança, gráficos, relatórios de cobrança, fechamento, NF-e,
fração ideal, integrações). Dos relatórios restantes, 32 já tinham serviço e API REST (ADR 0082, 0084 e
0089), e o Balancete Anual (AP-080) ganhou API no ADR 0164. Faltava só a tela.

## Decisão

- Uma classe, `Source\Controllers\Erp\Connect\ReportScreens`, e um template, `pages/reports/report.php`,
  atendem as 33 páginas: AP-045, 046, 049, 050, 052 a 054, 056, 062 a 070, 074 a 076, 080, 085 a 087,
  089, 090, 093 a 099.
- Cada página é uma linha de `ReportScreens::PAGES`: a rota da API, os filtros, as permissões (as mesmas da
  API) e as colunas tipadas. A tela chama no servidor o mesmo método de serviço que a API usa, no condomínio
  da barra superior. Não há consulta nova nem regra de negócio nova.
- "Exportar CSV" aponta para a própria API (`format=csv`), que já sanitiza as células (ADR 0081), audita a
  exportação e aplica o limite de 30 por hora (ADR 0087).
- Rateio (AP-062 a AP-067) e memória de cálculo (AP-075) exigem um condomínio: com "Todos", a tela pede a
  escolha em vez de chamar o serviço. Rateio bloqueado por unidade sem fração mostra as unidades.
- Sem banco ou sem permissão, `reportReady` é falso e a página continua na demonstração, como nas outras
  telas (ADR 0037).
- O menu usa as mesmas permissões (`Panel::pagePermissions()` inclui `ReportScreens::pagePermissions()`).
- Os artigos da Central de Ajuda dessas páginas passam a `situacao: funciona` (ADR 0141).

## Consequências

- Uma página nova de relatório com serviço pronto vira uma linha em `PAGES`, sem template novo.
- Os prints da Central de Ajuda dessas páginas ainda mostram a demonstração até rodar
  `bash tools/help/prints.sh` no Mac com o banco `*_test`.
- Testes: `tests/legacy/Unit/ErpReportScreensTest.php` (regras puras, colunas contidas no CSV de cada
  serviço) e o smoke de renderização com uma linha de exemplo por página. Os testes de integração dos
  serviços já existiam; nesta máquina não havia MariaDB para rodá-los de novo.
