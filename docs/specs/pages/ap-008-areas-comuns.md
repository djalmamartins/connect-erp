# AP-008 — Áreas Comuns

- **Domínio:** cadastros
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/cadastro/area-comum/`
- **Situação da rota na auditoria:** CONFIRMADA
- **Fonte:** `docs/reference/AUDITORIA_COMPLETA_APCONTROLE.pdf`, página 9
- **Natureza desta ficha:** scaffold gerado automaticamente; detalhes ainda NÃO confirmados

## Objetivo funcional
PENDENTE: detalhar com base na auditoria e decisões próprias do Connect ERP.

## Campos, controles, filtros, colunas e links
PENDENTE: levantar evidências específicas; não inferir a partir da quantidade de controles.

## Modelo de dados e dependências
PROPOSTO: mapear entidades canônicas e relacionamentos após inspeção do Organic Code.

## Permissões e segurança
PROPOSTO: autorização server-side por ação e condomínio (administradora única — ADR-002); testes negativos obrigatórios.

## UI e estados
PROPOSTO: aplicar componentes do design system, incluindo vazio, erro, carregamento e acesso negado.

## Critérios de aceite
- [ ] Campos e regras confirmados ou decisões documentadas
- [ ] Fluxo implementado ponta a ponta, com dados sintéticos
- [ ] Isolamento, validações, auditoria e testes revisados
- [ ] Navegação e acessibilidade verificadas

## Pendências / perguntas
- Quais regras não foram exercitadas pela auditoria somente leitura?
- Quais links e estados condicionais exigem especificação adicional?
- Qual dependência deve ser entregue antes desta página?

## Implementação Connect ERP (08/11/2026, ADR 0018)

- **Situação:** implementado com MariaDB pelo motor de cadastros (ADR 0014); campos PROPOSTOS, aguardam confirmação.
- **Tela:** `/erp/condominios/areas-comuns` (lista, `/novo`, `/{id}`, exclusão lógica). **API:** `/api/v1/common-areas`.
- **Tabela:** `erp_common_areas` · **Escopo:** condomínio obrigatório · **Permissões:** `erp.common_areas.view`, `erp.common_areas.manage`.

| Campo | Tipo | Obrigatório |
|---|---|---|
| Condomínio (`condominium_id`) | condominium | sim |
| Nome da área (`name`) | text | sim |
| Reserva (`booking_mode`) | select: Exige aprovação, Livre, Bloqueada, Não reservável | sim |
| Capacidade (pessoas) (`capacity`) | int | não |
| Taxa de reserva (`booking_fee_cents`) | money | não |
| Antecedência mínima (dias) (`min_advance_days`) | int | não |
| Antecedência máxima (dias) (`max_advance_days`) | int | não |
| Abre às (`opening_time`) | time | não |
| Fecha às (`closing_time`) | time | não |
| Regras de uso (`rules`) | longtext | não |
