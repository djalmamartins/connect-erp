# AP-010 — Fornecedores

- **Domínio:** cadastros
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/cadastro/fornecedor/`
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
- **Tela:** `/erp/cadastros/fornecedores` (lista, `/novo`, `/{id}`, exclusão lógica). **API:** `/api/v1/suppliers`.
- **Tabela:** `erp_suppliers` · **Escopo:** administradora (todos os condomínios) · **Permissões:** `erp.suppliers.view`, `erp.suppliers.manage`.

| Campo | Tipo | Obrigatório |
|---|---|---|
| Razão social ou nome (`legal_name`) | text | sim |
| CNPJ/CPF (`document`) | document | sim |
| Nome fantasia (`trade_name`) | text | não |
| Categoria (`category`) | select: Manutenção, Limpeza, Segurança, Elevadores, Jardinagem, Portaria, Concessionária (água, luz, gás), Outros | sim |
| E-mail (`email`) | email | não |
| Telefone (`phone`) | phone | não |
| Banco (código) (`bank_code`) | text | não |
| Agência (`bank_agency`) | text | não |
| Conta (`bank_account`) | text | não |
| Chave Pix (`pix_key`) | text | não |
| Observações (`notes`) | longtext | não |
| Situação (`status`) | Ativo, Inativo | sim |
