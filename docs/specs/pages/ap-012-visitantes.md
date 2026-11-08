# AP-012 — Visitantes

- **Domínio:** cadastros
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/cadastro/visitante/l`
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

## Implementação Connect ERP (08/11/2026, ADR 0014)

- **Situação:** implementado com MariaDB; campos PROPOSTOS, aguardam confirmação.
- **Tela:** `/erp/cadastros/visitantes` (lista, `/novo`, `/{id}`, exclusão lógica). **API:** `/api/v1/visitors`.
- **Tabela:** `erp_visitors` · **Escopo:** condomínio obrigatório · **Permissões:** `erp.visitors.view`, `erp.visitors.manage`.

| Campo | Tipo | Obrigatório |
|---|---|---|
| Condomínio (`condominium_id`) | condominium | sim |
| Unidade visitada (`unit_label`) | text | sim |
| Nome (`name`) | text | sim |
| Documento (CPF ou RG) (`document`) | id_document | não |
| Telefone (`phone`) | phone | não |
| Acesso permanente (`permanent_access`) | bool | não |
| Autorizado até (`valid_until`) | date | não |
| Observações (`notes`) | longtext | não |
| Situação (`status`) | Liberado, Bloqueado | sim |
