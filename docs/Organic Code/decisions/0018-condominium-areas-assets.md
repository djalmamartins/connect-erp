# 0018 — Áreas comuns, áreas vinculáveis e patrimônio pelo motor de cadastros

Data: 2026-11-08 · Status: aceito (campos PROPOSTOS, aguardam revisão do Djalma)

## Contexto

Com os cadastros prontos (ADR 0014), o Djalma pediu o módulo Condomínios. O trabalho foi
dividido entre duas frentes: uma faz Unidades (AP-006, migrations `20261108_04xx`); esta faz
Áreas comuns (AP-008), Áreas vinculáveis (AP-007) e Patrimônio (AP-019), que estavam no
catálogo com dados de demonstração. As fichas do APControle não confirmam campos.

## Decisão

- As três páginas entram como definições em `Definitions.php` (`common_areas`,
  `linkable_areas`, `assets`) e reaproveitam validador, serviço, controllers, templates,
  API, escopo por condomínio, auditoria e exclusão lógica do ADR 0014, com
  `'group' => 'condominios'` (chave criada no ADR 0017).
- Tabelas `erp_common_areas`, `erp_linkable_areas`, `erp_assets` (migration
  `20261108_0301`) e permissões `erp.common_areas.*`, `erp.linkable_areas.*`,
  `erp.assets.*` no grupo "ERP Condomínios" (`20261108_0302`), concedidas a developer e
  super_admin.
- O motor ganhou os tipos `int` (com `min`/`max_value`) e `time` (HH:MM) e a regra
  `not_below` (um campo numérico não pode ser menor que outro).
- Área vinculável aponta a unidade cadastrada (`unit_id`, ADR 0017) pela migration
  `20261108_0404`, numerada depois da 0401 que cria `erp_units`. Vaga livre fica sem unidade;
  `unit_label` continua gravado durante a transição, e a unidade com vaga vinculada não pode
  ser excluída.
- Patrimônio usa a situação "Em uso / Baixado": bem baixado continua no histórico.

## INFERIDO / PENDENTE

- Campos, modos de reserva (aprovação, livre, bloqueada, não reservável), antecedências,
  tipos de área vinculável e categorias de patrimônio são propostas.
- Reservas de áreas comuns (calendário, aprovação, cobrança da taxa) ficam para uma fatia
  própria; aqui só o cadastro da área e suas regras.
