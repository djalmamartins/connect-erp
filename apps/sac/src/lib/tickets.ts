import type { Message, Ticket, TicketPriority, TicketStatus, View } from '../api/sac';

/** Regras puras da fila do SAC no celular (testadas em __tests__/tickets.test.ts). */
export const STATUS_LABELS: Record<TicketStatus, string> = {
  open: 'Aberto',
  in_progress: 'Em atendimento',
  waiting_customer: 'Aguardando cliente',
  resolved: 'Resolvido',
  closed: 'Fechado',
};

export const PRIORITY_LABELS: Record<TicketPriority, string> = { low: 'Baixa', medium: 'Média', high: 'Alta', urgent: 'Urgente' };

export const VIEWS: { key: View; label: string; short: string }[] = [
  { key: 'mine', label: 'Meus', short: 'Meus' },
  { key: 'unassigned', label: 'Sem responsável', short: 'Sem resp.' },
  { key: 'overdue', label: 'Atrasados', short: 'Atrasados' },
  { key: 'all', label: 'Todos', short: 'Todos' },
];

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export function statusTone(status: TicketStatus): Tone {
  return ({ open: 'info', in_progress: 'warning', waiting_customer: 'neutral', resolved: 'success', closed: 'neutral' } as const)[status] ?? 'neutral';
}

export function priorityTone(priority: TicketPriority): Tone {
  return ({ low: 'neutral', medium: 'info', high: 'warning', urgent: 'danger' } as const)[priority] ?? 'neutral';
}

export function isFinal(status: TicketStatus): boolean {
  return status === 'resolved' || status === 'closed';
}

/** Ocorrência (OCO) ou chamado (SAC), pelo protocolo (ADR 0637). */
export function ticketKind(ticket: Pick<Ticket, 'protocol' | 'occurrence_id'>): 'ocorrencia' | 'chamado' {
  return ticket.occurrence_id !== null || ticket.protocol.toUpperCase().startsWith('OC') ? 'ocorrencia' : 'chamado';
}

/** Data do servidor ("AAAA-MM-DD HH:MM:SS") como Date local. */
export function parseServer(value: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value);
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5])) : null;
}

/** "hoje 14:05", "ontem 09:10" ou "25/11 17:00". */
export function when(value: string, now: Date = new Date()): string {
  const d = parseServer(value);
  if (!d) return value;
  const hm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  const day = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const diff = Math.round((day(now) - day(d)) / 86_400_000);
  if (diff === 0) return `hoje ${hm}`;
  if (diff === 1) return `ontem ${hm}`;
  if (diff === -1) return `amanhã ${hm}`;
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} ${hm}`;
}

/** Prazo em palavras: "atrasado desde ontem 17:00", "vence hoje 18:00" ou "vence 28/11 09:00". */
export function dueText(ticket: Pick<Ticket, 'due_at' | 'status' | 'late'>, now: Date = new Date()): string {
  if (isFinal(ticket.status)) return STATUS_LABELS[ticket.status];
  return ticket.late ? `Atrasado desde ${when(ticket.due_at, now)}` : `Vence ${when(ticket.due_at, now)}`;
}

/** Ordem da fila: atrasados primeiro, depois urgência e prazo. */
export function sortQueue(tickets: Ticket[]): Ticket[] {
  const rank: Record<TicketPriority, number> = { urgent: 0, high: 1, medium: 2, low: 3 };
  return [...tickets].sort((a, b) => {
    if (a.late !== b.late) return a.late ? -1 : 1;
    if (isFinal(a.status) !== isFinal(b.status)) return isFinal(a.status) ? 1 : -1;
    if (rank[a.priority] !== rank[b.priority]) return rank[a.priority] - rank[b.priority];
    return a.due_at.localeCompare(b.due_at);
  });
}

/** Quem pediu, em uma linha: nome e onde (condomínio · unidade). */
export function context(ticket: Pick<Ticket, 'requester' | 'condominium' | 'unit'>): string {
  return [ticket.requester.name, ticket.condominium?.name, ticket.unit?.label].filter((v): v is string => !!v && v.trim() !== '').join(' · ');
}

/** Rótulo do autor de cada mensagem na conversa. */
export function authorLabel(message: Message): string {
  if (message.internal) return `${message.author} · nota interna`;
  return message.from_team ? message.author : `${message.author} · quem pediu`;
}

/** Caminho do push (data.path) para uma tela do app; só aceita as telas conhecidas. */
export function pushRoute(data: unknown): string | null {
  const path = (data as { path?: unknown } | null)?.path;
  if (typeof path !== 'string') return null;
  return /^\/chamados\/\d+$/.test(path) ? path : null;
}

/** Selo do ícone: chamados meus em aberto mais os atrasados sem responsável. */
export function badgeCount(summary: { mine_open: number; unassigned: number } | null): number {
  return summary ? summary.mine_open + summary.unassigned : 0;
}

/** Por onde o chamado chegou, para o selo da fila (ADR 0661). */
export type Channel = 'whatsapp' | 'app' | 'telefone' | 'presencial' | 'site' | 'email' | 'equipe';

export const CHANNEL_LABELS: Record<Channel, string> = {
  whatsapp: 'WhatsApp',
  app: 'App',
  telefone: 'Telefone',
  presencial: 'Presencial',
  site: 'Site',
  email: 'E-mail',
  equipe: 'Equipe',
};

/** Canais que a equipe escolhe ao abrir um chamado pelo app (POST /sac/tickets). */
export const NEW_CHANNELS: Channel[] = ['telefone', 'whatsapp', 'presencial'];

/**
 * Canal do chamado: a tag "canal:..." gravada quando a equipe abre pelo app vale primeiro;
 * senão a origem (Talk = WhatsApp, portal do morador = App, site = Site, ERP e Help Desk = Equipe).
 */
export function channelOf(ticket: Pick<Ticket, 'origin' | 'tags'>): Channel {
  const tag = /(?:^|[,;\s])canal:(telefone|whatsapp|presencial)\b/i.exec(ticket.tags ?? '');
  if (tag) return tag[1].toLowerCase() as Channel;
  switch (ticket.origin) {
    case 'talk':
      return 'whatsapp';
    case 'residents':
      return 'app';
    case 'site':
      return 'site';
    case 'api':
      return 'email';
    default:
      return 'equipe';
  }
}

/** Iniciais do responsável no círculo da fila: "Ana Nunes" vira "AN", "Ana" vira "AN". */
export function initials(name: string | null | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/** Há quanto tempo: "agora", "há 4 min", "há 2 h", "ontem" ou "25/11". */
export function ago(value: string, now: Date = new Date()): string {
  const d = parseServer(value);
  if (!d) return value;
  const minutes = Math.floor((now.getTime() - d.getTime()) / 60_000);
  if (minutes < 1) return 'agora';
  if (minutes < 60) return `há ${minutes} min`;
  const day = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const days = Math.round((day(now) - day(d)) / 86_400_000);
  if (days === 0) return `há ${Math.floor(minutes / 60)} h`;
  if (days === 1) return 'ontem';
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/** Onde e quem, na linha de baixo da fila: "Cond. Solar · 302 B · Carla". */
export function placeLine(ticket: Pick<Ticket, 'requester' | 'condominium' | 'unit'>): string {
  return [ticket.condominium?.name, ticket.unit?.label, ticket.requester.name].filter((v): v is string => !!v && v.trim() !== '').join(' · ');
}

/** Campos do Novo chamado antes de enviar; devolve o primeiro problema, em palavras, ou null. */
export function newTicketProblem(input: { condominium_id: number | null; requester_name: string; requester_phone: string; requester_email: string; subject: string; message: string }): string | null {
  if (!input.condominium_id) return 'Escolha o condomínio.';
  if (input.requester_name.trim().length < 2) return 'Informe o nome de quem pediu.';
  if (input.requester_phone.replace(/\D/g, '').length < 10 && input.requester_email.trim() === '') return 'Informe o telefone (com DDD) ou o e-mail de quem pediu.';
  if (input.requester_email.trim() !== '' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(input.requester_email.trim())) return 'Confira o e-mail.';
  if (input.subject.trim().length < 4) return 'Escreva um assunto com pelo menos 4 letras.';
  if (input.message.trim().length < 10) return 'Descreva o pedido com pelo menos 10 caracteres.';
  return null;
}

/** Frase da chave do balão do site, conforme autorização e horário (ADR 0666). */
export function presenceText(p: { public: boolean; open: boolean; shown: boolean; hours: string }): { title: string; detail: string } {
  if (!p.public) return { title: 'Você não aparece no balão do site', detail: 'Ligue para o site mostrar seu primeiro nome e foto quando você estiver atendendo.' };
  if (!p.open) return { title: 'Fora do horário do balão', detail: `O balão mostra a equipe ${p.hours}.` };
  return p.shown ? { title: 'Você está online no balão do site', detail: 'Com o app aberto, você aparece para quem visita o site.' } : { title: 'Quase online no balão', detail: 'Abra a fila para aparecer no site.' };
}

/**
 * Condomínio guardado no filtro da Fila (ADR 0670). Volta para "todos" (0) quando o salvo
 * não está mais no escopo de quem entrou, ou quando a pessoa só atende um condomínio.
 */
export function queueCondominium(saved: string | null, condominiums: { id: number }[]): number {
  const id = Number(saved);
  if (!Number.isInteger(id) || id <= 0 || condominiums.length < 2) return 0;
  return condominiums.some((c) => c.id === id) ? id : 0;
}

/** Junta a página seguinte sem repetir chamado que já veio (ADR 0672). */
export function appendPage(prev: Ticket[], page: Ticket[]): Ticket[] {
  const seen = new Set(prev.map((t) => t.id));
  return [...prev, ...page.filter((t) => !seen.has(t.id))];
}

/** Situações do filtro do Buscar, na ordem do atendimento. */
export const STATUS_FILTERS: TicketStatus[] = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

/** O Buscar consulta com 2 letras ou mais, ou só com a situação escolhida (sem texto lista todos daquela situação). */
export function searchReady(search: string, status: TicketStatus | ''): boolean {
  return search.trim().length >= 2 || status !== '';
}
