# AP-019 — Patrimônio

- **Domínio:** cadastros
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/cadastro/patrimonio/`
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

## Implementação Connect ERP (08/11/2026, ADR 0017)

- **Situação:** implementado com MariaDB pelo motor de cadastros (ADR 0014); campos PROPOSTOS, aguardam confirmação.
- **Tela:** `/erp/condominios/patrimonio` (lista, `/novo`, `/{id}`, exclusão lógica). **API:** `/api/v1/assets`.
- **Tabela:** `erp_assets` · **Escopo:** condomínio obrigatório · **Permissões:** `erp.assets.view`, `erp.assets.manage`.

| Campo | Tipo | Obrigatório |
|---|---|---|
| Condomínio (`condominium_id`) | condominium | sim |
| Descrição (`description`) | text | sim |
| Número de patrimônio (`asset_number`) | text | não |
| Categoria (`category`) | select: Mobiliário, Equipamento, Eletrônico, Ferramenta, Segurança, Lazer, Outro | sim |
| Estado (`condition`) | select: Bom, Regular, Em manutenção, Inservível | sim |
| Data de aquisição (`acquired_on`) | date | não |
| Valor de aquisição (`value_cents`) | money | não |
| Localização (`location`) | text | não |
| Fornecedor (`supplier_name`) | text | não |
| Nota fiscal nº (`invoice_number`) | text | não |
| Observações (`notes`) | longtext | não |
