# 0014 — Motor único dos cadastros do ERP (grupo Cadastros)

Data: 2026-11-08 · Status: aceito (campos PROPOSTOS, aguardam revisão do Djalma)

## Contexto

O Djalma pediu (08/11/2026) que todas as páginas de cadastro fossem implementadas com
banco, antes do módulo Condomínios: proprietário, inquilino, funcionário, fornecedor,
advogados, veículos, visitantes, animais, contatos úteis, síndico e conselho fiscal.

Revisão do layout (catálogo `components/layout/catalog.php`): o grupo Cadastros tinha 8
páginas com dados de demonstração (AP-005, 009, 010, 011, 012, 013, 015, 016). Não havia
página de síndico e o Conselho (AP-014) estava em Condomínios.

As fichas `docs/specs/pages/ap-0xx-*.md` continuam com campos PENDENTES: a auditoria
APControle é somente leitura e não confirma campos nem regras.

## Decisão

- **Um motor para todos os cadastros** (CLAUDE.md: um componente compartilhado para
  comportamentos repetidos). Cada cadastro é uma entrada em
  `source/Services/Erp/Registry/Definitions.php` (tabela, rota, recurso da API,
  permissões, escopo, campos, regras). Um único `RegistryValidator` (puro),
  `RegistryService` (MariaDB, escopo, auditoria), controller de tela
  `Erp\Connect\Registry`, controller de API `Api\V1\Registry` e dois templates
  (`pages/registry/index.php`, `pages/registry/form.php`).
- **AP-014 vai para Cadastros como "Síndico e conselho"** (`/cadastros/sindico-e-conselho`),
  com cargos síndico, subsíndico, conselheiro fiscal, consultivo e suplente. Regra: um
  único síndico ativo por condomínio. Síndico profissional pode ter CNPJ e não ter unidade.
  Alterado em `tools/erp-catalog.py` (o catálogo é gerado).
- **Proprietário e inquilino ficam numa página só** (AP-005, já assim no catálogo), com o
  campo Vínculo (proprietário, inquilino, morador, responsável financeiro) e filtro por ele.
- **Escopo (ADR-002)**: `required` (condomínio obrigatório, filtrado por
  `CondominiumScope`), `optional` (contatos úteis: sem condomínio vale para todos; criar ou
  alterar esses exige `erp.condominiums.all`) e `none` (fornecedores e advogados são da
  administradora Connect). Registro fora do escopo responde 404 na tela e na API.
- **Unidade em texto** (`unit_label`) até existir o módulo Unidades (AP-006, em
  Condomínios). A migração para `unit_id` fica para o módulo de condomínios.
- **Permissões** `erp.<cadastro>.view` e `erp.<cadastro>.manage` (migration
  `20261108_0202`), concedidas a `developer` e `super_admin`. Prefixo `erp.` nega sem
  registro (ADR 0006). A sidebar esconde o que o usuário não vê; a rota valida de novo.
- **Validação estrita**: CPF/CNPJ com dígito verificador (`Support\Erp\BrDocument`, sem
  usar o `is_document()` legado, que aceita CNPJ com DV errado), placa antiga e Mercosul,
  OAB com UF válida, datas reais, valores em centavos, campos fora da definição ignorados.
- **Exclusão lógica** (`deleted_at`) com auditoria (`system_audit_logs`, valores de
  documento, e-mail e telefone mascarados pelo `Audit`). O histórico aparece na tela.
- **Telas sem JavaScript obrigatório**: filtros e paginação no servidor (25 por página),
  formulário em página própria (`/novo`, `/{id}`), POST com CSRF e padrão PRG; exclusão
  confirmada em diálogo com POST `/{id}/excluir`.
- **API REST** `/api/v1/{residents, board-members, employees, suppliers, lawyers,
  vehicles, visitors, pets, useful-contacts}` com GET, POST, GET/{id}, PUT/PATCH/{id},
  DELETE/{id}. Contrato em `docs/api/registries.openapi.yaml`, gerado das definições por
  `tools/registry-openapi.php`.

## Consequências

- Novo cadastro = nova entrada em `Definitions.php` + tabela + permissões; telas, API,
  validação e testes de consistência vêm do motor.
- Campos e opções de cada cadastro são propostas: mudar uma opção exige atualizar a
  definição (e a migration, quando a coluna é `enum`).
- Pendências: confirmar os campos com o Djalma; ligar `unit_label` às unidades; vincular
  proprietário/inquilino ao financeiro (responsável pela cobrança); upload de documentos
  e fotos (animais, veículos) quando houver armazenamento definido; exportação.
