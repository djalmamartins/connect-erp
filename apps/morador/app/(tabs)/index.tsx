import { router, type Href } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';
import { isFeatureOn, type Feature } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { date, money } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Card, CardTitle, ErrorBox, Loading, Screen } from '../../src/ui/kit';

type Tile = { feature: Feature; label: string; value: string; href: Href };

export default function Home() {
  const { api, me, residentId } = useAuth();
  const summary = useResource(() => api.summary(), [residentId]);
  const current = me?.current ?? me?.links.find((l) => l.resident_id === residentId) ?? null;
  const s = summary.data;

  const tiles: Tile[] = s
    ? [
        {
          feature: 'charges',
          label: 'Em aberto',
          value: `${money(s.open_charges_cents ?? 0)}${s.overdue_charges_count ? ` · ${s.overdue_charges_count} vencido(s)` : ''}`,
          href: '/financeiro',
        },
        { feature: 'charges', label: 'Próximo vencimento', value: date(s.next_due_date ?? null), href: '/financeiro' },
        { feature: 'deliveries', label: 'Encomendas para retirar', value: String(s.pending_deliveries ?? 0), href: '/encomendas' },
        { feature: 'reservations', label: 'Reservas marcadas', value: String(s.upcoming_reservations ?? 0), href: '/reservas' },
        { feature: 'occurrences', label: 'Ocorrências abertas', value: String(s.open_occurrences ?? 0), href: '/ocorrencias' },
        { feature: 'announcements', label: 'Comunicados novos', value: String(s.unread_announcements ?? 0), href: '/comunicados' },
      ].filter((t) => isFeatureOn(me, t.feature as Feature)) as Tile[]
    : [];

  return (
    <Screen refreshing={summary.refreshing} onRefresh={summary.reload}>
      <View style={styles.hero}>
        <Text style={styles.hello}>Olá, {me?.person.name?.split(' ')[0] ?? 'morador'}</Text>
        {current ? (
          <Text style={styles.unit}>
            {current.condominium_name} · {current.unit_label}
          </Text>
        ) : null}
      </View>
      {summary.loading ? <Loading /> : null}
      {summary.error ? <ErrorBox error={summary.error} onRetry={summary.reload} /> : null}
      {s && tiles.length === 0 ? (
        <Card>
          <CardTitle>Tudo pronto</CardTitle>
          <Body muted>O seu condomínio ainda não ligou nenhuma função do app. Assim que liberar, ela aparece aqui.</Body>
        </Card>
      ) : null}
      <View style={styles.grid}>
        {tiles.map((t) => (
          <Card key={t.label} style={styles.tile} onPress={() => router.push(t.href)} accessibilityLabel={`${t.label}: ${t.value}`}>
            <Text style={styles.tileLabel}>{t.label}</Text>
            <Text style={styles.tileValue}>{t.value}</Text>
          </Card>
        ))}
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  hero: { backgroundColor: colors.sidebar, borderRadius: radius.lg, padding: space[5], gap: space[1] },
  hello: { fontFamily: fonts.bold, fontSize: 22, color: '#ffffff' },
  unit: { fontFamily: fonts.medium, fontSize: 14, color: colors.accent },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: space[3] },
  tile: { flexBasis: '47%', flexGrow: 1 },
  tileLabel: { fontFamily: fonts.medium, fontSize: 13, color: colors.muted },
  tileValue: { fontFamily: fonts.bold, fontSize: 18, color: colors.text },
});
