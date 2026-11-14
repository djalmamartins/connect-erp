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

const STATUS_LABELS: Record<string, string> = {
  open: 'Em aberto',
  pending: 'Pendente',
  paid: 'Pago',
  settled: 'Pago',
  overdue: 'Vencido',
  cancelled: 'Cancelado',
  canceled: 'Cancelado',
  registered: 'Registrado',
  approved: 'Aprovada',
  rejected: 'Recusada',
  confirmed: 'Confirmada',
  received: 'Recebida',
  delivered: 'Entregue',
  waiting: 'Aguardando retirada',
  in_progress: 'Em andamento',
  closed: 'Encerrada',
  scheduled: 'Agendada',
  expected: 'Prevista',
  expired: 'Vencida',
  done: 'Concluída',
};

export function statusLabel(status: string | null | undefined): string {
  if (!status) {
    return '—';
  }
  return STATUS_LABELS[status] ?? status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
}

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export function statusTone(status: string | null | undefined): Tone {
  switch (status) {
    case 'paid':
    case 'settled':
    case 'approved':
    case 'confirmed':
    case 'delivered':
    case 'done':
      return 'success';
    case 'overdue':
    case 'rejected':
    case 'expired':
      return 'danger';
    case 'open':
    case 'pending':
    case 'waiting':
    case 'expected':
    case 'scheduled':
    case 'in_progress':
      return 'warning';
    case 'cancelled':
    case 'canceled':
    case 'closed':
      return 'neutral';
    default:
      return 'info';
  }
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
