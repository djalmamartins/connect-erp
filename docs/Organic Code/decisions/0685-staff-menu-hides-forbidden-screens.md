# 0685 — Menu da equipe só com as telas que o perfil abre

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026: "o Operador não deveria ver telas que não acessa" (com permissão total
  para decidir).

## Contexto

O menu lateral e a busca do topo escondem as páginas de `Panel::pagePermissions()` que o perfil não
tem. Dezenove telas pediam a permissão só dentro dos dados (`*Screens::data()`), sem estar nessa
lista: apareciam no menu para todos e, desde a ADR 0681, abriam "Sem permissão para esta tela".
Exemplos: Painel da unidade, Fechamento mensal, Faturas, Acordos, Boletos, Leituras, Honorários.

## Decisão

- As 19 telas entram em `Panel::PAGE_PERMISSIONS` com a mesma permissão que os dados já conferem
  (levantada abrindo cada página do catálogo com uma conta que só tem `erp.access`).
- O teste `ErpMenuPermissionsIntegrationTest` abre todas as páginas do catálogo com essa conta e falha
  se alguma der 403 sem estar na lista com a permissão negada. Tela nova que esquecer a entrada quebra
  o teste.
- Nada muda na autorização: a rota continua conferindo a permissão no servidor; o menu só deixa de
  oferecer o que daria 403.

## Consequências

- Com os papéis da ADR 0682: Operador e Atendente veem 67 telas, Financeiro 114, Gerente 174 (de 176,
  sem contar a tela da imobiliária).
- Uma tela que precise de duas permissões fica no menu pela primeira; se faltar a segunda, ainda abre
  o 403 explicando (ADR 0681).
- Telas propostas sem dados reais continuam visíveis para quem tem `erp.access`.
