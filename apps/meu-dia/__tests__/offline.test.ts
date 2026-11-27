import type { Day } from '../src/api/team';
import { ApiError } from '../src/api/client';
import { cacheKey, getEntry, isOfflineError, MAX_ENTRIES, putEntry, savedLabel } from '../src/lib/offline';

jest.mock('expo-file-system', () => ({ File: jest.fn(), Paths: {} }));

const day = (date: string) => ({ date, today: date }) as unknown as Day;

describe('dia sem internet', () => {
  it('guarda por pessoa e por dia, só os mais recentes', () => {
    let cache = null;
    for (let i = 0; i < MAX_ENTRIES + 2; i++) {
      cache = putEntry(cache, 5, cacheKey(`2026-11-${10 + i}`, null), day(`2026-11-${10 + i}`), new Date(2026, 10, 27, 8, i));
    }
    expect(Object.keys(cache!.entries)).toHaveLength(MAX_ENTRIES);
    expect(getEntry(cache, 5, 'eu:2026-11-10')).toBeNull();
    expect(getEntry(cache, 5, 'eu:2026-11-18')?.day.date).toBe('2026-11-18');
    // Outra pessoa conectada no aparelho não vê a cópia da anterior.
    expect(getEntry(cache, 6, 'eu:2026-11-18')).toBeNull();
    expect(Object.keys(putEntry(cache, 6, 'eu:hoje', day('2026-11-27'), new Date()).entries)).toEqual(['eu:hoje']);
    expect(cacheKey(undefined, 9)).toBe('9:hoje');
  });

  it('diz de quando são os dados', () => {
    const now = new Date(2026, 10, 27, 15, 0);
    expect(savedLabel(new Date(2026, 10, 27, 14, 32).toISOString(), now)).toBe('de hoje às 14:32');
    expect(savedLabel(new Date(2026, 10, 26, 18, 5).toISOString(), now)).toBe('de ontem às 18:05');
    expect(savedLabel(new Date(2026, 10, 25, 9, 10).toISOString(), now)).toBe('de 25/11 às 09:10');
  });

  it('só usa a cópia quando falta conexão', () => {
    expect(isOfflineError(new ApiError(0, 'network', 'x'))).toBe(true);
    expect(isOfflineError(new ApiError(0, 'timeout', 'x'))).toBe(true);
    expect(isOfflineError(new ApiError(500, 'server_error', 'x'))).toBe(false);
    expect(isOfflineError(new ApiError(403, 'forbidden', 'x'))).toBe(false);
    expect(isOfflineError(new Error('x'))).toBe(false);
  });
});
