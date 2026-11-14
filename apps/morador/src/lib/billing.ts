import type { StatementLine } from '../api/resident';

const pad = (n: number) => String(n).padStart(2, '0');

function firstOfMonth(iso: string, deltaMonths: number): string {
  const [y, m] = iso.split('-').map(Number);
  const d = new Date(y, m - 1 + deltaMonths, 1);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`;
}

function dayBefore(iso: string): string {
  const [y, m, d] = iso.split('-').map(Number);
  const dt = new Date(y, m - 1, d - 1);
  return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;
}

/**
 * Janela de busca dos boletos (regra pura). Sem cursor: 6 meses para trás e 3 para a frente
 * (contas já lançadas). Com cursor (início da janela anterior): os 6 meses antes dele.
 */
export function historyWindow(today: string, cursor: string | null): { from: string; to: string } {
  if (cursor === null) {
    return { from: firstOfMonth(today, -6), to: dayBefore(firstOfMonth(today, 4)) };
  }
  return { from: firstOfMonth(cursor, -6), to: dayBefore(cursor) };
}

/** Junta páginas sem repetir conta, da mais nova para a mais antiga. */
export function mergeLines(current: StatementLine[], next: StatementLine[]): StatementLine[] {
  const seen = new Set(current.map((l) => l.entry_id));
  return [...current, ...next.filter((l) => !seen.has(l.entry_id))].sort((a, b) => b.due_on.localeCompare(a.due_on) || b.entry_id - a.entry_id);
}
