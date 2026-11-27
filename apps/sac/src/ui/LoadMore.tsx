import { Text } from 'react-native';
import type { ApiError } from '../api/client';
import { colors, fonts } from '../theme';
import { Button } from './kit';

/** Fim da lista: "Carregar mais" enquanto houver página seguinte (ADR 0672). */
export function LoadMore({ hasMore, busy, error, onPress }: { hasMore: boolean; busy: boolean; error: ApiError | null; onPress: () => void }) {
  if (!hasMore) return null;
  return (
    <>
      {error ? <Text style={{ fontFamily: fonts.regular, fontSize: 13, color: colors.danger, textAlign: 'center' }}>{error.message}</Text> : null}
      <Button title={error ? 'Tentar de novo' : 'Carregar mais'} variant="secondary" busy={busy} onPress={onPress} />
    </>
  );
}
