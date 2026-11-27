import type { VisitPanel } from '../api/team';

/**
 * Regras puras do Painel da visita (ADR 0662): o que acompanhar no prédio e o checklist de chegada.
 * O checklist fica só no aparelho, guardado por prédio e dia, e recomeça na visita seguinte.
 */

export const ARRIVAL_STEPS = [
  { id: 'portaria', label: 'Passar na portaria e ver o livro de ocorrências' },
  { id: 'zelador', label: 'Falar com o zelador ou o síndico' },
  { id: 'pendencias', label: 'Conferir as pendências da última vistoria' },
  { id: 'areas', label: 'Andar pelas áreas comuns' },
  { id: 'fotos', label: 'Tirar fotos do que precisar de chamado' },
] as const;

export type ArrivalStepId = (typeof ARRIVAL_STEPS)[number]['id'];

export type VisitCounts = { tickets: number; urgent: number; documents: number; expired: number; pending: number };

export function visitCounts(panel: VisitPanel): VisitCounts {
  return {
    tickets: panel.tickets.length,
    urgent: panel.urgent,
    documents: panel.documents.length,
    expired: panel.documents.filter((d) => d.days < 0).length,
    pending: panel.pending_from_last_visit.length,
  };
}

/** Frase curta do topo: o que pede atenção primeiro. */
export function visitHeadline(c: VisitCounts): string {
  const parts: string[] = [];
  if (c.urgent > 0) parts.push(`${c.urgent} ${c.urgent === 1 ? 'chamado urgente' : 'chamados urgentes'}`);
  if (c.expired > 0) parts.push(`${c.expired} ${c.expired === 1 ? 'documento vencido' : 'documentos vencidos'}`);
  if (c.pending > 0) parts.push(`${c.pending} ${c.pending === 1 ? 'pendência' : 'pendências'} da última vistoria`);
  return parts.length > 0 ? `Atenção: ${parts.join(', ')}.` : 'Nada urgente neste prédio.';
}

/** Chave do checklist guardado: um prédio num dia. */
export function arrivalKey(condominiumId: number, date: string): string {
  return `${condominiumId}:${date}`;
}

/** Lê o checklist guardado; de outro prédio ou de outro dia, começa vazio. */
export function readArrival(raw: string | null, key: string): ArrivalStepId[] {
  if (!raw) return [];
  try {
    const data = JSON.parse(raw) as { key?: unknown; done?: unknown };
    if (data.key !== key || !Array.isArray(data.done)) return [];
    const ids = new Set<string>(ARRIVAL_STEPS.map((s) => s.id));
    return data.done.filter((d): d is ArrivalStepId => typeof d === 'string' && ids.has(d));
  } catch {
    return [];
  }
}

export function writeArrival(key: string, done: ArrivalStepId[]): string {
  return JSON.stringify({ key, done });
}

export function toggleStep(done: ArrivalStepId[], id: ArrivalStepId): ArrivalStepId[] {
  return done.includes(id) ? done.filter((d) => d !== id) : [...done, id];
}

/** "Há 12 dias", "Ontem", "Hoje" a partir da data da última vistoria. */
export function sinceLabel(completedAt: string, today: string): string {
  const a = Date.UTC(+completedAt.slice(0, 4), +completedAt.slice(5, 7) - 1, +completedAt.slice(8, 10));
  const b = Date.UTC(+today.slice(0, 4), +today.slice(5, 7) - 1, +today.slice(8, 10));
  const days = Math.round((b - a) / 86400000);
  if (days <= 0) return 'Hoje';
  if (days === 1) return 'Ontem';
  return `Há ${days} dias`;
}
