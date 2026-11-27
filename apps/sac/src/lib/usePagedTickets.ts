import { useEffect, useState } from 'react';
import { ApiError } from '../api/client';
import type { Ticket, TicketPage } from '../api/sac';
import { appendPage } from './tickets';
import { useResource } from './useResource';

/**
 * Fila em páginas (ADR 0672): a primeira vem pelo useResource (carregando, erro, puxar para atualizar);
 * "Carregar mais" pede a seguinte com before = meta.next e junta sem repetir.
 * load(before) com before 0 é a primeira página; null = nada a carregar (ex.: busca vazia).
 */
export function usePagedTickets(load: (before: number) => Promise<TicketPage> | null, deps: unknown[]) {
  const first = useResource(() => load(0) ?? Promise.resolve(null), deps);
  const [more, setMore] = useState<Ticket[]>([]);
  const [next, setNext] = useState<number | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<ApiError | null>(null);

  useEffect(() => {
    setMore([]);
    setMoreError(null);
    setNext(first.data?.meta.next ?? null);
  }, [first.data]);

  async function loadMore() {
    if (next === null || loadingMore) return;
    setLoadingMore(true);
    try {
      const page = await load(next);
      if (!page) return;
      setMore((prev) => appendPage(prev, page.data));
      setNext(page.meta.next);
      setMoreError(null);
    } catch (e) {
      setMoreError(e instanceof ApiError ? e : new ApiError(0, 'unknown', 'Não foi possível carregar.'));
    } finally {
      setLoadingMore(false);
    }
  }

  const tickets = first.data ? appendPage(first.data.data, more) : null;
  return { ...first, tickets, hasMore: next !== null, loadingMore, moreError, loadMore };
}
