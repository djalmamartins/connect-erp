import type { Reservation } from '../api/resident';

/**
 * Aviso de reserva aprovada (regra pura). O app guarda as reservas que estavam aguardando o síndico;
 * quando uma delas aparece confirmada, vira aviso até o morador fechar.
 */
export function trackApprovals(
  reservations: Pick<Reservation, 'id' | 'status'>[],
  pendingBefore: number[],
  unseenBefore: number[],
): { pending: number[]; unseen: number[] } {
  const confirmed = new Set(reservations.filter((r) => r.status === 'confirmed').map((r) => r.id));
  const newly = pendingBefore.filter((id) => confirmed.has(id));
  const unseen = [...new Set([...unseenBefore, ...newly])].filter((id) => confirmed.has(id));
  const pending = reservations.filter((r) => r.status === 'active').map((r) => r.id);
  return { pending, unseen };
}
