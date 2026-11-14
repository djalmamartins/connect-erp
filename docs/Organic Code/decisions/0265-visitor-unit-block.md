<!-- markdownlint-disable MD024 -->
# 0265 — Bloqueio em lote dos visitantes de unidades sem morador ativo

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0206 (saída de moradores da unidade);
  - 0241 (encerramento dos vínculos vencidos);
  - 0234 e 0235 (autorizações de visitantes e bloqueio das vencidas, mesmo desenho);
  - cadastro de visitantes (AP-012), só como cadastro reusado.

## Contexto

O cadastro de visitantes (AP-012) guarda os visitantes frequentes e os prestadores autorizados por unidade, muitos com
acesso permanente: a diarista, o personal, o namorado da filha.

Quando a família se muda, a saída da unidade (ADR 0206) encerra os vínculos e pode inativar veículos e animais. O
encerramento dos vínculos vencidos (ADR 0241) acerta os moradores. Os visitantes, porém, seguem "Liberado", e a portaria
continua deixando entrar quem foi autorizado por quem já não mora ali.

O bloqueio das autorizações vencidas (ADR 0235) só pega quem tem data vencida. Quem tem acesso permanente fica liberado.

Busquei `erp_visitors` em `source/`, no README das ADRs e nos handoffs 02xx. Usam a tabela o cadastro, o relatório e o
bloqueio das autorizações (0234 e 0235), mas nenhum confere se a unidade ainda tem morador. A saída da unidade (0206) não
toca os visitantes.

## Decisão

Rota nova: `POST /api/v1/visitors/block-unattached {condominium_id, visitor_ids?, reason, dry_run?}`, antes do laço dos
cadastros (`/visitors/{id}`). O service é `VisitorUnitBlock`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada visitante é regravado pelo `RegistryService::save` do cadastro "visitors", só com a situação
  "Bloqueado", como no bloqueio das autorizações vencidas (0235). Valem as regras e a auditoria update do formulário.
- **Morador ativo:** a regra das listas do cadastro: vínculo ativo, sem excluído e sem fim de vínculo passado.
- **Sem aviso:** nada vai ao morador nem ao visitante. Para liberar de novo, o formulário.

### Quais entram

Entram os visitantes liberados do condomínio vinculados a uma unidade (`unit_id`) que não tem nenhum morador ativo, ou só
os dos `visitor_ids` informados.

### Regra pura `problem()`

Recusa o visitante:

- não encontrado no condomínio;
- já bloqueado;
- sem unidade vinculada, isto é, só com o texto da unidade: vincule no cadastro;
- cuja unidade ainda tem morador ativo;
- cuja unidade está inativa: o formulário não regrava;
- sem data nem acesso permanente: o formulário não regrava.

Sem ids, os que ficam de fora são contados:

- `skipped_unlinked`: sem unidade vinculada;
- `skipped_inactive_unit`: unidade inativa;
- `skipped_incomplete`: sem data nem acesso permanente.

Com ids, um recusado desfaz todos (`_form` e `_visitor`).

### Execução

- **`permanent`:** quantos dos bloqueados tinham acesso permanente.
- **Tudo ou nada:** uma transação com os visitantes travados (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `block_unattached` por visitante (acesso permanente, data e
  motivo), só quando grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.visitors.view` e `erp.visitors.manage`, as do cadastro, e `erp.residents.view`,
  porque o morador ativo vem do cadastro de moradores. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou visitante recusado, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, se o acesso era permanente e a data "autorizado até". Não saem nome, documento, telefone,
  unidade ou observação. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VisitorUnitBlockTest`:**
  - a regra pura;
  - a validação e o escopo;
  - as recusas antes do formulário, uma por caso;
  - as contagens dos que ficam de fora (sem vínculo, unidade inativa, sem data);
  - o morador com fim de vínculo passado, inativo ou excluído contando como unidade vazia;
  - o contrato de reuso, o controller e a rota.
- **`Integration\VisitorUnitBlockTest`** (MariaDB, banco `*_test`):
  - a prévia e o bloqueio pelo cadastro do permanente e do com data da unidade vazia;
  - o da unidade com morador fica;
  - as auditorias `block_unattached` e update;
  - nenhum aviso e nenhum dado do visitante;
  - um segundo lote vazio;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch.** A tela de visitantes ou a saída da unidade podem ganhar o botão "Bloquear os visitantes
  da unidade vazia"; quando ganharem, vem o artigo da Central de Ajuda.
