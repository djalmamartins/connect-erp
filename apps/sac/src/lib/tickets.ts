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

export const VIEWS: { key: View; label: string }[] = [
  { key: 'mine', label: 'Meus' },
  { key: 'unassigned', label: 'Sem responsável' },
  { key: 'overdue', label: 'Atrasados' },
  { key: 'all', label: 'Todos' },
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
