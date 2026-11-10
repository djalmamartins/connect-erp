# ADR 0144 — Telas de configurações ligadas à API (parâmetros, saldo inicial e previsão)

- Situação: aceita (10/11/2026)
- Contexto: inventário do ADR 0164; serviço e API do ADR 0085

## Contexto

O ADR 0085 criou `ErpSettings` e a API `/api/v1/settings`, `/cost-center-openings` e `/budgets`, mas as páginas
AP-147, AP-151, AP-154, AP-155, AP-157, AP-158, AP-159, AP-166, AP-162, AP-163 e o relatório AP-084 seguiam
com o formulário de demonstração.

## Decisão

- `SettingsScreens` lê pelo mesmo serviço da API e escolhe um de três templates:
  - `pages/settings/parameters`: as chaves de `ErpSettings::CATALOG` da página, cada uma num formulário com o
    valor efetivo, a origem (padrão do sistema, padrão da Connect ou próprio do condomínio), {{Salvar}} e
    {{Voltar ao padrão}}. Sem condomínio na barra superior, a tela edita o padrão da Connect, que só muda com
    acesso a todos os condomínios (sem ele, fica só leitura). Chave de escopo global não é editável com um
    condomínio escolhido. Uma página nova de parâmetros é só uma chave nova no catálogo.
  - `pages/settings/openings` (AP-162) e `pages/settings/budget` (AP-163): pedem um condomínio; a previsão
    edita os 12 meses de uma conta analítica e reaproveita "Editar" na linha.
- As gravações são feitas pelo erp.js (`data-erp-settings`) na própria API, com o CSRF no cabeçalho; a API
  confere de novo permissão, escopo, competência fechada e auditoria.
- AP-084 (relatório de previsão) entra na tela única de relatórios (ADR 0143), sem CSV, porque a API de
  previsão não exporta.
- Permissões do menu: `erp.settings.view` (ver) e `erp.settings.manage` (gravar), como na API.

## Consequências

- Os artigos da Central de Ajuda das 11 páginas passam a `situacao: funciona`; os prints ainda são da
  demonstração até rodar `bash tools/help/prints.sh` no Mac.
- Testes: `ErpSettingsScreensTest` (cada página tem chaves no catálogo e vice-versa; o campo devolve o mesmo
  valor pela normalização da API), `SettingsScreensIntegrationTest` (origem do valor, previsão, saldo inicial,
  AP-084 e sem permissão) e o smoke de renderização.
