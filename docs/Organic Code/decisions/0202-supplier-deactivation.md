# 0202 — Inativação de fornecedores em lote

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0200 (conferência do cadastro de fornecedores), 0191 a 0197 (operações em lote com prévia)

## Contexto

A conferência do cadastro de fornecedores (ADR 0200) aponta os fornecedores sem uso há 24 meses. Hoje, para limpar o
cadastro, é preciso abrir e inativar um por um no formulário. Com dezenas de prestadores antigos, isso não acontece, e a
lista de escolha dos lançamentos continua cheia de nomes que ninguém usa.

Inativar não apaga nada: o histórico, os lançamentos e a auditoria ficam, e o formulário já recusa fornecedor inativo em
lançamento novo. O risco é inativar quem ainda tem compromisso em andamento.

## Decisão

Rota nova: `POST /api/v1/suppliers/deactivate` com `{supplier_ids[], reason, dry_run?}`. O service é
`SupplierDeactivation`.

### Recusas

A regra pura `problem` recusa o fornecedor:

- não encontrado ou excluído;
- já inativo;
- com conta a pagar em aberto (ativa, não paga) em qualquer condomínio, com a quantidade e o valor;
- com lançamento recorrente ativo, que geraria conta para um inativo;
- com empenho ativo.

Conta cancelada, recorrência inativa ou excluída e empenho liquidado ou cancelado não impedem.

### Gravação

- **Tudo ou nada:** numa transação com os fornecedores travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz
  `_form` (o motivo) e `_supplier` (qual).
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **O que muda:** só `status = 'inactive'` e `updated_by`, pelo `UPDATE` com `status = 'active'` na condição.
- **Por que não regrava pelo formulário:** fornecedor antigo com documento inválido não passaria na validação e nunca
  seria inativado; só a situação muda.
- **Auditoria:** `update` por fornecedor (`status` active → inactive, com o motivo e o tamanho do lote), só quando grava.
- **Motivo:** obrigatório (5 a 255 caracteres).
- **Tamanho:** até 100 fornecedores por vez; ids repetidos contam uma vez.

### Acesso

- O cadastro de fornecedores é comum a todos os condomínios (`scope none`), e a recusa olha as contas de todos. Por isso
  só quem acessa todos os condomínios inativa em lote; os outros recebem 403 (`RegistryForbidden`).
- **Permissão:** exige `erp.access`, `erp.suppliers.view` e `erp.suppliers.manage`; sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.
- **Consultas:** parametrizadas, com marcadores `?`.

### Dados expostos

Saem id, nomes, documento mascarado e a data do último lançamento (`last_used_on`). E-mail, telefone, banco e Pix não
saem.

## Consequências

- **Testes.** `SupplierDeactivationTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2, sem o
  `FOR UPDATE`) confere:
  - prévia sem gravar e a gravação, com `updated_by`;
  - última data de uso sem lançamento excluído;
  - ausência de dados sensíveis;
  - tudo ou nada com conta em aberto de outro condomínio;
  - cada recusa;
  - o que não impede (cancelada, recorrência inativa, empenho liquidado);
  - usuário sem acesso a todos;
  - validação, regra pura, controller, transação, auditoria e rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Reativação:** continua pelo formulário, um por vez.
- **Juntar fornecedores duplicados** (mover lançamentos) continua fora: muda lançamentos e pede outra aprovação.
- **Sem tela nova neste patch.** A tela de fornecedores ou a conferência (ADR 0200) pode ganhar o botão quando a frente
  de layout quiser; quando a tela existir, ela ganha o artigo da Central de Ajuda.
