# AP-005 — Inquilinos

- **Domínio:** cadastros
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/Proprietários/cadastro/morador/lis`
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
- **Tela:** `/erp/cadastros/proprietarios-e-inquilinos` (lista, `/novo`, `/{id}`, exclusão lógica). **API:** `/api/v1/residents`.
- **Tabela:** `erp_residents` · **Escopo:** condomínio obrigatório · **Permissões:** `erp.residents.view`, `erp.residents.manage`.

| Campo | Tipo | Obrigatório |
|---|---|---|
| Condomínio (`condominium_id`) | condominium | sim |
| Unidade (`unit_label`) | text | sim |
| Vínculo (`relationship`) | select: Proprietário, Inquilino, Morador, Responsável financeiro | sim |
| Nome completo ou razão social (`name`) | text | sim |
| CPF/CNPJ (`document`) | document | não |
| E-mail (`email`) | email | não |
| Telefone (`phone`) | phone | não |
| Início do vínculo (`starts_on`) | date | não |
| Fim do vínculo (`ends_on`) | date | não |
| Recebe boleto por e-mail (`billing_by_email`) | bool | não |
| Observações (`notes`) | longtext | não |
| Situação (`status`) | Ativo, Inativo | sim |
