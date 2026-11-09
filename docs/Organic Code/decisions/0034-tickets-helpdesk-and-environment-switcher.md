# 0034 — Todo chamado na fila do Help Desk, e alternador ERP / Studio / Help Desk

Data: 2026-11-09 · Status: aceito (pedido do Djalma em 09/11/2026, com autonomia delegada)

## Contexto

Pedido do proprietário: "toda vez que tivesse um chamado, cair no Help Desk, com todas as
informações", e ligar o cabeçalho "ERP / Connect ERP" da sidebar ao Studio e ao Help Desk.
Por enquanto todo usuário pode trocar de ambiente; a permissão por usuário vem depois.

Antes desta decisão havia duas portas de entrada que gravavam direto em `studio_support_tickets`
(formulário interno do Help Desk e widget do site), cada uma com a própria regra, sem condomínio
nem unidade, e o widget não avisava a equipe. O ERP não abria chamados. O cabeçalho da sidebar do
ERP era fixo, e o alternador do Studio filtrava por permissão e apontava para `/erp` no próprio
host. Com hosts próprios (ADR 0026), `erp.<domínio>` e `studio.<domínio>` não compartilham o
cookie de sessão.

## Decisão

### Chamados

- **Serviço único `Source\Services\Support\TicketService::open()`** para todas as origens:
  `helpdesk`, `erp`, `site`, `residents` (portal dos moradores, quando existir) e `api`.
  Valida assunto e descrição, área, prioridade, condomínio no escopo de quem abre
  (`CondominiumScope`), unidade pertencente ao condomínio, e solicitante (usuário do sistema ou
  nome + e-mail, com telefone opcional). Calcula o SLA pela prioridade, grava o evento `origin`,
  audita e avisa a equipe (níveis 5+), o responsável e o solicitante com conta, com o link para a
  fila no host do Help Desk.
- **Migration `20261109_0601`**: `origin`, `condominium_id` e `unit_id` (FKs com `SET NULL`) e
  `requester_phone` em `studio_support_tickets`. Chamados antigos ficam com origem `helpdesk` e
  sem condomínio.
- **Help Desk**: filtro por condomínio e por origem, contexto (condomínio, unidade, origem) na
  fila e nos detalhes do chamado, e condomínio e unidade no "Novo chamado".
- **ERP**: página própria CN-004 "Chamados" em `/operacional/chamados` (Operacional › Rotina),
  com abertura (condomínio pré-selecionado pelo contexto da barra, unidade, "eu mesmo" ou um
  morador) e lista dos chamados dos condomínios do escopo e dos que o usuário abriu. Exige
  `erp.access`; o atendimento continua no Help Desk.
- **API REST**: `GET/POST /api/v1/tickets` e `GET /api/v1/tickets/{id}` (OpenAPI, tag Tickets),
  mesma regra da tela.
- O widget do site passa a usar o serviço (origem `site`) e, com isso, também avisa a equipe.

### Alternador de ambientes

- **`Source\Services\Environments`** é o catálogo único (Studio, ERP, Help Desk, Moradores quando
  o tema existir, Site), usado no cabeçalho da sidebar do ERP e do Studio/Help Desk.
- **Permissão: hoje todo usuário logado vê e usa todos os ambientes.** O gancho é
  `Environments::canAccess()`, o único ponto de decisão; cada ambiente já tem o slug que a regra
  por usuário vai exigir (`studio.access`, `erp.access`, `support.manage`, `app.access`,
  `site.access`). Dentro de cada ambiente a rota continua validando a própria permissão.
- **Entre hosts**, o link vai para `/ambiente/{ambiente}` no host de origem. Com sessão completa
  (senha e 2FA já concluídos), ele emite um código aleatório de 256 bits, de uso único, válido por
  60 s e só para aquele ambiente (migration `20261109_0602`, tabela `auth_environment_handoffs`,
  só o hash SHA-256). O host de destino troca o código pela sessão em
  `/ambiente/entrar/{ambiente}/{código}`, regenerando o ID de sessão, com log e auditoria, e
  responde com `Referrer-Policy: no-referrer`. No mesmo host o link é direto.

## Alternativas descartadas

- Cookie de sessão no domínio pai (`.connect.lab`): mudaria o login de todos os hosts de uma vez,
  com risco de sessões duplicadas, e quebraria o isolamento por hostname.
- Registrar `/studio` e `/helpdesk` também no host do ERP: quebra o isolamento por hostname
  (ADR 0026).

## Consequências e riscos

- Sair em um host não encerra a sessão do outro (cada host tem a sua). PENDENTE: logout global.
- O código vai na URL; o uso único, os 60 s e o `no-referrer` limitam a exposição em logs.
- PENDENTE (proprietário): permissão por usuário para cada ambiente; se o ERP deve ver respostas
  do Help Desk; o portal dos moradores ainda não existe, e quando existir abrirá chamados com
  origem `residents` e condomínio e unidade definidos pelo servidor.
