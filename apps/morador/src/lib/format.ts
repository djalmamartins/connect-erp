/** Formatação em pt-BR. A API manda valores em centavos e datas AAAA-MM-DD. */

export function money(cents: number | null | undefined): string {
  if (cents === null || cents === undefined || !Number.isFinite(cents)) {
    return '—';
  }
  const negative = cents < 0;
  const abs = Math.round(Math.abs(cents));
  const reais = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const centavos = (abs % 100).toString().padStart(2, '0');
  return `${negative ? '-' : ''}R$ ${reais},${centavos}`;
}

/** "2026-11-05" → "05/11/2026". Também aceita "2026-11-05 14:30:00" (mostra a hora). */
export function date(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(value);
  if (!m) {
    return value;
  }
  const day = `${m[3]}/${m[2]}/${m[1]}`;
  return m[4] ? `${day} às ${m[4]}:${m[5]}` : day;
}

/** Data de hoje em AAAA-MM-DD no fuso do aparelho. */
export function today(now: Date = new Date()): string {
  const y = now.getFullYear();
  const mo = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${mo}-${d}`;
}

/** Converte "25/12/2026" ou "2026-12-25" em AAAA-MM-DD; null se inválida. */
export function parseDate(input: string): string | null {
  const text = input.trim();
  let y: number;
  let mo: number;
  let d: number;
  let m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(text);
  if (m) {
    [d, mo, y] = [Number(m[1]), Number(m[2]), Number(m[3])];
  } else if ((m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text))) {
    [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  } else {
    return null;
  }
  const dt = new Date(Date.UTC(y, mo - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) {
    return null;
  }
  return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

/** "14:30" → "14:30"; null se inválida. */
export function parseTime(input: string): string | null {
  const m = /^(\d{1,2}):(\d{2})$/.exec(input.trim());
  if (!m) {
    return null;
  }
  const h = Number(m[1]);
  const mi = Number(m[2]);
  if (h > 23 || mi > 59) {
    return null;
  }
  return `${String(h).padStart(2, '0')}:${m[2]}`;
}

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export type StatusKind = 'situation' | 'reservation' | 'occurrence' | 'delivery' | 'visit' | 'assembly';

/** Rótulos e cores das situações, iguais aos cadastros do ERP (Registry\Definitions) e ao extrato (UnitStatement). */
const STATUS: Record<StatusKind, Record<string, [string, Tone]>> = {
  situation: { open: ['Em aberto', 'warning'], overdue: ['Vencido', 'danger'], paid: ['Pago', 'success'], renegotiated: ['Renegociado', 'neutral'] },
  reservation: { active: ['Aguardando aprovação', 'warning'], confirmed: ['Confirmada', 'success'], inactive: ['Cancelada', 'neutral'] },
  occurrence: { active: ['Aberta', 'warning'], running: ['Em andamento', 'info'], done: ['Resolvida', 'success'] },
  delivery: { active: ['Aguardando retirada', 'warning'], picked_up: ['Retirada', 'success'], inactive: ['Cancelada', 'neutral'] },
  visit: { active: ['Prevista', 'info'], done: ['Realizada', 'success'], inactive: ['Cancelada', 'neutral'] },
  assembly: { convened: ['Convocada', 'info'], open: ['Aberta', 'warning'], closed: ['Encerrada', 'neutral'] },
};

export function statusLabel(kind: StatusKind, status: string | null | undefined): string {
  if (!status) {
    return '—';
  }
  return STATUS[kind][status]?.[0] ?? status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
}

export function statusTone(kind: StatusKind, status: string | null | undefined): Tone {
  return (status && STATUS[kind][status]?.[1]) || 'info';
}

const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/** "2026-10" → "Outubro de 2026"; "2026" → "Ano de 2026". */
export function periodLabel(period: string, kind?: string): string {
  const m = /^(\d{4})-(\d{2})$/.exec(period);
  if (m && kind !== 'annual') {
    const name = MONTHS[Number(m[2]) - 1] ?? m[2];
    return `${name.charAt(0).toUpperCase()}${name.slice(1)} de ${m[1]}`;
  }
  return `Ano de ${period.slice(0, 4)}`;
}

const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

/** Data do dia por extenso, para o topo do Início: "sábado, 14 de novembro". */
export function longToday(now: Date = new Date()): string {
  return `${WEEKDAYS[now.getDay()]}, ${now.getDate()} de ${MONTHS[now.getMonth()]}`;
}

/** Saudação pela hora do aparelho. */
export function greeting(now: Date = new Date()): string {
  const h = now.getHours();
  return h < 12 ? 'Bom dia' : h < 18 ? 'Boa tarde' : 'Boa noite';
}

/** Iniciais para o avatar: primeira letra do primeiro e do último nome. */
export function initials(name: string | null | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) {
    return '?';
  }
  const first = parts[0]!.charAt(0);
  const last = parts.length > 1 ? parts[parts.length - 1]!.charAt(0) : '';
  return (first + last).toUpperCase();
}
