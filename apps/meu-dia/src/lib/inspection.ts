import type { Inspection, InspectionItem, InspectionResult } from '../api/team';

/**
 * Regras puras da vistoria no app (ADR 0663). As mesmas do servidor (InspectionService::complete), para o app
 * avisar antes de mandar: todo item respondido e foto nos itens que pedem foto (menos "Não se aplica").
 */

export const RESULT_LABEL: Record<InspectionResult, string> = {
  ok: 'Conforme',
  issue: 'Com problema',
  not_applicable: 'Não se aplica',
  pending: 'Pendente',
};

export function answered(items: InspectionItem[]): number {
  return items.filter((i) => i.result !== 'pending').length;
}

export function needsPhoto(item: InspectionItem): boolean {
  return item.requires_photo && item.result !== 'not_applicable' && item.photos.length === 0;
}

/** O que ainda falta para concluir, na ordem dos itens. */
export function blockers(items: InspectionItem[]): string[] {
  const out: string[] = [];
  for (const i of items) {
    if (i.result === 'pending') out.push(`Responda: ${i.label}`);
    else if (needsPhoto(i)) out.push(`Foto obrigatória: ${i.label}`);
  }
  return out;
}

/** Itens agrupados pela seção do modelo, na ordem em que aparecem. */
export function bySection(items: InspectionItem[]): { section: string; items: InspectionItem[] }[] {
  const groups: { section: string; items: InspectionItem[] }[] = [];
  for (const item of [...items].sort((a, b) => a.position - b.position)) {
    const name = item.section.trim() || 'Itens';
    const last = groups[groups.length - 1];
    if (last && last.section === name) last.items.push(item);
    else groups.push({ section: name, items: [item] });
  }
  return groups;
}

/** "Com problema" pede descrição de pelo menos 3 letras (igual ao servidor). */
export function noteError(result: InspectionResult, note: string): string | null {
  if (result === 'issue' && note.trim().length < 3) return 'Descreva o problema encontrado.';
  if (note.trim().length > 1000) return 'Use no máximo 1000 caracteres.';
  return null;
}

export function checkinLabel(i: Pick<Inspection, 'checkin'>): string {
  switch (i.checkin.status) {
    case 'inside':
      return 'Check-in no condomínio';
    case 'outside':
      return `Check-in a ${Math.round(i.checkin.distance_m ?? 0)} m do condomínio`;
    case 'no_reference':
      return 'Check-in feito (o condomínio ainda não tem a localização cadastrada)';
    default:
      return 'Check-in sem localização do aparelho';
  }
}

/** Caminho da foto na API; servidores antigos não mandam api_path, então o app monta o mesmo endereço. */
export function photoPath(inspectionId: number, photo: { id: number; api_path?: string }): string {
  return photo.api_path ?? `/inspections/${inspectionId}/photos/${photo.id}`;
}

/** Motivo do cancelamento (ADR 0676): as mesmas regras do servidor, 5 a 1000 caracteres. */
export function cancelReasonError(reason: string): string | null {
  const n = reason.trim().length;
  if (n < 5) return 'Explique por que a vistoria foi cancelada.';
  if (n > 1000) return 'Use no máximo 1000 caracteres.';
  return null;
}

/** Na vistoria concluída, "Só os problemas" mostra só os itens Com problema (ADR 0677). */
export function visibleItems<T extends Pick<InspectionItem, 'result'>>(items: T[], onlyIssues: boolean): T[] {
  return onlyIssues ? items.filter((i) => i.result === 'issue') : items;
}
