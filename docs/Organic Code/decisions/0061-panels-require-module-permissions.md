# 0061 — Painéis e dashboard exigem a permissão de cada módulo que mostram

- Status: aceito (09/11/2026)
- Contexto: revisão de segurança dos ciclos 18 a 22 (`docs/security/REVIEW_API_FINANCEIRO.md`), ADR 0053, 0054, 0055 e 0071

## Contexto

Os painéis do condomínio e da unidade (`PanelSummaries`, `GET /api/v1/panels/*` e as telas do
ERP) exigiam só `erp.units.view`, mas mostram dados de vários módulos:
- saldo das contas bancárias, que normalmente exige `erp.bank_accounts.view`;
- inadimplência e acordos (`erp.agreements.view`);
- resultado do fechamento (`erp.closing.view`);
- nome, telefone e e-mail de moradores (`erp.residents.view`);
- contas a vencer (`erp.entries.view`);
- documentos emitidos (`erp.unit_documents.view`).

Assim, um perfil só com "Visualizar unidades" via dados financeiros e pessoais que o próprio
módulo lhe negaria. O dashboard (`GET /api/v1/dashboard`, só `erp.access`) mostrava somas de
contas a receber e a pagar.

Além disso, `POST /api/v1/notifications/dispatch` processa a fila de todos os condomínios e só
pedia `erp.notifications.manage`.

## Decisão

- `PanelSummaries::REQUIRED` lista as permissões de cada painel. Faltando qualquer uma, o
  serviço lança `RegistryForbidden`: a API responde 403 e a tela mostra a mensagem de permissão.
  Painel parcial, com só as seções permitidas, fica para quando a tela souber esconder seção
  (frente de layout).
- `OperationalDashboard::summary` tira os cartões de valores (`FINANCE_CARDS`) de quem não tem
  `erp.entries.view`. As telas já tratam chave ausente como zero.
- Disparar a fila de avisos à mão exige acesso a todos os condomínios (`erp.condominiums.all`).
  Listar, ver e reenviar continuam por escopo.

## Consequências

- Perfis que hoje veem o painel só com `erp.units.view` passam a receber "sem permissão" até
  ganharem as permissões listadas. Os papéis `developer` e `super_admin` já têm todas.
- Testes: `PanelSummariesTest` (regras puras) e `PanelSummariesIntegrationTest` (recusa com
  permissão parcial; precisa rodar no lab).
