# 0681 — Tela do ERP sem permissão diz qual permissão falta

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026, teste 3.6 do manual: conta Operador ligada a 3 condomínios via
  Usuários do Sistema; Painel da unidade, Leituras, Financeiro e Fechamento mensal mostravam
  "Interface proposta com dados de demonstração".

## Contexto

As telas com dados reais (`*Screens::data()`) devolvem nada quando a pessoa não tem a permissão de
consulta do módulo (por exemplo `erp.units.view`, `erp.meter_readings.view`, `erp.closing.view`).
O `Panel::pageView()` então caía na interface proposta, com dados de demonstração fora de produção.
Para quem testa, parecia que a tela não funcionava; não havia nenhuma indicação de que faltava
permissão. O papel Operador só tem `erp.access` no ERP, então quase todas as telas caíam nesse caso.

## Decisão

- `Access::can` passa a anotar, por requisição, as permissões estritas (`erp.*`, `api.*`,
  `security.*`) negadas por decisão do banco (`deniedMark()` / `deniedSince()`). Falha de banco não
  é anotada (continua negando sem cache).
- `Panel::pageView()` marca antes de carregar os dados da tela. Se a tela não abriu a versão real e
  alguma permissão estrita foi negada nesse meio, mostra o estado 403 "Sem permissão para esta tela"
  com o nome das permissões que faltam (de `access_permissions.name`) e onde liberar (Studio ›
  Usuários › Permissões específicas, ou Configurações › Permissões de Usuários para o perfil). A
  resposta HTTP é 403.
- Sem banco (smoke, `MOVESOS_TEST_DB_DISABLED`) nada muda: a demonstração continua como antes.
- Telas só propostas (sem dados reais) não conferem permissão e continuam iguais.

## Consequências

- Nenhum dado novo é exposto: só muda a tela de quem já era barrado.
- Honorários já mostrava 403 próprio (`erp.management_fees.view`) e continua igual.
- Testes: `tests/legacy/Unit/ErpForbiddenScreenTest.php`; smoke de renderização sem mudança.
