# CN-012 — Unidades da imobiliária

- Rota: `/erp/condominios/imobiliaria` (controller `PartnerUnitScreens`, template `pages/condominiums/partner-units`)
- Situação: CONFIRMADA (pedido do proprietário, 27/11/2026; ADR 0684)
- Permissões: `erp.access` + `erp.partner.view` (papel Imobiliária). Só leitura.

## Conteúdo

- Indicadores: unidades administradas, unidades em atraso, valor em atraso (posição do dia).
- Um quadro por condomínio ligado à conta (escopo `user_condominium_access`; a barra superior filtra um).
- Linhas: só as unidades com `erp_units.partner_agency_id` da imobiliária da conta. Colunas: Unidade, Situação
  (Em dia / Em atraso), Contas vencidas, Valor vencido, Vencida desde, Último pagamento, Moradores (nome, vínculo,
  telefone e e-mail mascarados).
- Fonte: `PartnerAccess::view()`, a mesma de `GET /api/v1/partner/view`; contas a receber ativas dos últimos 24 meses.

## Regras

- Em atraso = conta a receber ativa vencida antes de hoje e sem pagamento.
- Conta só de imobiliária: o menu mostra só esta tela e `/dashboard` redireciona para ela.
- Imobiliária desativada ou conta sem vínculo: aviso e nenhuma unidade.
- Sem lançamentos, boletos, pagador nem documentos (LGPD, minimização).

## Testes

- `tests/legacy/Unit/PartnerPaymentSummaryTest.php`, `tests/legacy/Integration/AccessAdminIntegrationTest.php`,
  `tests/erp/render-smoke.php` (CN-012).
