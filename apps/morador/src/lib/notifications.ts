import type { ResidentNotification } from '../api/resident';

/**
 * Tela do app para o caminho do aviso (regra pura). O sininho vem do mesmo serviço do app web,
 * então o caminho é o da web (/boletos/12, /comunicados#c3...); aqui vira a rota nativa.
 */
export function notificationRoute(path: string): string {
  const clean = path.split('#')[0];
  if (clean.startsWith('/boletos')) return '/financeiro';
  if (clean.startsWith('/assembleias/')) return clean;
  if (/^\/chamados\/\d+$/.test(clean)) return clean;
  if (clean === '/ocorrencias' || clean === '/chamados') return '/chamados';
  const known = ['/encomendas', '/visitantes', '/comunicados', '/reservas', '/assembleias', '/documentos', '/prestacao-de-contas'];
  return known.find((k) => clean === k) ?? '/avisos';
}

/** Quantos avisos são novos desde a última vez que o morador abriu o sininho. Data futura conta como agora. */
export function unseenCount(items: Pick<ResidentNotification, 'at'>[], seenAt: string | null, now: string): number {
  if (!seenAt) return items.length;
  return items.filter((i) => (i.at > now ? now : i.at) > seenAt).length;
}

/** Data e hora local no formato do servidor (AAAA-MM-DD HH:MM:SS). */
export function stamp(d: Date = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}
