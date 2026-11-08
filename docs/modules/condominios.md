# Módulo Condomínios (ERP v1)

Referência funcional: `docs/specs/pages/ap-004-condominios.md`. Lista de campos aprovada pelo
Djalma em 2026-11-08.
Decisões: ADR-002 (administradora única), ADR 0006 (autorização), ADR 0007 (tema e fatia),
ADR 0011 (cadastro).

## Campos (tabela `condominiums`, migration `20261108_0005`)

| Grupo | Campo | Coluna | Regra |
|---|---|---|---|
| Identificação | Nome | `name` | obrigatório, 3 a 160 |
| | Razão social | `legal_name` | obrigatório, 3 a 160 |
| | CNPJ | `document` | obrigatório, dígitos verificadores, único (`uq_condominiums_document`) |
| | Situação | `status` | `active` (padrão) ou `inactive` |
| Endereço | CEP | `zip_code` | obrigatório, 8 dígitos |
| | Logradouro | `street` | obrigatório, até 160 |
| | Número | `number` | obrigatório, até 20 |
| | Complemento | `complement` | opcional, até 80 |
| | Bairro | `district` | obrigatório, até 100 |
| | Cidade | `city` | obrigatório, até 100 |
| | UF | `state` | obrigatório, uma das 27 UFs |
| Contato | Telefone | `phone` | opcional, DDD + 8 ou 9 dígitos |
| | E-mail | `email` | opcional, e-mail válido |
| Estrutura | Blocos | `blocks_count` | opcional, 0 a 999 |
| | Unidades | `units_count` | opcional, 0 a 100000 |
| Contrato | Síndico | — | não fica em `condominiums`: papel `sindico` em `erp_board_members` (migration 0201 da main) |
| | Início do contrato | `contract_start_date` | opcional, data válida |
| | Dia de vencimento da taxa | `fee_due_day` | opcional, 1 a 28 (também `CHECK` no banco) |
| Banco (provisório) | Banco, agência, conta | `bank_code`, `bank_branch`, `bank_account` | opcionais, informados juntos. Migram para o núcleo financeiro |

As colunas novas são `NULL` no banco para preservar registros antigos. A obrigatoriedade é do
serviço.

## Fluxo e segurança

Rota → `Erp\Connect\Condominiums` → `CondominiumRegistry` (com `CondominiumValidator`) → MariaDB.

| Rota | Ação | Permissão |
|---|---|---|
| `GET /erp/condominios` | lista (escopo do usuário) | `erp.access` + `erp.condominiums.view` |
| `GET /erp/condominios/{id}` | detalhe | idem; fora do escopo responde 404 |
| `GET/POST /erp/condominios/novo` | cadastro | `erp.access` + `erp.condominiums.manage` |
| `GET/POST /erp/condominios/{id}/editar` | edição | idem + condomínio no escopo (ativo ou inativo) |

- CSRF (`csrf_input`/`csrf_verify`) em todo POST. Limite de 30 gravações a cada 5 minutos por sessão.
- Após o POST, redireciona para o detalhe. Erro de validação devolve 422 com o formulário preenchido.
- O serviço também verifica a permissão (não depende só do controller).
- Quem cadastra sem `erp.condominiums.all` recebe o vínculo em `user_condominium_access`.
- Auditoria: `Support/Audit::record('create'|'update', 'condominiums', ...)` com antes/depois.
- `created_by`/`updated_by` gravam o usuário.

## Testes

- `tests/legacy/Unit/CondominiumValidatorTest.php`: normalização, obrigatórios, CNPJ,
  limites, rotas antes de `/{id}`, formulário com CSRF/escape/erros.
- `tests/legacy/Integration/CondominiumRegistryTest.php`: cadastro com vínculo, CNPJ
  duplicado, edição auditada, edição fora do escopo, usuário só leitura.

## Pendente

- Botões "Novo condomínio" e "Editar" na lista e no detalhe, e visual do formulário
  (`claude-layout`, que é dono de `pages/`).
- Detalhe exibindo os campos novos (hoje `CondominiumScope::allowed` devolve só id, nome, CNPJ, situação).
- Busca de endereço por CEP, máscara de campos.
- Teste HTTP das rotas e smoke no lab.
