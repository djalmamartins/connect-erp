<!-- markdownlint-disable MD024 -->
# 0241 — Encerramento em lote dos vínculos com o fim já passado

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0206 (saída de moradores da unidade: encerra os vínculos de uma unidade na data de saída);
  - 0240 (perfil de ocupação: já desconta os vínculos com o fim vencido);
  - 0090 (convites do portal do morador);
  - 0239 (mesmo desenho: destino em lote pelo cadastro, tudo ou nada, prévia e auditoria).

## Contexto

O cadastro de proprietários e inquilinos guarda o fim do vínculo (`ends_on`), mas a situação só muda quando alguém abre o
formulário. O inquilino com o contrato terminado em março segue "ativo": entra na cobrança por e-mail, no portal do
morador e nas listas. O perfil de ocupação (0240) mostra a diferença, porque conta só o vínculo valendo na data.

Busquei `erp_residents` e `ends_on` em `source/`, no README das ADRs e nos handoffs 02xx. A saída de moradores (0206)
não cobre o caso:

- trabalha numa unidade por vez;
- grava a data de saída informada no lugar do fim;
- serve para o dia da mudança, não para acertar o cadastro.

Nenhuma outra ação encerra em lote os vínculos pelo fim que já está no cadastro.

## Decisão

Rota nova: `POST /api/v1/residents/expire-ended` com `{condominium_id, before?, relationships?, resident_ids?, reason,
dry_run?}`. O service é `ResidentLinkExpiry`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada vínculo é regravado pelo `RegistryService::save` do cadastro `residents`, só com a situação inativa.
  Valem as regras e a auditoria `update` do formulário. Os demais campos ficam como estão, inclusive o próprio fim.
  Nada é apagado.
- **Convites:** como na saída de moradores (0206), os convites pendentes do portal dessas pessoas são revogados.
- **Nenhum aviso:** o cadastro não envia aviso.

### Quais vínculos entram

- **Sem `resident_ids`:** os vínculos ativos do condomínio com fim anterior a `before` (padrão hoje; nunca depois de
  hoje), dos tipos em `relationships` (padrão os quatro: owner, tenant, resident e financial). Os de unidade inativa ficam
  de fora, porque o formulário não os regrava; a contagem sai em `skipped_inactive_unit`.
- **Com `resident_ids`:** só os informados. A regra pura `problem()` recusa o vínculo:
  - não encontrado no condomínio (inclusive excluído);
  - já encerrado;
  - sem fim informado (use a saída de moradores);
  - cujo fim ainda não passou (o dia do corte não conta como passado);
  - de unidade inativa (corrija no cadastro).

### Gravação

- **Tudo ou nada:** numa transação com os vínculos e os convites travados (`FOR UPDATE`), um recusado desfaz todos. O
  422 traz `_form` (ou os erros do formulário) e `_resident`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim, com os convites que seriam revogados.
- **Auditoria:** a `update` do formulário, a dos convites revogados e um registro `expire` por vínculo (tipo, fim, dias
  desde o fim e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 vínculos por vez; acima disso, a ação recusa e
  pede uma data de corte menor.
- **Nenhum aviso é enviado** ao morador. Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.residents.view` e `erp.residents.manage`, as mesmas do cadastro; sem uma delas,
  403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, tipo de vínculo, fim e dias desde o fim. Não saem nome, documento, contato ou unidade.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ResidentLinkExpiryTest`:**
  - a regra pura, com o dia anterior ao corte, o próprio dia e a unidade inativa; os tipos de vínculo;
  - validação (corte, tipos, motivo, ids) e escopo (404);
  - as recusas antes do formulário (encerrado, sem fim, fim futuro, unidade inativa, de outro condomínio, excluído,
    inexistente), sem transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, mantém o fim, sem aviso e sem dado pessoal; controller e rota.
- **`Integration\ResidentLinkExpiryTest`** (MariaDB, banco `*_test`):
  - vínculos gravados pelo cadastro, com fins passados, futuro e sem fim, e um de unidade depois inativada;
  - a prévia com os dias desde o fim, o de unidade inativa de fora e o convite que seria revogado; o filtro por tipo;
  - o encerramento com o fim mantido, o convite revogado e os demais intactos;
  - as auditorias `expire` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem nome na resposta;
  - os ids com um vínculo ainda valendo desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista de proprietários e inquilinos pode ganhar "Encerrar os vencidos"; quando ganhar,
  vem o artigo da Central de Ajuda.
