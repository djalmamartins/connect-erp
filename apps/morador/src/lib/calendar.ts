/**
 * Calendário de reservas (regras puras, testadas): grade do mês e situação de cada dia
 * conforme o prazo da área e os horários já tomados.
 */
export type Busy = { date: string; starts_time: string | null; ends_time: string | null };
export type DayState = 'past' | 'outside' | 'full' | 'partial' | 'free';
export type AreaWindow = { min_advance_days: number | null; max_advance_days: number | null };

const pad = (n: number) => String(n).padStart(2, '0');

export function isoDay(year: number, month: number, day: number): string {
  return `${year}-${pad(month)}-${pad(day)}`;
}

export function monthKey(year: number, month: number): string {
  return `${year}-${pad(month)}`;
}

/** Soma meses a um "AAAA-MM" (aceita negativo). */
export function shiftMonth(key: string, delta: number): string {
  const [y, m] = key.split('-').map(Number);
  const index = y * 12 + (m - 1) + delta;
  return monthKey(Math.floor(index / 12), (index % 12) + 1);
}

/** Semanas do mês começando no domingo; null nas casas fora do mês. */
export function monthGrid(key: string): (string | null)[][] {
  const [y, m] = key.split('-').map(Number);
  const first = new Date(y, m - 1, 1).getDay();
  const days = new Date(y, m, 0).getDate();
  const cells: (string | null)[] = Array(first).fill(null);
  for (let d = 1; d <= days; d++) cells.push(isoDay(y, m, d));
  while (cells.length % 7 !== 0) cells.push(null);
  const weeks: (string | null)[][] = [];
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));
  return weeks;
}

function addDays(iso: string, n: number): string {
  const [y, m, d] = iso.split('-').map(Number);
  const dt = new Date(y, m - 1, d + n);
  return isoDay(dt.getFullYear(), dt.getMonth() + 1, dt.getDate());
}

/** Horários tomados no dia. Um horário sem início/fim ocupa o dia inteiro. */
export function busyOn(day: string, busy: Busy[]): Busy[] {
  return busy.filter((b) => b.date === day);
}

export function dayState(day: string, today: string, area: AreaWindow, busy: Busy[]): DayState {
  if (day < today) return 'past';
  if (area.min_advance_days !== null && day < addDays(today, area.min_advance_days)) return 'outside';
  if (area.max_advance_days !== null && day > addDays(today, area.max_advance_days)) return 'outside';
  const taken = busyOn(day, busy);
  if (taken.length === 0) return 'free';
  return taken.some((b) => !b.starts_time || !b.ends_time) ? 'full' : 'partial';
}
