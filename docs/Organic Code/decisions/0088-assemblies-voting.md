# 0088 — Assembleia e votação: edital, presença, voto por unidade, apuração e ata imutável

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Páginas: AP-017, AP-018, AP-088, AP-092

## Contexto

O condomínio precisa convocar, registrar presença, votar a pauta e guardar a ata no próprio ERP, com a
mesma regra de escopo, auditoria e comunicação pela outbox dos ADRs 0054 a 0059.

## Decisão

- **Tabelas** (migration 20261108_0543):
  - `erp_assemblies`;
  - `erp_assembly_items`, com a base do voto e o quórum de cada item;
  - `erp_assembly_attendance`, uma linha por unidade;
  - `erp_assembly_votes`, um voto por unidade e item.
  - Permissões `erp.assemblies.view` e `erp.assemblies.manage`. Convocar exige também
    `erp.announcements.manage`.
- **Ciclo:** rascunho → convocada → aberta → encerrada. Rascunho e convocada podem ser canceladas. Cada
  passagem é um UPDATE condicionado à situação anterior, para que duas abas não se atropelem.
- **Edital (AP-018):** sai como comunicado (ADR 0059), com pelo menos 8 dias até a primeira convocação
  (`MIN_NOTICE_DAYS`, o prazo usual das convenções). A segunda convocação, quando houver, é pelo menos
  30 minutos depois. Nada é enviado direto: fora de produção, a fila só registra em log ou caixa de teste.
  Se o edital falhar, a assembleia volta a rascunho. Cancelar a assembleia para o que ainda não saiu.
- **Presença (AP-088):**
  - por unidade ativa do condomínio;
  - procuração só com arquivo no Disco Virtual do mesmo condomínio (ADR 0047);
  - a elegibilidade é conferida na entrada. Receita da unidade vencida e não paga (a mesma base do ADR
    0039) deixa a unidade presente, mas sem voto (Código Civil, art. 1.335, III). O valor fica gravado.
- **Voto (AP-017):**
  - um item em votação por vez;
  - um voto por unidade e item (sim, não ou abstenção);
  - o peso é a fração ideal (que precisa estar cadastrada) ou 1 por unidade, conforme o item.
- **Apuração:** `decide()`, regra pura, em milionésimos (sem ponto flutuante):
  - simples: mais sim do que não entre os votos;
  - absoluta: sim acima da metade do total do condomínio;
  - dois terços: sim em pelo menos dois terços do total;
  - unanimidade: sim igual ao total.
  - O total é a soma das frações ou o número de unidades ativas no momento da apuração. O resultado fica
    em `result_json`.
- **Ata (AP-092):**
  - gerada no encerramento, que só acontece com todos os itens apurados;
  - traz presença por unidade, procurações, unidades sem voto e o resultado de cada item;
  - não traz nome nem contato de pessoa: os nomes ficam só no relatório de presença;
  - recebe `minutes_sha256`. Depois de encerrada, nenhum método altera a assembleia.
- **Relatórios:**
  - presença (AP-088) e resultados (AP-092) em JSON, ou em CSV seguro (ADR 0081);
  - o CSV respeita o limite de 30 exportações por hora (ADR 0087) e fica na auditoria.
- **Auditoria:** cada criação, convocação, abertura, presença, voto, apuração, encerramento e
  cancelamento. Na presença, a auditoria guarda a unidade e o direito a voto, sem o nome.

## Consequências

- Rotas, OpenAPI, a linha do README e a limpeza das tabelas no TestCase entram depois do alinhamento do
  claude-units. Até lá, o teste de integração limpa as próprias tabelas.
- Fica para depois:
  - editar a pauta do rascunho;
  - voto remoto feito pelo próprio condômino;
  - limite de procurações por pessoa, que depende da convenção de cada condomínio.
