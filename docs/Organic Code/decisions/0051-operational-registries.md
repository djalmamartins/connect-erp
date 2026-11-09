# 0051 — Operacional: manutenções, ocorrências, reservas, mudanças, seguros e contratos

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O resto do Operacional no catálogo (AP-110 a AP-121) ainda era demonstração. A coordenação orientou:
- as reservas ficam sobre as áreas comuns que já existem (AP-008);
- as ocorrências usam a fila do Help Desk (ADR 0034) onde fizer sentido;
- contratos e seguros ficam ligados ao fornecedor, com anexo pelo Disco Virtual (ADR 0047).

As fichas ainda são scaffold; os campos seguem `page-specs.php` e são PROPOSTOS.

## Decisão

- **Oito cadastros no motor único** (ADR 0014), grupo Operacional, por condomínio, com exclusão
  lógica, CSRF nas telas, auditoria e API REST gerada (migration 0530):
  - **Manutenções (AP-110):** serviço, fornecedor, data prevista, periodicidade, conclusão e anexo;
    situação: agendada, em execução, concluída ou cancelada.
  - **Ocorrências (AP-112):** unidade, assunto, prioridade, data, descrição e anexo; situação: aberta,
    em andamento ou resolvida.
  - **Reservas de áreas comuns (AP-113):** área comum (AP-008), unidade, morador, data, horário e
    taxa; situação: aguardando aprovação, confirmada ou cancelada.
  - **Lista de espera (AP-115):** área, unidade e data desejada; a posição é a ordem de inscrição.
  - **Seguros (AP-118):** seguradora (e fornecedor, se cadastrado), apólice única no condomínio,
    vigência, prêmio, cobertura e a apólice em PDF.
  - **Contratos (AP-119):** fornecedor obrigatório, objeto, vigência, valor mensal, reajuste e o
    contrato assinado; situação: vigente, encerrado ou cancelado.
  - **Agendamentos de mudança (AP-120)** e as **configurações** deles (AP-121, uma por condomínio).
- **Agendamentos Pendentes (AP-114)** são as reservas aguardando aprovação (filtro
  `?situacao=active`), sem tabela própria.
- **Regras novas no motor:**
  - **`no_overlap`:** dois registros válidos do mesmo recurso não se sobrepõem no horário. Sem horário,
    o registro ocupa o dia; encostar no fim do outro não conflita; cancelado libera o horário. Vale
    para reservas (área + dia + horário) e mudanças (condomínio + dia + período).
  - **`reservation`:** aplica as regras da área comum:
    - área bloqueada ou não reservável recusa;
    - antecedência mínima e máxima (ao criar);
    - horário dentro do funcionamento;
    - taxa vazia herda a da área;
    - área de reserva livre já nasce confirmada; com aprovação, nasce aguardando.
- **Ocorrência → Help Desk:**
  - `POST /api/v1/occurrences/{id}/ticket` abre o chamado pelo `TicketService` (origem `erp`), com
    condomínio, unidade, assunto, descrição e prioridade (baixa, média e alta viram low, medium e
    high);
  - grava o `ticket_id` (só leitura no cadastro) e passa a ocorrência para "em andamento";
  - uma ocorrência abre no máximo um chamado (chave única e trava da linha).
- **Vínculos:**
  - área comum, unidade e fornecedor apontados por registros ativos não podem ser excluídos;
  - anexos pelo Disco Virtual do mesmo condomínio (chave `attachment`, ADR 0050).
- **Permissões** `erp.maintenances`, `erp.occurrences`, `erp.reservations` (reservas e lista de
  espera), `erp.insurances`, `erp.contracts` e `erp.moves` (mudanças e configurações), cada uma com
  `view` e `manage`.

## Consequências

- Ficam para depois:
  - "Atrasada" (manutenção), "A renovar" e "Vencido" (seguro e contrato), que são situações calculadas
    pela data e entram nos dashboards;
  - antecedência e taxa das mudanças pela configuração;
  - chamar o primeiro da lista de espera quando uma reserva é cancelada;
  - geração das próximas manutenções pela periodicidade.
- A frente de layout ganha as telas pelo motor. O botão "Abrir chamado" na ocorrência usa a API acima.
- Próximo do passo 7: os dashboards, que ficaram para o fim.
