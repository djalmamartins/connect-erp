<!-- markdownlint-disable MD024 -->
# 0233 — Destino em lote dos achados e perdidos fora do prazo

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0042 (portaria: AP-134 achados e perdidos), só como cadastro reusado;
  - 0232 (resumo dos achados e perdidos: mostra a idade dos guardados);
  - 0221 e 0231 (mesmo padrão: regravação pelo cadastro, tudo ou nada, prévia e auditoria).

## Contexto

O regimento costuma fixar um prazo de guarda para os achados e perdidos; passado o prazo, o item vai para doação ou
descarte. O resumo 0232 mostra quantos itens estão guardados há mais de 90 ou 180 dias, mas hoje cada um é baixado à mão
no formulário. Busquei `erp_lost_found` em `source/`, no README das ADRs e nos handoffs 02xx: só o cadastro, o resumo
0232 e os dados de exemplo usam a tabela; nenhuma ação em lote existe.

## Decisão

Rota nova: `POST /api/v1/lost-found/dispose` com `{condominium_id, destination, older_than_days?, item_ids?, reason,
dry_run?}`. O service é `LostFoundDisposal`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada item é regravado pelo `RegistryService::save` do cadastro `lost_found`, só com a situação
  "Descartado", a única saída sem dono do cadastro. Valem as regras e a auditoria `update` do formulário; os demais
  campos ficam como estão.
- **Destino sem migration:** `destination` (`doacao` ou `descarte`) e o motivo ficam na auditoria `dispose`; o cadastro
  não ganha campo nem situação nova.
- **Mesmo desenho da 0221 e da 0231:** ids opcionais, regra pura, tudo ou nada, prévia e auditoria.

### Quais itens entram

- **Sem `item_ids`:** os itens guardados do condomínio achados há mais de `older_than_days` dias (padrão 90, de 30 a
  3650), ou seja, com `found_on` até `found_until` (hoje menos o prazo).
- **Com `item_ids`:** só os informados. A regra pura `problem()` recusa o item:
  - não encontrado no condomínio (inclusive excluído);
  - que não está guardado (devolvido ou descartado);
  - ainda dentro do prazo de guarda.

### Gravação

- **Tudo ou nada:** numa transação com os itens travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz `_form`
  (ou os erros do formulário) e `_item`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `dispose` por item (destino, data de achado, dias guardados e
  motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres. **Limite:** até 500 itens por vez.
- **Nenhum aviso é enviado.** Só fica a notificação interna "Alteração no sistema" que toda auditoria grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.lost_found.view` e `erp.lost_found.manage`, as mesmas do cadastro; sem uma
  delas, 403 (fail-closed).
- **Escopo (ADR-002):** o condomínio precisa estar no escopo do usuário; fora dele, 404. Entrada inválida, 422.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, data de achado e dias guardados. Não saem item, local, quem achou ou observação.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\LostFoundDisposalTest`:**
  - a regra pura, com o último dia do prazo e os dois destinos;
  - validação (destino, prazo, motivo, ids) e escopo (404);
  - as recusas antes do formulário (devolvido, dentro do prazo, de outro condomínio, excluído, inexistente), sem
    transação aberta e sem gravar;
  - contrato de reuso: só pelo cadastro, destino na auditoria, sem aviso e sem dado do item; controller e rota.
- **`Integration\LostFoundDisposalTest`** (MariaDB, banco `*_test`):
  - itens gravados pelo cadastro;
  - a prévia com os dias guardados;
  - o destino dos fora do prazo, com o recente e o devolvido intactos e os dados preservados;
  - as auditorias `dispose` (com o destino) e `update`;
  - nenhum aviso (outbox, e-mail e notificação além da interna da auditoria) e sem dado do item;
  - os ids com um item dentro do prazo desfazendo o lote; o escopo.
- **Sem tela nova neste patch.** A lista dos achados e perdidos pode ganhar "Dar destino aos vencidos"; quando ganhar,
  vem o artigo da Central de Ajuda.
