# 0661 — App do SAC em cinza-claro e dourado, ícone A e Novo chamado pelo app

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026: "Ícones A" e "E o resto está ok" sobre a prévia dos apps (seção 5: Fila,
  Conversa e Novo chamado do SAC em cinza-claro e dourado). Continua a ADR 0660.

## Decisão

- **Ícone A** (só a marca Connect, na cor de cada app): SAC com o pino dourado `#a9871f` sobre cinza-claro
  `#eceeed`. Arquivos em `apps/sac/assets` (ícone, Android adaptativo, monocromático, notificação e favicon).
- **Cores** (`apps/sac/src/theme.ts`): fundo `#f1f2f1`, cartões brancos com borda `#e3e5e3`, dourado `#a9871f` nos
  destaques (protocolo, números das abas, aba ativa da barra), dourado-claro `#f6efd9` nas respostas da equipe,
  botão principal verde-escuro do ERP `#0d2e27`. Verde só no WhatsApp.
- **Abas**: Fila, Novo, Buscar e Perfil. A busca saiu da Fila para a aba Buscar (todos os chamados, por assunto
  ou protocolo).
- **Fila**: abas Meus, Sem responsável, Atrasados e Todos em controle segmentado com o número em dourado;
  cada linha mostra protocolo, há quanto tempo, assunto, o selo do canal, condomínio · unidade · quem pediu e as
  iniciais de quem atende. O canal vem da tag `canal:telefone|whatsapp|presencial` gravada pelo app e, sem ela,
  da origem (Talk = WhatsApp, portal do morador = App, site = Site, ERP e Help Desk = Equipe).
- **Conversa**: resposta da equipe em dourado-claro à direita, nota interna no centro tracejada em amarelo,
  linha "Aberto pelo …" no começo.
- **Novo chamado** (`POST /api/v1/sac/tickets`, só `support.manage`): condomínio do escopo, unidade opcional
  (`GET /api/v1/sac/units?condominium_id=`), como chegou (telefone, WhatsApp ou presencial), quem pediu, telefone
  ou e-mail, assunto, descrição, prioridade e "Fica comigo" (responsável = quem abriu, ligado por padrão).
  Origem `helpdesk`; protocolo SAC (ADR 0637); mesmo push de chamado novo para a equipe (ADR 0660).
  `/sac/options` passa a trazer `condominiums` e `channels`.
- **Telefone sem e-mail**: `TicketService::open` aceita nome + telefone quando a origem é `helpdesk` e há quem
  registra (a equipe), como já aceitava no Talk. Sem e-mail, quem pediu não recebe o aviso por e-mail; a equipe
  dá o protocolo por telefone ou WhatsApp.

## Fora desta etapa

- O interruptor "Você está online no balão do site" da prévia não entrou: o balão do SAC no site ainda não tem
  presença da equipe no servidor. Fica para uma decisão própria.

## Testes

- `SacDeskIntegrationTest::testOpenByPhoneWithChannelUnitsAndScope` (abrir só com telefone, canal na tag,
  unidade, escopo de condomínio, canal inválido, sem contato, quem só acompanha = 403).
- `apps/sac/__tests__/tickets.test.ts`: canal, iniciais, há quanto tempo, linha de onde e quem, conferência
  do Novo chamado.
