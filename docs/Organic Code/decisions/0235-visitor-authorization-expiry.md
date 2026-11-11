<!-- markdownlint-disable MD024 -->
# 0235 — Bloqueio em lote das autorizações de visitantes vencidas

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - AP-012 (cadastro de visitantes), só como cadastro reusado;
  - 0234 (situação das autorizações: conta as vencidas e ainda liberadas e anotou esta ação);
  - 0231 (mesmo desenho: baixa pelo cadastro, tudo ou nada, prévia e auditoria).

## Contexto

O relatório 0234 mostra quantos visitantes seguem "Liberado" com a data "autorizado até" já passada: a portaria pode
deixar entrar quem não está mais autorizado. Hoje cada um é bloqueado à mão no formulário. Busquei `erp_visitors` em
`source/`, no README das ADRs e nos handoffs 02xx: só o cadastro, o relatório 0234 e os dados de exemplo usam a tabela;
nenhuma ação em lote existe.

## Decisão

Rota nova: `POST /api/v1/visitors/expire` com `{condominium_id, before?, visitor_ids?, reason, dry_run?}`. O service é
`VisitorAuthorizationExpiry`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada visitante é regravado pelo `RegistryService::save` do cadastro `visitors`, só com a situação
  "Bloqueado" (a equivalente do cadastro para "não liberado"). Valem as regras e a auditoria `update` do formulário; os
  demais campos ficam como estão. Para liberar de novo, o formulário.
- **Mesmo desenho da 0231:** ids opcionais, data de corte, regra pura, tudo ou nada, prévia e auditoria `expire`.
- **Nenhum aviso:** o cadastro não envia aviso; nada vai ao morador nem ao visitante.

### Quais visitantes entram

- **Sem `visitor_ids`:** os liberados do condomínio, sem acesso permanente, com "autorizado até" anterior a `before`
  (padrão hoje; nunca depois de hoje). São os `expired_active` do relatório 0234.
- **Com `visitor_ids`:** só os informados. A regra pura `problem()` recusa o visitante:
  - não encontrado no condomínio (inclusive excluído);
  - já bloqueado;
  - com acesso permanente (a autorização não vence);
  - sem data (corrija no cadastro: informe a data ou marque acesso permanente);
  - cuja autorização ainda não venceu.

### Gravação

- **Tudo ou nada:** numa transação com os visitantes travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz
  `_form` (ou os erros do formulário) e `_visitor`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `expire` por visitante (data vencida, dias vencidos e motivo), só
  quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 visitantes por vez.
- **Nenhum aviso é enviado.** Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.visitors.view` e `erp.visitors.manage`, as mesmas do cadastro; sem uma delas,
  403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, data vencida e dias vencidos. Não saem nome, documento, telefone, unidade ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VisitorAuthorizationExpiryTest`:**
  - a regra pura, com a borda da data de corte;
  - validação e escopo (404);
  - as recusas antes do formulário (bloqueado, permanente, sem data, ainda válido, de outro condomínio, excluído,
    inexistente), sem transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, sem aviso e sem dado pessoal; controller e rota.
- **`Integration\VisitorAuthorizationExpiryTest`** (MariaDB, banco `*_test`):
  - unidade e visitantes pelo cadastro, com CPF e telefone;
  - a prévia com os dias vencidos;
  - o bloqueio dos vencidos, com o ainda válido e o permanente intactos e os dados preservados;
  - as auditorias `expire` e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem dado pessoal;
  - os ids com um permanente desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista de visitantes pode ganhar "Bloquear vencidos"; quando ganhar, vem o artigo da
  Central de Ajuda.
