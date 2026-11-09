# 0140 — Cadastro rápido de unidades e fração ideal/área em grade

Data: 2026-11-09 · Status: aceito (pedido do Djalma; decisões tomadas sem ele, por delegação) · AP-006 e AP-167

## Contexto

Em Condomínios › Unidades (AP-006) cada unidade era cadastrada uma a uma. Um prédio com 114 unidades exigia
114 formulários. O Djalma pediu um cadastro rápido: informar o início, o fim e quantas unidades há por andar e
gerar a sequência, deixando a fração ideal para ajustar depois, sem passar unidade por unidade. No exemplo dele,
"101 a 1210 com 8 por andar" não fecha (8 por andar vai até 1208), então ele foi lido como ilustração.

A fração ideal já tinha a API `POST /api/v1/unit-fractions` (AP-167, ADR do ErpSettings), que exige todas as
unidades e soma 1, mas a página AP-167 ainda mostrava só a demonstração. A área privativa não tinha edição em lote.

## Decisão

1. **Sequência por parâmetros** (`UnitBatch::plan`, regra pura): blocos opcionais (a sequência se repete em cada
   bloco), andar inicial e final (0 = térreo), unidades por andar, dígitos da posição (1 a 3, padrão 2) e primeira
   posição (1 ou 0). Número = andar + posição (andar 1, 8 por andar: 101…108). No térreo fica só a posição (01…08)
   e o andar gravado é "Térreo". Opcionais: andares a pular (ex.: 13), números a pular (ex.: 1207) e unidades
   avulsas (Cobertura 1, Loja 1), que entram em cada bloco sem andar. Tipo e área privativa iguais para todas.
   Limite de 2.000 unidades por vez.
2. **Pré-visualização antes de criar.** `POST /api/v1/unit-batches/preview` devolve a lista marcando "Já existe"
   (mesmo rótulo, sem diferença de maiúsculas, ativa ou inativa, não excluída). `POST /api/v1/unit-batches` cria só
   as novas pelo motor dos cadastros (`RegistryService::save`): mesmas validações, unicidade e auditoria por
   unidade. É tudo ou nada, em uma transação com o condomínio travado (`FOR UPDATE`), para dois cadastros rápidos
   simultâneos não duplicarem. Rodar de novo não duplica nada.
3. **Grade de fração ideal e área** na página AP-167 (Configurações › Fração Ideal), para o condomínio da barra
   superior, com link a partir de Unidades. Fração em decimal ou em porcentagem (1,25%). "Dividir igualmente" e
   "Dividir pela área" só preenchem a grade (`POST /api/v1/unit-measures/distribute`, regra pura em milionésimos
   com maiores restos, soma exata 1,000000). "Salvar" é `PUT /api/v1/unit-measures`: grava só as unidades que
   mudaram, cada uma auditada (antes/depois de fração e área), em transação com as unidades travadas.
4. **Soma das frações:** pode salvar com unidades ainda sem fração (o preenchimento é gradual), mas a soma nunca
   passa de 100%; quando todas têm fração, precisa dar 100% dentro de `params.fraction_tolerance` (AP-166), a mesma
   tolerância do `POST /api/v1/unit-fractions`. A API antiga continua igual (exige todas de uma vez).
5. **Permissões:** tudo exige `erp.access` + `erp.units.view`; pré-visualizar, criar, dividir e gravar exigem
   `erp.units.manage`. Condomínio fora do escopo = 404. Sem tabela nova, sem migration.

## Consequências e lacunas

- A tela de cadastro rápido não define fração ideal; ela fica para a grade, como o Djalma sugeriu.
- Numeração com letras (101A, 101B) ou com o bloco dentro do número não é gerada; dá para usar unidades avulsas
  ou o cadastro individual.
- Cada unidade criada gera um registro de auditoria e, como em todo o sistema, uma notificação ao usuário master;
  um lote grande gera muitas notificações. Não foi mudado aqui.
- A pré-visualização lista todas as unidades; com centenas, a página fica longa (o botão Criar fica no topo).
