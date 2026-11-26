/**
 * Regras puras do app Meu Dia (sem rede e sem React), testadas em __tests__/day.test.ts.
 *
 * - O que ficou para trás: a API manda a lista "overdue" (ADR 0658). Enquanto o servidor ainda não tiver essa lista,
 *   o app monta a mesma coisa com o que já vem no dia (chamados e vistorias vencidos, compromissos de dias passados
 *   ainda marcados), para nunca esconder uma pendência.
 * - Lembretes no aparelho: 15 minutos antes de cada compromisso marcado e um resumo às 7h com o que há para fazer e
 *   quantas tarefas estão atrasadas. Os lembretes são locais (funcionam sem internet e no Expo Go); o push do
 *   servidor avisa quando alguém marca uma tarefa para você.
 */
import type { Day, DayEvent, OverdueItem, OverdueKind } from '../api/team';

export const REMINDER_MINUTES = 15;
export const MORNING_HOUR = 7;
/** Quantos dias à frente o app agenda lembretes (a semana que a API manda). */
export const REMINDER_DAYS = 7;

const WEEKDAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
const WEEKDAYS_LONG = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/** "2026-11-27" → Date local à meia-noite. */
export function parseDay(value: string): Date {
  const [y, m, d] = value.slice(0, 10).split('-').map(Number);
  return new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1);
}

/** Date → "AAAA-MM-DD" no fuso do aparelho. */
export function dayKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function addDays(value: string, days: number): string {
  const d = parseDay(value);
  d.setDate(d.getDate() + days);
  return dayKey(d);
}

/** Dias entre duas datas AAAA-MM-DD (b - a). */
export function diffDays(a: string, b: string): number {
  return Math.round((parseDay(b).getTime() - parseDay(a).getTime()) / 86_400_000);
}

/** "2026-11-27" → "sex, 27/11". */
export function shortDay(value: string): string {
  const d = parseDay(value);
  return `${WEEKDAYS[d.getDay()]}, ${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/** "2026-11-27" → "Sexta-feira, 27 de novembro". */
export function longDay(value: string): string {
  const d = parseDay(value);
  return `${WEEKDAYS_LONG[d.getDay()]}, ${d.getDate()} de ${MONTHS[d.getMonth()]}`;
}

/** "Hoje", "Amanhã", "Ontem" ou a data curta. */
export function relativeDay(value: string, today: string): string {
  const diff = diffDays(today, value);
  if (diff === 0) return 'Hoje';
  if (diff === 1) return 'Amanhã';
  if (diff === -1) return 'Ontem';
  return shortDay(value);
}

export function lateLabel(daysLate: number): string {
  return daysLate <= 1 ? 'Atrasada desde ontem' : `Atrasada há ${daysLate} dias`;
}

export const KIND_LABEL: Record<OverdueKind, string> = {
  event: 'Tarefa',
  ticket: 'Chamado do SAC',
  visit: 'Vistoria',
  deadline: 'Vencimento',
};

/**
 * Tudo o que ficou para trás, do mais antigo para o mais novo. Usa a lista do servidor quando ela existe.
 */
export function overdueItems(day: Day): OverdueItem[] {
  if (Array.isArray(day.overdue)) {
    return [...day.overdue].sort((a, b) => a.due_on.localeCompare(b.due_on) || a.id - b.id);
  }
  const today = day.today;
  const items: OverdueItem[] = [];
  const seen = new Set<string>();
  const push = (item: OverdueItem) => {
    const key = `${item.kind}:${item.id}`;
    if (!seen.has(key) && item.days_late >= 1) {
      seen.add(key);
      items.push(item);
    }
  };
  for (const e of day.week_events ?? []) {
    const on = e.starts_at.slice(0, 10);
    if (e.status === 'scheduled' && on < today) {
      push({ kind: 'event', id: e.id, title: e.title, due_on: on, days_late: diffDays(on, today), condominium: e.condominium, ticket_id: e.ticket_id, protocol: e.protocol, status: e.status });
    }
  }
  for (const t of [...(day.to_schedule ?? []), ...(day.waiting ?? [])]) {
    if (t.overdue) {
      push({ kind: 'ticket', id: t.id, title: t.subject, due_on: t.due_on, days_late: diffDays(t.due_on, today), condominium: t.condominium ?? null, ticket_id: t.id, protocol: t.protocol, status: t.status });
    }
  }
  for (const v of day.visits ?? []) {
    if (v.overdue) {
      push({ kind: 'visit', id: v.plan_id, title: `${v.template} · ${v.condominium}`, due_on: v.due_on, days_late: diffDays(v.due_on, today), condominium_id: v.condominium_id, condominium: v.condominium });
    }
  }
  return items.sort((a, b) => a.due_on.localeCompare(b.due_on) || a.id - b.id);
}

/** Um compromisso é "atrasado" se o dia passou e ainda está marcado. */
export function isLate(event: DayEvent, today: string): boolean {
  return event.overdue ?? (event.status === 'scheduled' && event.starts_at.slice(0, 10) < today);
}

/** Dia na faixa fica vermelho quando tem pendência daquele dia que ficou para trás. */
export function stripTone(day: { date: string; count: number; overdue?: number }, today: string, late: OverdueItem[]): 'late' | 'busy' | 'free' {
  const lateHere = day.overdue ?? late.filter((i) => i.due_on === day.date).length;
  if (day.date < today && lateHere > 0) return 'late';
  return day.count > 0 ? 'busy' : 'free';
}

export type Reminder = { id: string; at: Date; title: string; body: string; data: Record<string, unknown> };

/** Data e hora local do início de um compromisso ("2026-11-27 09:00:00"). */
export function startOf(event: DayEvent): Date {
  const [d, t] = event.starts_at.split(' ');
  const base = parseDay(d ?? '');
  const [h, mi] = (t ?? '00:00').split(':').map(Number);
  base.setHours(h ?? 0, mi ?? 0, 0, 0);
  return base;
}

/**
 * Lembretes de 15 minutos antes, só de compromissos marcados que ainda vão acontecer, sem repetir.
 */
export function eventReminders(events: DayEvent[], now: Date, minutes = REMINDER_MINUTES): Reminder[] {
  const seen = new Set<number>();
  const out: Reminder[] = [];
  for (const e of events) {
    if (e.status !== 'scheduled' || seen.has(e.id)) continue;
    seen.add(e.id);
    const start = startOf(e);
    const at = new Date(start.getTime() - minutes * 60_000);
    if (start.getTime() <= now.getTime()) continue;
    const when = at.getTime() > now.getTime() ? at : new Date(now.getTime() + 5_000);
    const place = e.condominium ? ` · ${e.condominium}` : '';
    out.push({
      id: `event-${e.id}`,
      at: when,
      title: `${e.type_label} às ${e.start}`,
      body: `${e.title}${place}`,
      data: { app: 'meu-dia', kind: 'event', id: e.id, date: e.starts_at.slice(0, 10) },
    });
  }
  return out.sort((a, b) => a.at.getTime() - b.at.getTime());
}

/** Próximo horário do resumo da manhã (hoje às 7h se ainda não passou; senão amanhã). */
export function nextMorning(now: Date, hour = MORNING_HOUR): Date {
  const at = new Date(now);
  at.setHours(hour, 0, 0, 0);
  if (at.getTime() <= now.getTime()) at.setDate(at.getDate() + 1);
  return at;
}

function plural(n: number, one: string, many: string): string {
  return `${n} ${n === 1 ? one : many}`;
}

/**
 * Texto do resumo da manhã para o dia que começa em `on`. Conta os compromissos daquele dia, o que há para agendar e
 * as tarefas atrasadas (as de hoje que não fecharem viram atrasadas amanhã).
 */
export function morningSummary(day: Day, on: string): { title: string; body: string } {
  const events = (day.week_events ?? []).filter((e) => e.starts_at.slice(0, 10) === on && e.status === 'scheduled');
  const late = overdueItems(day).length + (on > day.today ? (day.week_events ?? []).filter((e) => e.status === 'scheduled' && e.starts_at.slice(0, 10) === day.today).length : 0);
  const parts: string[] = [];
  parts.push(events.length ? `${plural(events.length, 'compromisso', 'compromissos')} (o primeiro às ${events[0]!.start})` : 'Nenhum compromisso marcado');
  if (day.summary.to_schedule) parts.push(plural(day.summary.to_schedule, 'chamado para agendar', 'chamados para agendar'));
  const title = late > 0 ? `Bom dia! ${plural(late, 'tarefa atrasada', 'tarefas atrasadas')}` : 'Bom dia! Seu dia no Connect';
  return { title, body: parts.join(' · ') + '.' };
}

/** Número no ícone do app: o que está atrasado. */
export function badgeCount(day: Day): number {
  return Array.isArray(day.overdue) ? day.overdue.length : overdueItems(day).length;
}
