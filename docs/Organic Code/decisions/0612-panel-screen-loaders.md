# 0612 — Carregadores de tela fora do Panel do ERP

- Status: Aceito
- Data: 20/11/2026
- Relacionados: relatório "Melhorias de arquitetura do Connect ERP" (20/11/2026), padrão das `*Screens` (ADR 0146 e outras)

## Contexto
`source/Controllers/Erp/Connect/Panel.php` tinha 1.270 linhas. Além da moldura do ERP (layout, menu, contexto do
condomínio), ele montava os dados reais de 15 páginas (fechamento, faturas, boletos, acordos, documentos da unidade,
leituras, disco virtual, integrações e outras), e o `pageView()` decidia quem carregar com uma fila de 35 `if`.
As telas mais novas já usavam uma classe própria (`DeliveryScreens`, `ReportScreens`…), com `PAGES` e `data()`.
Em 20/11/2026 o Djalma escolheu, no cartão de decisão, começar a melhoria de arquitetura por aqui.

## Decisão
1. Os carregadores saíram, sem mudar o código de dentro, para três classes no mesmo padrão das `*Screens`:
   `FinanceScreens` (AP-030, 032, 033, 034, 035–038, 051, 079, 165), `UnitScreens` (AP-003, 040, 042, 123, 124, 125,
   130, 167) e `OperationScreens` (AP-114, 122, 171).
2. `Panel::pageView()` soma os dados de todas as telas por uma lista única (`Panel::SCREENS`), na mesma ordem de antes.
   O fechamento mensal passa a ser escolhido pela página (AP-030), não pelo tipo `closing` (só AP-030 tem esse tipo).
3. Os condomínios do escopo seguem vindo do cache do Panel (a mesma lista que o smoke injeta), passados por closure.
4. `Panel::UNIT_DOCUMENT_PAGES`, `RECEIPT_PAGE`, `METER_KINDS`, `REPORT_PAGES`, `contextUnits()`, `documentUnits()`,
   `readingSheet()`, `flattenParcels()` e `fileSize()` continuam como atalhos, para não mexer nas telas e testes que os usam.

## Consequências
- `Panel.php` cai para cerca de 720 linhas. Nenhuma tela, rota, permissão, consulta ou migration mudou.
- Teste novo `ErpPanelScreensTest`; os testes que liam o código do Panel passam a ler a classe nova.
- Página nova com dados reais entra numa `*Screens` e na lista `Panel::SCREENS`, nunca dentro do Panel.

## Parte 2 — SQL fora dos controllers (20/11/2026)
1. Visualizações e votos dos artigos da Central de Ajuda estavam copiados em `Web::supportArticle/supportVote` e
   `Support::article/vote`. Agora os dois usam `Source\Services\Help\ArticleFeedback` (`countView`, `vote`,
   `visitorHash`), com a mesma regra: um voto por visitante, troca corrige os dois contadores, numa transação.
2. As consultas de apoio das telas foram para o serviço do módulo, sem mudar o SQL: unidades ausentes, documentos e
   votantes da assembleia (`Assemblies`), usuários elegíveis a parceiro (`PartnerAccess`), moradores para convite
   (`ResidentInvitations`) e unidades das entregas (`DeliveryLog`).
3. Os condomínios do escopo em Acessos e Administração usam `CondominiumScope::allowed()`, no lugar de duas cópias
   da mesma consulta.
- Teste novo `ArticleFeedbackIntegrationTest`. Integração completa (701) passou num MariaDB local.
- PENDENTE (próximas fatias): consultas de `SettingsScreens`, `PatrimonialEntryScreens` e `EntryShortcutScreens`, a
  busca e os chamados de `Web/Support/Support.php` e as 66 consultas do `Studio.php` (usuários, permissões e logs:
  só mover, sem mudar regra).
