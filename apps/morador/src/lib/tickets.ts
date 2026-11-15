import { TICKETS_PER_PAGE, type Ticket, type TicketKind } from '../api/resident';

/** Tipo do registro: o servidor manda kind; nos antigos, ocorrência é o que tem occurrence_id. */
export function ticketKind(t: Pick<Ticket, 'kind' | 'occurrence_id'>): TicketKind {
  return t.kind ?? (t.occurrence_id ? 'ocorrencia' : 'chamado');
}

/** Junta as páginas da lista de chamados sem repetir (o mais recente vem primeiro do servidor). */
export function mergeTickets(current: Ticket[], page: Ticket[]): Ticket[] {
  const seen = new Set(current.map((t) => t.id));
  return [...current, ...page.filter((t) => !seen.has(t.id))];
}

/** Página cheia = pode haver mais. */
export function hasMore(page: Ticket[]): boolean {
  return page.length >= TICKETS_PER_PAGE;
}

/** Chamado fechado não aceita resposta (o servidor devolve 409 ticket_closed). */
export function canReply(status: string): boolean {
  return status !== 'closed';
}

/** "hoje, 14:05", "ontem, 09:10" ou "12/11, 08:00" a partir de AAAA-MM-DD HH:MM:SS. */
export function when(value: string | null | undefined, now: Date = new Date()): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value ?? '');
  if (!m) return '';
  const [, y, mo, d, h, mi] = m;
  const day = new Date(Number(y), Number(mo) - 1, Number(d));
  const base = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const diff = Math.round((base.getTime() - day.getTime()) / 86400000);
  const label = diff === 0 ? 'hoje' : diff === 1 ? 'ontem' : Number(y) === now.getFullYear() ? `${d}/${mo}` : `${d}/${mo}/${y}`;
  return `${label}, ${h}:${mi}`;
}
