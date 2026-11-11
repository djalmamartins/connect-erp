# AP-037 — Remessas

- **Domínio:** financeiro
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/financeiro/remessa/l`
- **Situação da rota na auditoria:** CONFIRMADA
- **Fonte:** `docs/reference/AUDITORIA_COMPLETA_APCONTROLE.pdf`, página 10
- **Natureza desta ficha:** scaffold gerado automaticamente; detalhes ainda NÃO confirmados

## Objetivo funcional
DECIDIDO (ADR 0156): mostrar os boletos enviados ao banco agrupados por conta bancária e dia, com a confirmação do
registro. Com o Banco Inter o registro é online pela API e não há arquivo de remessa; arquivo CNAB entra quando
houver adaptador CNAB. Só leitura. API: `GET /api/v1/bank-remittances` (erp.bank_charges.view).
Situação do lote: Confirmada (nada aguardando), Enviada (algum aguardando banco), Com recusa (algum recusado).

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
