import type { Privacy } from '../api/resident';

/**
 * Precisa mostrar a tela de aceite (LGPD, ADR 0600)? Enquanto carrega (null) ou se o servidor ainda não tiver a rota
 * (undefined), não prende o morador: o servidor web pede o aceite de novo na próxima vez.
 */
export function needsTerms(privacy: Privacy | null | undefined): boolean {
  return !!privacy && privacy.accepted === false;
}

/** Tipos de pedido do titular, na ordem em que o servidor mandou. */
export function requestOptions(privacy: Privacy | null | undefined): { value: string; label: string }[] {
  return Object.entries(privacy?.requests ?? {}).map(([value, label]) => ({ value, label }));
}

/** "2026-12-05" -> "05/12/2026". */
export function brDate(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : iso;
}
