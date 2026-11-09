# 0096 — Revisão de configurações, relatórios cadastrais, atalhos e acordos

Data: 2026-11-09 · Status: aceito (revisado na 25ª rodada) · Módulos: ErpSettings (ADR 0085), RegistryReports (ADR 0084),
EntryShortcuts e Agreements

## Contexto

Esta revisão continua a do ADR 0094. Para cada módulo, conferi:
- escopo por condomínio;
- permissões por ação;
- teto das listagens;
- exposição de dados pessoais;
- auditoria;
- escritas em lote.

## Achados

Nenhum de gravidade ALTA. O escopo está certo em todas as escritas, leituras e exportações:
- `assertAccess`, ou `canAccess` com 404, nos quatro serviços;
- a configuração global exige acesso a todos os condomínios;
- os relatórios usam `fetch()` com teto e a exceção "reduza o período";
- o lote de frações e a cobrança extra só aceitam unidades ativas do condomínio.

**MÉDIA**
1. **Banners aceitavam HTML e link** (ADR 0085). Os campos título e texto passam a ser `plain`, e o
   novo `Registry\PlainText` recusa na gravação:
   - marcação e entidades HTML;
   - esquemas de link (http, https, javascript, data, mailto e tel);
   - `www.` e domínios soltos.

   Como o banner aparece para todos os moradores, um link de golpe seria o pior caso. A saída continua
   escapada na view.
2. **O lote de frações lia as unidades fora da transação.** Uma unidade criada ou inativada entre a
   conferência da soma e a gravação deixava a soma errada.
   - Agora a leitura acontece dentro da transação, com `FOR UPDATE`;
   - o UPDATE também filtra pelo condomínio.

**BAIXA**
3. **O relatório de logs (AP-097) mostrava o IP completo.** Agora mostra o IPv4 sem o último octeto e
   o IPv6 só com os 3 primeiros grupos (`RegistryReports::maskIp`).
4. **A auditoria das configurações gravava o valor direto.** O valor do tipo e-mail
   (`admin.support_email`) agora passa pelo `AuditMask` (`ErpSettings::auditValue`).
5. **O histórico de ajustes do lançamento** (`EntryShortcuts::adjustments`) não tinha teto. Agora tem
   `LIMIT 500`.
6. **`Agreements::list` devolvia lista vazia** para condomínio fora do escopo. Agora responde 404, como
   os outros módulos. Não vazava dados, mas a resposta era diferente.

## O que não mudou, e por quê

- **A lista de configurações devolve o e-mail de atendimento em claro** para quem tem
  `erp.settings.view`. É a configuração editada por essa mesma pessoa, não dado de morador. Só a
  auditoria mascara.
- **Nenhuma configuração lê variável de ambiente nem credencial:** o catálogo é fechado no código e
  não há `getenv`.
- **A previsão e o saldo inicial** já validam a conta e o centro de custo do condomínio, e a previsão já
  grava em transação.
- **Os relatórios cadastrais** já mascaravam documento, e-mail e telefone, e os logs já passavam o
  contexto pelo `AuditMask::text`.

## Testes

- `NewRoutesPermissionMatrixTest` passa a cobrir ErpSettings, RegistryReports, EntryShortcuts e
  Agreements: autorização em toda ação pública, as permissões de cada controller e as ações sensíveis
  (frações, pessoas, logs e escritas).
- `ReviewedModulesTest` (unit) cobre:
  - o texto simples dos banners, inclusive pela validação do cadastro;
  - o valor mascarado na auditoria;
  - o IP mascarado.
- `ReviewedModulesScopeIntegrationTest` cobre:
  - 11 operações fora do escopo, que respondem 404;
  - os logs sem acesso global, que respondem 403;
  - o lote de frações com unidade de outro condomínio, que é recusado sem mudar nada.

Sem migration.
