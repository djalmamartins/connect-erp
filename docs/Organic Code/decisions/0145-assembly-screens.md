# ADR 0145 — Telas de assembleia ligadas à API (gestão, votação, presenças e parecer)

- Situação: aceita (10/11/2026)
- Contexto: inventário de páginas de exemplo (docs/process/PAGINAS-EXEMPLO.md); serviço e API do ADR 0088

## Contexto

O ADR 0088 criou o serviço `Assemblies` e a API `/api/v1/assemblies` (criar com a pauta, convocar pelo edital,
abrir, presença, votação por item, encerrar com a ata e cancelar, mais os relatórios de presença e de
resultados com CSV). As páginas AP-018 Assembleia Virtual, AP-017 Votação, AP-088 Listagem de presenças e
AP-092 Parecer seguiam com a lista e o relatório de demonstração.

## Decisão

- `AssemblyScreens` lê pelo mesmo serviço da API e entrega um template único, `pages/assemblies/assembly`, com
  quatro modos:
  - **AP-018 (gestão):** lista das assembleias do escopo, {{Nova assembleia}} com a pauta repetível (até
    `Assemblies::MAX_ITEMS`) e, em `?assembleia=ID`, o detalhe com os botões da situação (convocar, abrir,
    encerrar e cancelar com motivo) e o registro de presença, que só oferece unidades ativas ainda sem presença
    e a procuração escolhida entre os arquivos do Disco Virtual do condomínio.
  - **AP-017 (votação):** a assembleia aberta mais recente (ou a de `?assembleia=ID`), a pauta com abrir e
    apurar a votação, e o voto que só oferece unidades presentes, quites e que ainda não votaram no item em
    votação.
  - **AP-088 e AP-092 (relatórios):** a assembleia escolhida numa lista (em andamento ou encerrada), a
    presença ou o resultado de cada item e a ata com o SHA-256; {{Exportar CSV}} aponta para a API
    (`format=csv`), que mantém o CSV seguro, a auditoria e o limite de exportações (ADR 0081 e 0087).
- As gravações são feitas pelo erp.js (`data-erp-assemblies`) na própria API, com o CSRF no cabeçalho; a API
  confere de novo permissão, escopo, situação e auditoria. A tela só esconde o que a pessoa não pode fazer.
- Permissões: o menu usa `erp.assemblies.view`; os botões aparecem com `erp.assemblies.manage`, e {{Convocar}}
  também exige `erp.announcements.manage`, como na API. O valor vencido na lista de presenças aparece só com
  `erp.agreements.view` (ADR 0094).
- Criar exige um condomínio na barra superior; a lista mostra todo o escopo.

## Consequências

- Os artigos da Central de Ajuda das quatro páginas passam a `situacao: funciona`; os prints ainda são da
  demonstração até rodar `bash tools/help/prints.sh` no Mac.
- Testes: `ErpAssemblyScreensTest` (tela própria, rótulos cobrindo os valores do serviço, escolha da
  assembleia, ações por situação, texto do resultado), `AssemblyScreensIntegrationTest` (o ciclo completo nas
  quatro telas, assembleia fora do escopo e sem permissão) e o smoke de renderização.
- Convocar continua exigindo 8 dias até a primeira convocação; fora de produção o edital só vai para o log ou
  para a caixa de teste.
