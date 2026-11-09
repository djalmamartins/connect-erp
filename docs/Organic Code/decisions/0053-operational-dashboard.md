# 0053 — Dashboard com alertas reais, chamada da lista de espera e próxima manutenção

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O dashboard da administradora (AP-001) ainda mostra demonstração, além da contagem dos cadastros
(ADR 0030). O ADR 0051 deixou para depois:
- as situações calculadas pela data (manutenção atrasada, seguro ou contrato a renovar ou vencido);
- chamar a lista de espera quando uma reserva é cancelada;
- gerar as próximas manutenções pela periodicidade.

A coordenação pediu tudo isso junto, com a lista de espera chamada por ação explícita ou aviso,
nunca reservando sozinha. A frente de layout cuida da tela e mexeu no `Panel.php`; por isso esta
entrega é só serviço e API.

## Decisão

- **`GET /api/v1/dashboard`** (`OperationalDashboard::summary`), no contexto da barra superior
  (um condomínio ou todos do escopo; fora do escopo = 404). Calculado a cada pedido, sem tabela.
  - **cards:**
    - manutenções atrasadas (agendada com data prevista antes de hoje) e da semana;
    - seguros e contratos a renovar (vencem em até 30 dias) e vencidos;
    - reservas aguardando aprovação, ocorrências abertas e entregas aguardando retirada;
    - contas a receber vencidas, a receber e a pagar nos próximos 7 dias;
    - reservas canceladas com lista de espera para chamar.
  - **alerts:** só os de contagem maior que zero, do mais grave (danger) para o informativo, cada um
    com a rota da tela.
- **Situações pela data** (regras puras, para as listas e telas):
  - `validity()`: vigente, a renovar ou vencido; contrato sem fim fica vigente;
  - `isLate()`: atrasada só se agendada; em execução e concluída não atrasam.
- **Lista de espera** (`POST /api/v1/reservations/{id}/call-waitlist`, `erp.reservations.manage`):
  - só para reserva cancelada e com a data ainda livre;
  - chama o primeiro da fila (ordem de inscrição) ainda não chamado;
  - grava quem chamou, quando e para qual reserva (migration 0531) e audita;
  - **não cria reserva**: quem foi chamado reserva pelas regras da área (ADR 0051);
  - o dashboard avisa quando há reserva cancelada com gente aguardando.
- **Próxima manutenção** (`POST /api/v1/maintenances/{id}/next`, `erp.maintenances.manage`):
  - só de manutenção concluída e com periodicidade (mensal, trimestral, semestral ou anual);
  - mantém o dia, limitado ao fim do mês;
  - a nova nasce agendada, com o mesmo serviço e fornecedor;
  - `previous_id` único: gera uma vez só (409 na segunda).

## Consequências

- A frente de layout troca a demonstração do AP-001 pelos `cards` e `alerts` e coloca os botões
  "Chamar lista de espera" e "Gerar próxima" nas telas de reserva e manutenção.
- Avisar o morador chamado (e-mail ou app) fica para quando houver a fila de envio do `send`
  integrada ao ERP.
- Os painéis do condomínio (AP-002) e da unidade (AP-003) podem reaproveitar o mesmo serviço com o
  filtro de condomínio, e o da unidade com a inadimplência e os documentos dela.
