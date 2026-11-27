import { useState } from 'react';
import type { Day } from '../api/team';
import { useAuth } from '../auth/AuthContext';
import { cacheKey, isOfflineError, offline } from './offline';
import { type Resource, useResource } from './useResource';

/**
 * GET /my-day de um dia com a cópia sem internet (ADR 0669), usado por Hoje e Semana (ADR 0673). As duas abas pedem o
 * mesmo dia ao servidor, então dividem a mesma cópia: o que foi visto numa aparece na outra sem conexão.
 */
export function useDay(date: string | undefined, personId: number | null): { day: Resource<Day>; staleAt: string | null } {
  const { api, me } = useAuth();
  const [staleAt, setStaleAt] = useState<string | null>(null);
  const day = useResource(async () => {
    const key = cacheKey(date, personId);
    try {
      const fresh = await api.day(date, personId);
      setStaleAt(null);
      if (me) void offline.save(me.id, key, fresh);
      return fresh;
    } catch (e) {
      if (me && isOfflineError(e)) {
        const saved = await offline.load(me.id, key);
        if (saved) {
          setStaleAt(saved.savedAt);
          return saved.day;
        }
      }
      throw e;
    }
  }, [date, personId]);
  return { day, staleAt };
}
