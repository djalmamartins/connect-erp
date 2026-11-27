# AP-014 — Conselho

- **Domínio:** cadastros
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/cadastro/conselho/li`
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
- **Tela:** `/erp/cadastros/sindico-e-conselho` (lista, `/novo`, `/{id}`, exclusão lógica). **API:** `/api/v1/board-members`.
- **Tabela:** `erp_board_members` · **Escopo:** condomínio obrigatório · **Permissões:** `erp.board.view`, `erp.board.manage`.

| Campo | Tipo | Obrigatório |
|---|---|---|
| Condomínio (`condominium_id`) | condominium | sim |
| Cargo (`role`) | select: Síndico, Subsíndico, Conselheiro fiscal, Conselheiro consultivo, Suplente | sim |
| Nome ou razão social (`name`) | text | sim |
| CPF/CNPJ (`document`) | document | não |
| Unidade (`unit_label`) | text | não |
| Início do mandato (`term_starts_on`) | date | sim |
| Fim do mandato (`term_ends_on`) | date | sim |
| E-mail (`email`) | email | não |
| Telefone (`phone`) | phone | não |
| Observações (`notes`) | longtext | não |
| Situação (`status`) | Ativo, Inativo | sim |

## Escolha entre moradores e síndicos profissionais e mandato de 1 ou 2 anos (27/11/2026, ADR 0683)

Pedido do proprietário durante os testes: ao digitar o nome, sugerir as pessoas do prédio (síndico morador); poder
cadastrar síndicos profissionais e escolhê-los; no conselho, mostrar a lista de moradores para escolher; e um campo de
mandato de 1 ou 2 anos (máximo 2) que calcula o fim pelo início.

| Regra | Situação |
|---|---|
| Sugestões ao digitar o nome (2 letras ou mais): moradores ativos do condomínio e, para síndico e subsíndico, síndicos profissionais | CONFIRMADO |
| Quadro "Escolher a pessoa" ao lado do formulário, acima do histórico, com abas Moradores do prédio e Síndicos profissionais, busca e "Cadastrar síndico profissional" | CONFIRMADO |
| Escolher preenche nome, CPF/CNPJ, e-mail, telefone e unidade e grava o vínculo `resident_id` ou `professional_manager_id` | CONFIRMADO |
| Conselho fiscal, consultivo e suplente: só moradores (a aba de síndicos profissionais some; o servidor recusa `professional_manager_id`) | INFERIDO (condôminos compõem o conselho; o proprietário pediu a lista de moradores) |
| Morador e síndico profissional juntos no mesmo membro são recusados | CONFIRMADO |
| Morador vinculado precisa estar ativo e ser do mesmo condomínio; síndico profissional precisa estar ativo | CONFIRMADO |
| Duração do mandato (`term_years`): 1 ou 2 anos, obrigatória (padrão 2 na API antiga, sem o campo); fim calculado = início + anos − 1 dia | CONFIRMADO |
| Fim do mandato até início + anos da duração (recusa depois disso) | CONFIRMADO |
| Lista de moradores só com `erp.residents.view` (LGPD: mesma regra da tela de moradores) | CONFIRMADO |

API: `GET /api/v1/board-members/candidates?condominium_id=` (erp.access + erp.board.manage). Síndicos profissionais:
CN-012, `/erp/cadastros/sindicos-profissionais`, `/api/v1/professional-managers`.

Campos novos: Morador do prédio (`resident_id`, ref lazy), Síndico profissional (`professional_manager_id`, ref lazy,
só síndico/subsíndico), Duração do mandato (`term_years`). Migration `20261127_2130_board_member_picker`.
