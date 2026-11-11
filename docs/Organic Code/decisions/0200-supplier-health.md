# 0200 — Conferência do cadastro de fornecedores

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0185 (qualidade do cadastro de unidades e moradores), 0190 (gastos por fornecedor), 0188
  (conferência de despesas)

## Contexto

O cadastro de fornecedores é da Connect, comum a todos os condomínios (cadastro `suppliers`, sem condomínio). Com o
tempo, ele junta problemas:

- documento digitado errado ou importado sem conferência;
- o mesmo prestador cadastrado duas vezes ("Limpa Tudo Ltda" e "LIMPA TUDO - ME");
- fornecedor inativado que ainda tem conta a pagar;
- fornecedor que recebe sem Pix nem conta cadastrados (o pagamento sai "na mão");
- fornecedor que ninguém usa há anos.

A qualidade do cadastro (ADR 0185) cobre unidades e moradores, não fornecedores.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/supplier-health`. O service é `SupplierHealth`. Olha os fornecedores
não excluídos.

### O que aponta

- **`invalid_document`:** CPF ou CNPJ com os dígitos verificadores errados (`InvitationRules::validDocument`).
- **`similar_names`:** grupos com o mesmo nome normalizado (regra pura `normalizeName`): sem acento, pontuação e
  diferença de maiúsculas, e sem os sufixos do fim (LTDA, ME, EPP, EIRELI, S/A, MEI, CIA). É provável cadastro em
  dobro.
- **`inactive_in_use`:** fornecedor inativo com contas a pagar em aberto, com a quantidade e o valor.
- **`no_payment_data`:** fornecedor ativo pago nos últimos 12 meses sem chave Pix e sem conta bancária. Sai só a
  marca, nunca os dados.
- **`dormant`:** fornecedor ativo, cadastrado há mais de 24 meses, sem nenhum lançamento nesse tempo, candidato a
  inativar.

### Escopo

- **Contas a pagar:** `inactive_in_use` e `no_payment_data` olham só os condomínios do escopo do usuário.
- **`dormant`:** precisa olhar todos os condomínios, então só aparece para quem tem acesso a todos. Para os outros vem
  `null`, com o aviso `partial_scope`.

### Dados e permissões

- **Dados expostos:** do fornecedor saem id, nome, nome fantasia, categoria, situação e documento mascarado. E-mail,
  telefone, banco e Pix não saem.
- **Tamanho:** cada lista vem limitada a 200 itens, com a marca `truncated`.
- **Permissão:** exige `erp.access`, `erp.suppliers.view` e `erp.entries.view`; sem uma delas, 403 (fail-closed).
- **Consultas:** parametrizadas; a lista de condomínios usa marcadores `?`.

## Consequências

- **Testes.** `SupplierHealthTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - **usuário de um condomínio:**
    - as quatro listas e o resumo;
    - o excluído de fora;
    - a conta a pagar de outro condomínio, cancelada ou paga de fora;
    - a ausência de dados sensíveis;
    - o aviso `partial_scope`;
  - **usuário com acesso a todos:** as contas de todos os condomínios e o `dormant`, sem o recém-cadastrado;
  - **regra pura do nome:** acento, caixa, pontuação, S/A, S.A., sufixo só no fim;
  - o controller e a rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration, sem tabela nova e sem gravação.** Juntar fornecedores duplicados (mover lançamentos de um para
  outro) seria gravação e fica para outro ciclo, com aprovação.
- **Sem tela nova neste patch.** A tela de fornecedores pode mostrar o resumo quando a frente de layout quiser; quando a
  tela existir, ela ganha o artigo da Central de Ajuda.
