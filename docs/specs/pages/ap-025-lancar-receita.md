# AP-025 — Lançar Receita

- **Domínio:** financeiro
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/financeiro/lancament`
- **Situação da rota na auditoria:** CONFIRMADA
- **Fonte:** `docs/reference/AUDITORIA_COMPLETA_APCONTROLE.pdf`, página 10
- **Natureza desta ficha:** scaffold gerado automaticamente; detalhes ainda NÃO confirmados

## Objetivo funcional
CONFIRMADO (revisão de 15/11, item 16, modelo 8; ADR 0313): lançar uma receita do condomínio ou cobrada das unidades,
com rateio, valor de cada unidade, fundo de reserva, cobrar de e repetição.

## Campos, controles, filtros, colunas e links
CONFIRMADO (modelo 8): descrição, conta da receita, centro de custo, vencimento, valor, documento, rateio (sem rateio,
todas as unidades menos as isentas, escolhidas), cálculo (fixo, dividido, fração, valor de cada unidade), fundo de
reserva (% e conta), incluir na fatura de (competência), cobrar de (responsável, proprietário, inquilino), repetir
(2 a 24 meses, com "(1/N)" opcional). Grava por `POST /api/v1/revenues` (`RevenueLaunch`).
PENDENTE: "conta relacionada", "tipo da fatura" e anexos no mesmo envio (anexo na ficha de cada lançamento, ADR 0174).

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

## Implementação no Connect ERP (ADR 0057 e 0073)
Regras e API no ADR 0057; tela descrita no ADR 0073 (`docs/Organic Code/decisions/0073-entry-shortcut-screens.md`).
