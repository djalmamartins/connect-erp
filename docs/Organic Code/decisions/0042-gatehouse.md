# 0042 — Portaria (AP-131 a AP-136) pelo motor de cadastros

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O passo 7 do `docs/process/IMPLEMENTATION_ORDER.md` começa pelo operacional. Nesse passo, portaria,
documentos e leituras ficaram livres para esta frente. As fichas AP-131 a AP-136 ainda são scaffold;
os campos abaixo seguem as colunas e os campos de `page-specs.php` e são PROPOSTOS.

## Decisão

- **Cinco cadastros novos no motor único** (ADR 0014), grupo **Operacional**. Todos por condomínio
  (escopo `required`), com exclusão lógica, CSRF nas telas, auditoria e a API REST gerada
  (`docs/api/registries.openapi.yaml`). Migration 0525, com tabelas e permissões
  `erp.<cadastro>.view|manage`.
  - **Portarias (AP-131, `/gatehouses`):** nome, tipo (presencial, remota ou híbrida), turnos (1 a 6),
    responsável e telefone.
  - **Previsão de visita (AP-132, `/visit-forecasts`):**
    - unidade, visitante, documento, data, "até" (não antes da data) e quem autorizou;
    - situação: prevista, realizada ou cancelada.
  - **Registro de acessos (AP-133, `/access-logs`):**
    - pessoa, documento, tipo (visitante, prestador, entregador ou morador), unidade, placa, data,
      hora de entrada e de saída;
    - portaria e visita prevista são opcionais, do mesmo condomínio.
  - **Achados e perdidos (AP-134, `/lost-found`):**
    - item, local, data e quem encontrou;
    - devolução com data (não antes do achado), exigindo a quem foi devolvido;
    - situação: guardado, devolvido ou descartado.
  - **Entregas (AP-135, `/deliveries`):**
    - unidade, destinatário, transportadora, rastreio e recebimento;
    - retirada com data (não antes do recebimento), exigindo quem retirou;
    - situação: aguardando retirada, retirada ou cancelada.
- **AP-136 (Registros de Entregas)** é a lista de entregas filtrada pela situação "Retirada"
  (`?situacao=picked_up`), sem tabela nem cadastro próprio: as entregas retiradas são as mesmas
  entregas.
- **Travas de exclusão:**
  - portaria e visita prevista apontadas por um registro ativo não saem (campo `ref`, ADR 0020);
  - uma unidade com previsão, acesso ou entrega não sai (`referenced_by`).
- **Escopo:** registros de condomínio fora do escopo respondem 404. O contexto da barra superior
  (ADR 0030/0032) filtra as telas como nos demais cadastros.

## Consequências

- O teste do motor aceita `unit_id` criado já na tabela, além do acrescentado por ALTER (0402).
- Ficam para depois:
  - integração com controle de acesso físico (catraca, interfone, app do morador);
  - aviso automático ao morador quando chega uma entrega (fila de e-mail do `send`);
  - fotos do item achado.
- Próximos do passo 7: documentos (AP-122 a AP-130) e leituras (AP-041 a AP-043).
