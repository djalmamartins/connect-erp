<!-- markdownlint-disable MD024 -->
# 0257 — Inativação em lote dos contatos úteis duplicados

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - 0252 (cobertura dos contatos úteis);
  - cadastro de contatos úteis (AP-015), só como cadastro reusado.

## Contexto

O cadastro de contatos úteis (AP-015) guarda os contatos do condomínio e os compartilhados com todos (sem condomínio). A
cobertura dos contatos (ADR 0252) conta os ativos por serviço.

Quando a administradora passa a compartilhar o telefone dos bombeiros ou do eletricista de confiança, as cópias que cada
condomínio cadastrou continuam ativas. A portaria vê o mesmo telefone duas ou três vezes, e a cópia antiga deixa de ser
corrigida quando o compartilhado muda. Hoje cada cópia é inativada à mão.

Busquei `erp_useful_contacts` em `source/`, no README das ADRs e nos handoffs 02xx. Só usam a tabela o cadastro, os dados
de exemplo e a cobertura (somente leitura). Nenhuma ação em lote existe para os contatos.

## Decisão

Rota nova: `POST /api/v1/useful-contacts/deactivate-duplicates {condominium_id, contact_ids?, dry_run?}`, antes do laço
dos cadastros (`/useful-contacts/{id}`). O service é `ContactDeduplication`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada cópia é regravada pelo `RegistryService::save` do cadastro "contacts", só com a situação inativa.
  Valem as regras e a auditoria update do formulário.
- **Telefone:** comparado só com os dígitos, como o formulário grava.
- **O contato que fica não muda.**

### Regra pura `duplicates()`

Vale sobre os contatos ativos que o condomínio vê, os próprios e os compartilhados:

- mesmo serviço e mesmo telefone formam um grupo; o telefone conta só os dígitos, sem o 55 do país (`phoneKey()`);
- no grupo com contato compartilhado, fica o compartilhado mais antigo, e todos os próprios são cópias;
- no grupo só de próprios, fica o mais antigo (menor id), e os demais são cópias;
- contato sem telefone não é comparado;
- só contatos próprios do condomínio são inativados; os compartilhados ficam como estão.

### Regra pura `problem()`

Com `contact_ids`, recusa o contato:

- não encontrado no condomínio, o que vale também para os compartilhados;
- já inativo;
- que não é cópia de nenhum contato ativo.

Um recusado desfaz todos (`_form` e `_contact`). Sem ids, entram todas as cópias do condomínio.

### Execução

- **Tudo ou nada:** uma transação com os contatos próprios travados (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `deduplicate` por contato (serviço e o contato que fica), só quando
  grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.contacts.view` e `erp.contacts.manage`, as do cadastro. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou contato recusado, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, serviço, o id do contato que fica e se ele é compartilhado. Não saem nome, telefone, e-mail ou
  observação. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ContactDeduplicationTest`:**
  - a regra pura do telefone e das cópias: o compartilhado mais antigo fica, entre próprios fica o menor id, outro
    serviço não é cópia, sem telefone não compara e compartilhado nunca sai;
  - a validação e o escopo;
  - as recusas antes do formulário, uma por caso;
  - nada a fazer sem cópias;
  - o contrato de reuso, o controller e a rota.
- **`Integration\ContactDeduplicationTest`** (MariaDB, banco `*_test`):
  - a prévia e a gravação pelo cadastro: a cópia do compartilhado e a cópia do próprio, com o telefone formatado de
    jeitos diferentes;
  - o mesmo telefone em outro serviço fica;
  - as auditorias `deduplicate` e update;
  - nenhum aviso e nenhum dado do contato;
  - um segundo lote vazio;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch.** A tela de contatos úteis pode ganhar o botão "Inativar cópias"; quando ganhar, vem o
  artigo da Central de Ajuda.
