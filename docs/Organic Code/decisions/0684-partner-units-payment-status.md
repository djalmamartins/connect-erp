# 0684 — Imobiliária vê só as unidades dela e se estão em dia

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026 17:09 (Brasília): "a imobiliária ela tem um acesso de poder conferir se está
  sendo pago tudo, talvez faça uma pesquisa na internet e ver como que os outros sistemas funcionam".

## Contexto

O papel Imobiliária (ADR 0090) consultava, pela API `GET /api/v1/partner/view`, todas as unidades e
moradores dos condomínios liberados, sem nada de financeiro, e não tinha tela no ERP: ao entrar, via o
menu da equipe inteiro. A unidade já guarda a imobiliária que a administra (`erp_units.partner_agency_id`,
ADR 0311). No mercado, o serviço da imobiliária é garantir ao proprietário que o condomínio do imóvel
alugado está pago (há imobiliárias que acompanham o pagamento e quitam o boleto se o inquilino atrasa);
para isso basta saber, por unidade, se há conta vencida e desde quando.

## Decisão

- `PartnerAccess::view()` mostra só as unidades com a imobiliária da conta e os moradores delas, mais
  `payment` por unidade (`paymentSummary`, regra pura): em dia / em atraso, contas vencidas, valor
  vencido, vencida desde, valor em aberto e último pagamento (contas a receber ativas dos últimos 24
  meses). Nada de descrição, pagador, boleto ou documento.
- Tela nova CN-012 "Unidades da imobiliária" (`/condominios/imobiliaria`, `PartnerUnitScreens`), só
  leitura, com o mesmo serviço. Permissão `erp.partner.view`.
- Conta só de imobiliária (`erp.partner.view` sem `erp.condominiums.view`): o menu mostra só a CN-012 e
  o `/dashboard` redireciona para ela.

## Consequências

- Condomínio liberado sem unidade marcada com a imobiliária aparece vazio: a equipe marca a imobiliária
  na ficha da unidade ou na edição em massa.
- A API mantém o caminho e ganha `as_of` e `units[].payment` (OpenAPI atualizado).
- Artigo `condominios/unidades-da-imobiliaria` na Central de Ajuda (sem prints ainda; gerar no Mac com
  `bash tools/help/prints.sh --only unidades-da-imobiliaria` depois de declarar os blocos `tela`).
- Testes: `PartnerPaymentSummaryTest`, `AccessAdminIntegrationTest`, smoke CN-012.
