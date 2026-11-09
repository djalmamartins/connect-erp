# 0162 — CNPJ do condomínio opcional, busca e vínculo de pessoas, dados de exemplo do laboratório

Data: 2026-11-09 · Status: proposto para revisão · Correções pedidas pelo Djalma depois de testar a main

## Contexto

O Djalma testou a main e pediu quatro coisas. Primeiro, alguns prédios não têm CNPJ. Segundo, o cadastro de
proprietário e inquilino precisa achar e reaproveitar uma pessoa já cadastrada. Terceiro, uma pessoa pode ter
várias unidades, inclusive em condomínios diferentes, e um inquilino pode ter várias salas. Quarto, é preciso
ter dados para testar a inadimplência.

## Decisão

### A) CNPJ do condomínio opcional

- O `CondominiumValidator` aceita o CNPJ vazio, que é gravado como NULL.
  - Preenchido, o CNPJ continua validado pelos dígitos e a unicidade continua no `CondominiumRegistry`.
  - Texto sem nenhum dígito é recusado, em vez de passar calado.
- A coluna já aceitava NULL. A migration `20261109_0702` só troca `''` por NULL, para que dois condomínios
  sem CNPJ não colidam em `uq_condominiums_document`.
- O checklist de chegada já tem o status "Não se aplica" para o item "CNPJ do condomínio" e não exige CNPJ.
- O convite do morador (`InvitationRules`) continua exigindo o CPF/CNPJ válido do morador. Essa regra não
  muda.

### B) `GET /api/v1/people/search?q=&role=owner|tenant|any&limit=20`

- **Permissão:** `erp.access` e `erp.units.view`.
- **Parâmetros:**
  - `q`: no mínimo 3 caracteres. Se só tiver dígitos e pontuação de documento, a busca é por CPF/CNPJ, por
    igualdade. Senão, é por nome (contém).
  - `role`: o padrão é `any`, que traz owner e tenant.
  - `limit`: de 1 a 20.
- **Resposta:** `{"data":[{"id","name","document_masked","person_type":"PF"|"PJ","links":[{"unit_id","unit_label","condominium_slug","condominium_name","role"}]}]}`.
  - Agrupa as linhas de `erp_residents` pela pessoa (o documento; sem documento, cada linha é uma pessoa).
    `id` é a primeira linha da pessoa.
  - `document_masked` vem no formato `***.456.789-**` (CPF) ou `**.345.678/****-**` (CNPJ).
  - `links` traz só unidades de condomínios do escopo. A pessoa de fora do escopo aparece com `links` vazio.
  - E-mail e telefone nunca saem na resposta.
- **Limite e auditoria:** 300 buscas por hora por usuário, contadas na auditoria, como nos relatórios. A
  auditoria guarda o tipo da busca, o vínculo e quantos resultados vieram; não guarda o texto buscado.
- **Erros:** 422 para parâmetro inválido e 429 para limite.

### C) Uma pessoa em várias unidades

- **Modelo:** `erp_residents` é uma linha por vínculo (pessoa + unidade + papel). A regra única é condomínio +
  unidade + vínculo + documento. Nada impede o mesmo documento em várias unidades ou condomínios, então não
  houve mudança no modelo.
- **`POST /api/v1/people/{id}/links`** com `{"unit_ids":[...],"role":"owner"|"tenant","starts_at":"AAAA-MM-DD"}`:
  - **Permissão:** `erp.access`, `erp.units.view` e `erp.residents.manage`. O CSRF é conferido no
    `ApiAuthenticated`.
  - **Escopo:** unidade inexistente, inativa ou fora do escopo responde 404, sem gravar nada.
  - **Gravação:**
    - aceita de 1 a 50 unidades por pedido, numa transação;
    - é idempotente: vínculo que já existe (mesma unidade, papel e documento, ou mesmo nome quando não há
      documento) vem em `existing`;
    - os vínculos novos vêm em `created`, com o id e a unidade;
    - grava uma auditoria por vínculo novo, sem dado pessoal.
  - **Dados copiados:** de uma pessoa de fora do escopo, só nome e documento. E-mail e telefone só são
    copiados quando a pessoa de origem está no escopo.

### D) `php tools/lab-dados-exemplo.php [--remover]`

- **Trava:** recusa rodar a menos que as três condições valham. Em dúvida, recusa.
  - `ERP_DEMO_DATA` está ligado (true). O valor vem da constante que o `Config.php` define a partir do
    `.env`; sem a constante, vem do `getenv`.
  - O ambiente (`MOVESOS_ENV` ou `APP_ENV`) é lab, dev, development ou local.
  - O nome do banco tem lab, dev, local ou sandbox, e não tem "prod".
- **O que cria** (`LabSampleData`), com "EXEMPLO" no começo de cada nome:
  - dois condomínios: o residencial, com 6 unidades e sem CNPJ, e o comercial, com 2 salas;
  - 3 proprietários, um deles com unidades nos dois condomínios, e 1 inquilino com as duas salas;
  - contas a receber: vencidas há 30, 60 e 120 dias (esta é a candidata a acordo), uma em dia, uma paga e
    uma unidade sem conta.
- **O que não faz:** boleto, banco real ou e-mail.
- **Idempotente:** reaproveita o que já existe.
- **`--remover`:** apaga só o que tem a marca, dentro dos condomínios marcados, numa transação. Se algum
  registro de exemplo ainda for usado por outro cadastro, nada é apagado.

## Consequências

- `PeopleAndLabDataTest` cobre A a D:
  - A: o CNPJ opcional e validado;
  - B: os parâmetros, a máscara, o agrupamento por pessoa, o escopo e o contrato exato da resposta;
  - C: o vínculo idempotente e transacional, o 404 sem gravar e a cópia sem e-mail e telefone;
  - D: a trava e o plano dos dados de exemplo.
- O `CondominiumValidatorTest` foi ajustado (CNPJ opcional). A `NewRoutesPermissionMatrixTest` ganhou o
  controller `People`.
- A tela fica com a frente de interface: o `required` do CNPJ no formulário já foi tirado lá, e a busca de
  pessoa e o vínculo em lote consomem esta API.

## Ajustes na integração (Preparar, 09/11)

- **Escopo na busca.** A busca por nome só traz pessoas com vínculo em algum condomínio do escopo. A pessoa de fora
  do escopo só aparece pelo CPF/CNPJ completo, com `links: []`. O motivo é que a busca por nome expunha nomes de
  moradores de condomínios que o usuário não acessa.
- **Vínculo de pessoa de fora do escopo.** O `POST /people/{id}/links` exige `document` igual ao da pessoa, o mesmo
  CPF/CNPJ digitado na busca. Sem ele, responde 404. Isso impede copiar nome e CPF de outro condomínio só trocando
  o id. O OpenAPI documenta o campo.
- **Trava dos dados de exemplo.** Além de nome de banco com lab, dev, local ou sandbox, a trava aceita igualdade
  exata com `ERP_DEMO_DB`, porque o lab usa `connect_legacy`.
  - Continuam obrigatórios `ERP_DEMO_DATA=true` e o ambiente lab ou dev.
  - Banco com "prod" no nome é sempre recusado.
  - `ERP_DEMO_DB` entrou na lista de chaves do `Config.php` e no `.env.example`.
- **Migration.** A 20261109_0702 não colidiu com nenhuma outra e ficou com esse número.
