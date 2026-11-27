# 0683 — App do morador: o proprietário vê tudo da unidade, o inquilino só o dia a dia e o que ele paga

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026 17:09 (Brasília): "o proprietário ele consegue ver tudo da unidade. O
  inquilino ele tem acesso só ao dia a dia e ao boleto que ele tem que pagar, né? E em caso de acordo
  alguma coisa."

## Contexto

O vínculo do morador com a unidade (`erp_residents.relationship`: owner, tenant, resident, financial)
já decidia quem vê boletos (`ResidentLinks::FINANCIAL_RELATIONSHIPS`). O inquilino via todas as contas
da unidade, inclusive as do proprietário (fundo de obras, rateios extras, acordos dele), e também a
prestação de contas e as assembleias.

## Decisão

- `ResidentPortal::payerFilter()` (regra única): para o inquilino, uma conta aparece só quando ele é o
  pagador gravado (`erp_entries.payer_resident_id`) ou, sem pagador gravado, quando o "Responsável pela
  cobrança" da unidade é o inquilino. Vale no cartão do Início, no extrato, na conta aberta, no recibo,
  no PDF do boleto (que agora confere a conta do boleto) e no aviso de conta vencida do sininho.
  Acordo é conta como qualquer outra: o inquilino vê o acordo em que ele é o pagador.
- Proprietário e responsável financeiro continuam vendo todas as contas da unidade.
- `ResidentLinks::OWNER_FEATURES`: prestação de contas só para proprietário e responsável financeiro;
  assembleias só para o proprietário (o voto é do condômino, Código Civil art. 1.335; a procuração ao
  inquilino segue pelo fluxo de assembleia, fora do app).
- O resto do dia a dia (reservas, ocorrências/chamados, comunicados, encomendas, visitantes,
  documentos do condomínio e moradores da unidade, como já era) continua para o inquilino.
- O app (web e celular) já esconde as abas pelas funções do vínculo; o Talk usa as mesmas funções.

## Consequências

- Unidade com responsável "Não definido" e conta sem pagador: o inquilino não vê a conta (o
  proprietário vê). Para o inquilino receber, a equipe marca o inquilino como responsável na unidade
  ou como pagador da conta (como já faz a troca de inquilino, ADR 0311).
- Nenhuma mudança de banco nem de API (os contratos são os mesmos, só filtram mais).
- Testes: `tests/legacy/Integration/ResidentTenantViewIntegrationTest.php`; `TalkResidentsTest`
  ajustado (inquilino sem assembleia).
