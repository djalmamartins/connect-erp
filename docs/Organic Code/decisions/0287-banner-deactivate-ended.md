<!-- markdownlint-disable MD024 -->
# 0287 — Banners encerrados ainda ativos inativados em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0286 (situação dos banners: `ended_active_ids`);
  - 0085 (configurações do ERP: banners do aplicativo, AP-156; sem condomínio, valem para todos).

## Contexto

A situação dos banners (ADR 0286) mostra os banners que já acabaram e continuam ativos: esquecidos no cadastro. Para
limpar, era preciso abrir e inativar um por um.

## Decisão

Rota nova: `POST /api/v1/banners/deactivate-ended {condominium_id, banner_ids?, reason, dry_run?}`, logo depois de
`GET /reports/banners`. O service é `BannerDeactivation`, em `Source\Services\Erp`.

### Reuso

- **Cadastro:** `RegistryService::definition('banners')` dá a tabela e a permissão (`erp.settings`).
- **Quem altera os de todos:** `RegistryService::canWrite`, a mesma regra do cadastro: um banner sem condomínio só é
  alterado por quem acessa todos os condomínios.
- **Situação no dia:** `BannerOverview::state` (ADR 0286), no dia do banco (`SELECT NOW()`).
- **Lote:** o mesmo desenho das ADRs 0283 e 0285 (validação, escopo, `FOR UPDATE`, tudo ou nada, prévia, auditoria só
  quando grava).

### Regras

- **Entram, sem `banner_ids`:**
  - os banners do condomínio encerrados (o fim é antes de hoje) e ainda ativos;
  - os de todos os condomínios na mesma situação, só para quem pode alterá-los. Para os outros, ficam de fora e são
    contados em `skipped_global`.
- **Com `banner_ids`:** só os informados. A regra pura `problem()` recusa:
  - banner não encontrado no condomínio nem entre os de todos (outro condomínio ou apagado);
  - banner já inativo;
  - banner que ainda não acabou (no ar ou agendado). Acabar hoje ainda é estar no ar;
  - banner de todos os condomínios, para quem não acessa todos.
- **Tudo ou nada:** numa transação, com os banners travados (`FOR UPDATE`). Um recusado desfaz todos (422, `_form` e
  `_banner`). O `UPDATE` só vale com `status = 'active'`; se outro pedido mudou o banner antes, é 409.
- **Prévia:** `dry_run` passa por tudo e desfaz.
- **O que muda:** só o `status`, de `active` para `inactive`, e `updated_by`. Nada é apagado; as datas, o título e o texto
  ficam.
- **Auditoria:** um registro `deactivate` por banner (motivo e tamanho do lote), só quando grava.
- **Limites:** até 500 por vez; motivo obrigatório (5 a 255 caracteres).

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.settings.view` e `erp.settings.manage`, as da tela de banners. Sem uma delas,
  403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Dados que saem:** ids, alcance e contagens. Não saem título, texto nem quem cadastrou.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\BannerDeactivationTest`:**
  - a regra pura nos limites do dia;
  - a validação e o escopo;
  - a prévia e a gravação, só no status, sem apagar, com o de todos os condomínios de fora e contado à parte;
  - com acesso a todos, os de todos entram;
  - as recusas, uma por caso, desfazendo o lote;
  - o relatório da 0286 depois da inativação;
  - nenhum título nem texto;
  - o contrato, o controller, a rota e a OpenAPI.
- **`Integration\BannerDeactivationTest`** (MariaDB, banco `*_test`):
  - banners em `erp_banners`, com as datas pelo dia do banco;
  - a prévia sem auditoria e a gravação com auditoria;
  - o de todos os condomínios recusado sem acesso a todos;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
