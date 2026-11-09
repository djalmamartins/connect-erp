# 0070 — Telas do Operacional e dashboard com dados reais

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ciclo 19 (ADR 0051) pôs no motor único os cadastros do Operacional (AP-110 a AP-121), e o ciclo 20
(ADR 0053) trouxe o dashboard com alertas reais, a chamada da lista de espera e a próxima manutenção.
As listas e os formulários desses cadastros já saem do motor. Faltavam as telas que não são cadastro
puro:
- Agendamentos Pendentes (AP-114), que ainda era demonstração;
- o botão "Abrir chamado" na ocorrência (POST /api/v1/occurrences/{id}/ticket);
- os botões "Chamar lista de espera" e "Gerar próxima";
- os cards e alertas reais do dashboard (AP-001).

Os números 0054 a 0069 estão reservados para a thread Avançar. Por isso este ADR é o 0070.

## Decisão

- **Agendamentos Pendentes (AP-114):**
  - tela própria (`pages/operational/pending-reservations.php`) com as reservas aguardando
    aprovação no condomínio da barra superior;
  - os dados vêm de `Panel::pendingReservationData`, pela listagem do motor, com o mesmo escopo e a
    mesma paginação;
  - cada linha mostra a data, o dia da semana, o horário ou "Dia inteiro", a área comum, a unidade,
    o morador e a taxa;
  - **Confirmar** e **Recusar** são `POST /operacional/agendamentos-pendentes/{id}/confirmar|recusar`
    (`PendingReservations`), com CSRF e padrão PRG, e exigem `erp.reservations.manage`;
  - a troca de situação regrava o registro pelo `RegistryService::save`. Assim as regras da área,
    a sobreposição de horário e a auditoria continuam valendo;
  - `PendingReservations::formInput` devolve o registro no formato do formulário (valor em reais,
    vazio para nulo), para a taxa não ser multiplicada;
  - recusar pede confirmação, e uma reserva que já foi decidida não é alterada de novo;
  - o item do menu exige `erp.reservations.view`.
- **Ocorrência (AP-112):**
  - o formulário de uma ocorrência gravada ganha o card "Chamado no Help Desk";
  - sem chamado e com `erp.occurrences.manage`, o botão **Abrir chamado** chama a API. O card passa
    a mostrar o protocolo e o link, e a situação no formulário vira "Em andamento";
  - com chamado, o card mostra o número e o link para a fila do Help Desk (`Environments::url`,
    no host do Studio quando há hosts próprios).
- **Continuações do ADR 0053** (`Registry::recordAction`, card `components/cards/record-action.php`),
  só com a permissão manage:
  - na reserva cancelada, **Chamar lista de espera** chama o primeiro da fila e mostra o nome dele.
    Não cria reserva;
  - na manutenção concluída e recorrente, **Gerar próxima** cria a próxima e leva até ela;
  - o servidor confere de novo a situação, se a data está livre e se a próxima já foi gerada.
- **Dashboard (AP-001):**
  - os 4 indicadores e os alertas vêm de `OperationalDashboard::summary`, o mesmo serviço de
    `GET /api/v1/dashboard`, no contexto da barra superior;
  - os indicadores são: a receber vencido (com o que vence em 7 dias), a pagar em 7 dias,
    manutenções atrasadas e ocorrências abertas (com as reservas aguardando). Cada um leva à tela;
  - os alertas vêm ordenados por gravidade, com a contagem e o link;
  - sem banco, a tela segue com a demonstração. Os gráficos, a tabela de condomínios e as atividades
    continuam de demonstração até terem dados reais.
- **Cabeçalho dos cadastros:** o sobretítulo passa a usar o grupo do catálogo (ex.: "Operacional ·
  Ocorrências"), e não mais "Cadastros" para tudo.

## Consequências

- Não foi testado com MariaDB real. Os testes cobrem as regras puras, a renderização e o navegador
  com a API simulada.
- O morador chamado da lista de espera ainda não é avisado por e-mail ou app (ADR 0053).
- O link para o Help Desk entre hosts diferentes pede login no Studio quando não há sessão lá.
- Próximo: os painéis do condomínio (AP-002) e da unidade (AP-003), pelos endpoints do ciclo 21.
