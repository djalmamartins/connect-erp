<!-- markdownlint-disable MD024 -->
# 0252 — Cobertura dos contatos úteis do condomínio

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - cadastro de contatos úteis (AP-015), só como cadastro reusado;
  - 0185 (qualidade do cadastro: mesmo espírito de apontar o que falta).

## Contexto

O cadastro de contatos úteis (AP-015) guarda os telefones de emergência, encanador, eletricista, gás e demais serviços
para os moradores e a portaria, do próprio condomínio ou compartilhados com todos (sem condomínio). Ninguém confere, porém,
se o condomínio tem um contato ativo para cada serviço: a portaria descobre que falta o eletricista na hora da pane.

Busquei `erp_useful_contacts` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro e os dados de exemplo
usam a tabela; nenhum relatório confere a cobertura.

## Decisão

Rota nova: `GET /api/v1/reports/contact-coverage?condominium_id=`. O service é `ContactCoverage`, em
`Source\Services\Erp`.

### Reuso

- **Serviços:** os do cadastro (`RegistryService::definition('contacts')`).
- **Regra das listas do cadastro:** o condomínio vê os contatos próprios e os compartilhados; para a cobertura, só os
  ativos e sem excluídos.

### Linhas

A regra pura `summarize()` monta uma linha por serviço, sempre os do cadastro, com:

- `own`: contatos ativos do condomínio;
- `shared`: contatos ativos compartilhados com todos os condomínios;
- `inactive_own`: contatos inativos do condomínio;
- `with_email`: contatos ativos com e-mail;
- `covered`: `own` + `shared` maior que zero.

Serviço desconhecido conta como "Outro". `totals` soma as linhas. Fora das linhas:

- `covered_services` e `services`: os serviços cobertos e o total de serviços;
- `missing_essential`: os serviços essenciais (emergência, encanador, eletricista e gás) sem nenhum contato ativo.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.contacts.view`, as mesmas do cadastro; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só os serviços e as contagens. Não saem nome, telefone, e-mail ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ContactCoverageTest`:**
  - o resumo: os serviços do cadastro, o compartilhado inativo que não conta, o inativo que não cobre, o desconhecido como
    "Outro", os essenciais em falta e o total igual à soma das linhas;
  - a consulta com os compartilhados, sem outro condomínio nem excluídos, e sem dado do contato;
  - validação, escopo (404) e contrato; controller e rota.
- **`Integration\ContactCoverageTest`** (MariaDB, banco `*_test`):
  - contatos gravados pelo cadastro, um inativado, e um compartilhado;
  - as linhas, os totais e os essenciais em falta;
  - somente leitura e sem dado do contato; o escopo.
- **Sem tela nova neste patch.** A tela de contatos úteis pode ganhar o quadro da cobertura; quando ganhar, vem o artigo da
  Central de Ajuda.
