<!-- markdownlint-disable MD024 -->
# 0237 — Baixa em lote das inscrições vencidas da lista de espera

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - AP-115 (lista de espera das áreas comuns) e ADR 0053 (chamada da lista), só como cadastro e regra reusados;
  - 0221 e 0231 (mesmo desenho: baixa pelo cadastro, tudo ou nada, prévia e auditoria);
  - 0206 (saída de moradores), que já inativa veículos e animais da unidade na saída.

## Contexto

A lista de espera guarda quem quer uma área comum numa data já reservada. A inscrição nasce "Aguardando" e só sai daí
quando alguém marca "Atendida" ou "Desistiu". Quando a data desejada passa sem vaga, ela fica aguardando para sempre:
polui a lista, o painel operacional e a chamada automática da lista (`OperationalDashboard`). Hoje cada uma é baixada à
mão no formulário.

Busca prévia: `erp_waitlist` só aparece no cadastro e no painel operacional (chamada da lista); nenhuma ação em lote,
ADR ou handoff 02xx baixa inscrições. A ideia sugerida de inativar em lote veículos e animais de unidades vazias já é
coberta, unidade por unidade, pela saída de moradores (0206, `include_vehicles` e `include_pets`), então ficou de fora
para não duplicar.

## Decisão

Rota nova: `POST /api/v1/waitlist/expire` com `{condominium_id, before?, entry_ids?, reason, dry_run?}`. O service é
`WaitlistExpiry`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada inscrição é regravada pelo `RegistryService::save` do cadastro `waitlist`, só com a situação
  "Desistiu" (`inactive`, a saída sem atendimento do cadastro). Valem as regras e a auditoria `update` do formulário; os
  demais campos ficam como estão.
- **Mesmo desenho da 0221 e da 0231:** ids opcionais, data de corte, regra pura, tudo ou nada, prévia e auditoria
  `expire`.
- **Nenhum aviso:** o cadastro não envia aviso; nada vai ao morador.

### Quais inscrições entram

- **Sem `entry_ids`:** as aguardando do condomínio com data desejada anterior a `before` (padrão hoje; nunca depois de
  hoje). As de área comum ou unidade inativa ficam de fora (o formulário não as regrava) e a contagem sai em
  `skipped_inactive_link`.
- **Com `entry_ids`:** só as informadas. A regra pura `problem()` recusa a inscrição:
  - não encontrada no condomínio (inclusive excluída);
  - que não está aguardando (atendida ou desistiu);
  - cuja data ainda não passou;
  - de área comum ou unidade inativa (corrija no cadastro).

### Gravação

- **Tudo ou nada:** numa transação com as inscrições travadas (`FOR UPDATE`), uma recusada desfaz todas. O 422 traz
  `_form` (ou os erros do formulário) e `_entry`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `expire` por inscrição (data desejada, área, se tinha sido
  chamada e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 inscrições por vez.
- **Nenhum aviso é enviado.** Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.reservations.view` e `erp.reservations.manage`, as mesmas do cadastro da lista
  de espera; sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, área comum, data desejada e se tinha sido chamada. Não saem morador, unidade ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\WaitlistExpiryTest`:**
  - a regra pura, com a borda da data de corte e o vínculo inativo;
  - validação e escopo (404);
  - as recusas antes do formulário (atendida, ainda por vir, de outro condomínio, área inativa, unidade inativa,
    excluída, inexistente), sem transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, sem aviso e sem dado do morador; controller e rota.
- **`Integration\WaitlistExpiryTest`** (MariaDB, banco `*_test`):
  - unidade e inscrições pelo cadastro;
  - a prévia com a chamada e a de área inativa de fora;
  - a baixa das vencidas, com a ainda por vir, a atendida e a de área inativa intactas e os dados preservados;
  - as auditorias `expire` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem o nome do morador;
  - os ids com uma inscrição ainda por vir desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista de espera pode ganhar "Dar baixa nas vencidas"; quando ganhar, vem o artigo da
  Central de Ajuda.
