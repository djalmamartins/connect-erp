import { File, Paths } from 'expo-file-system';
import { Platform } from 'react-native';
import { ApiError } from '../api/client';
import type { Day } from '../api/team';

/**
 * O dia sem internet (ADR 0669): a última resposta do GET /my-day de cada dia aberto fica num arquivo dentro do app
 * (pasta de documentos do próprio app, que o sistema protege), só da pessoa conectada e dos últimos 7 dias vistos.
 * Sem conexão, a aba Hoje mostra a cópia com o aviso da hora em que foi salva. O arquivo é apagado ao sair.
 */

/** Só falta de conexão (sem rede ou sem resposta) usa a cópia; erro do servidor continua aparecendo como erro. */
export function isOfflineError(e: unknown): boolean {
  return e instanceof ApiError && (e.code === 'network' || e.code === 'timeout');
}

export const MAX_ENTRIES = 7;
const FILE_NAME = 'meu-dia-offline.json';

export type CacheEntry = { savedAt: string; day: Day };
export type Cache = { userId: number; entries: Record<string, CacheEntry> };

export function cacheKey(date: string | undefined, personId: number | null): string {
  return `${personId ?? 'eu'}:${date ?? 'hoje'}`;
}

/** Guarda (ou troca) um dia e mantém só os MAX_ENTRIES mais recentes. Outra pessoa conectada começa do zero. */
export function putEntry(cache: Cache | null, userId: number, key: string, day: Day, now: Date): Cache {
  const base: Cache = cache && cache.userId === userId ? cache : { userId, entries: {} };
  const entries = { ...base.entries, [key]: { savedAt: now.toISOString(), day } };
  const keep = Object.entries(entries)
    .sort((a, b) => b[1].savedAt.localeCompare(a[1].savedAt))
    .slice(0, MAX_ENTRIES);
  return { userId, entries: Object.fromEntries(keep) };
}

export function getEntry(cache: Cache | null, userId: number, key: string): CacheEntry | null {
  if (!cache || cache.userId !== userId) return null;
  return cache.entries[key] ?? null;
}

/** "de hoje às 14:32", "de ontem às 18:05" ou "de 25/11 às 09:10". */
export function savedLabel(savedAt: string, now: Date): string {
  const at = new Date(savedAt);
  const hhmm = `${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}`;
  const day = (d: Date) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (day(at) === day(now)) return `de hoje às ${hhmm}`;
  if (day(at) === day(yesterday)) return `de ontem às ${hhmm}`;
  return `de ${String(at.getDate()).padStart(2, '0')}/${String(at.getMonth() + 1).padStart(2, '0')} às ${hhmm}`;
}

function file(): File | null {
  if (Platform.OS === 'web') return null;
  try {
    return new File(Paths.document, FILE_NAME);
  } catch {
    return null;
  }
}

async function read(): Promise<Cache | null> {
  const f = file();
  if (!f || !f.exists) return null;
  try {
    const data = JSON.parse(await f.text()) as Cache;
    return typeof data?.userId === 'number' && data.entries && typeof data.entries === 'object' ? data : null;
  } catch {
    return null;
  }
}

export const offline = {
  async save(userId: number, key: string, day: Day): Promise<void> {
    const f = file();
    if (!f) return;
    try {
      const next = putEntry(await read(), userId, key, day, new Date());
      if (!f.exists) f.create();
      f.write(JSON.stringify(next));
    } catch {
      // Sem espaço ou sem acesso: o app segue normal, só não terá a cópia sem internet.
    }
  },
  async load(userId: number, key: string): Promise<CacheEntry | null> {
    return getEntry(await read(), userId, key);
  },
  clear(): void {
    const f = file();
    try {
      if (f?.exists) f.delete();
    } catch {
      // nada a fazer
    }
  },
};
