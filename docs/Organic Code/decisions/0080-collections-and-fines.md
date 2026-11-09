# 0080 — Cobrança e multas: multas pendentes, atendimento e acompanhamento judicial

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

O catálogo tem Multas Pendentes (AP-116), Atendimento Cobrança (AP-117) e Cobrança Judicial (AP-039).
As fichas são esqueletos. A multa avulsa já existe (ADR 0057), e a inadimplência por unidade, com
encargos sugeridos, também (ADR 0039 e 0040). A coordenação fixou regras conservadoras: a cobrança
judicial só marca e acompanha. Ela não envia nada a advogado nem a tribunal e não cria encargo novo.

## Decisão

- **AP-116 Multas pendentes** (`GET /api/v1/pending-fines`):
  - lista as multas avulsas ativas ainda não pagas, ou também as pagas com `include_paid`;
  - cada uma vem com a situação (em aberto, vencida ou paga) e a origem;
  - a multa pode citar a ocorrência (Operacional, ADR 0051: do condomínio, da unidade ou sem unidade) ou a reserva
    de área comum da unidade que a motivou (`occurrence_id` e `reservation_id`, migration
    20261108_0538), conferidas ao lançar.
- **AP-117 Atendimento de cobrança:**
  - **fila** (`GET /api/v1/collection-desk`): as unidades em atraso (pela inadimplência), com o último
    contato, a próxima ação, a promessa quebrada e o caso judicial aberto. A ordem é: promessa
    quebrada, próxima ação vencida, sem contato, maior atraso;
  - **contato** (`POST /api/v1/collection-contacts`): canal, resultado e nota obrigatórios. A promessa
    de pagamento exige a data (até 90 dias) e pode trazer o valor; a próxima ação vai até 180 dias.
    Guarda o valor vencido em aberto no momento. É histórico: não se edita nem se apaga;
  - **promessa quebrada** (`Collections::promiseBroken`): o último contato prometeu, a data passou e a
    unidade segue em atraso.
- **AP-039 Cobrança judicial**, só acompanhamento (`/api/v1/legal-cases`):
  - abrir exige contas a receber vencidas, em aberto e fora de acordo da unidade, e uma nota. O caso
    guarda as contas e o valor de cada uma na abertura (`claimed_cents`, sem encargos);
  - o advogado é opcional (cadastro AP-016), assim como o número do processo e a vara;
  - **um caso aberto por unidade** (chave única com coluna gerada);
  - **andamento** (`POST /legal-cases/{id}/events`): nota com data, e mudança de situação pelas
    transições permitidas. Em preparação vai para ajuizado, suspenso, acordo judicial ou encerrado;
    ajuizado e suspenso vão entre si e para acordo ou encerrado; acordo judicial volta a ajuizado ou
    encerra. **Encerrado é final** e guarda o motivo;
  - o detalhe mostra o valor ainda em aberto das contas do caso (as pagas depois saem da soma);
  - **nada é enviado a advogado ou tribunal, nenhum encargo ou lançamento é criado e nenhuma conta é
    travada.** A negociação segue pelos acordos (ADR 0039).
- **Permissões:** `erp.collections.view` e `erp.collections.manage`. A fila e a abertura do caso
  exigem também `erp.agreements.view`. Escopo por condomínio; tudo auditado.

## Fora deste ADR

- Honorários do advogado e custas processuais: nenhum valor é calculado nem lançado.
- Integração com tribunal (consulta de processo) e envio de documentos ao advogado.
- Lembrete automático da próxima ação: a fila mostra o que venceu; o aviso pode virar evento da outbox
  depois.

## Consequências

- Telas (layout): AP-116, AP-117 e AP-039 usam estas rotas; a multa avulsa (AP-027) ganha a escolha
  da ocorrência ou da reserva.
