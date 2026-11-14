import { router, type Href } from 'expo-router';
import { StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../src/auth/AuthContext';
import { date, money } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Card, CardTitle, ErrorBox, Loading, Screen, SectionTitle } from '../../src/ui/kit';

type Tile = { label: string; value: string; href: Href; danger?: boolean };

export default function Home() {
  const { api, me, residentId } = useAuth();
  const summary = useResource(() => api.summary(), [residentId]);
  const current = me?.current ?? null;
  const s = summary.data;

  const tiles: Tile[] = [];
  if (s?.charges) {
    tiles.push({ label: 'Em aberto', value: money(s.charges.open_cents), href: '/financeiro', danger: s.charges.overdue_cents > 0 });
    tiles.push({ label: 'Próximo vencimento', value: date(s.charges.next_due_on), href: '/financeiro' });
  }
  if (s?.deliveries_waiting !== undefined) {
    tiles.push({ label: 'Encomendas para retirar', value: String(s.deliveries_waiting), href: '/encomendas' });
  }
  if (s?.visits_today !== undefined) {
    tiles.push({ label: 'Visitas previstas hoje', value: String(s.visits_today), href: '/visitantes' });
  }

  return (
    <Screen refreshing={summary.refreshing} onRefresh={summary.reload}>
      <View style={styles.hero}>
        <Text style={styles.hello}>Olá, {current?.name?.split(' ')[0] ?? me?.user.name?.split(' ')[0] ?? 'morador'}</Text>
        {current ? (
          <Text style={styles.unit}>
            {current.condominium_name} · {current.unit_label}
          </Text>
        ) : null}
      </View>
      {summary.loading ? <Loading /> : null}
      {summary.error ? <ErrorBox error={summary.error} onRetry={summary.reload} /> : null}
      {s && s.features.length === 0 ? (
        <Card>
          <CardTitle>Tudo pronto</CardTitle>
          <Body muted>O seu condomínio ainda não ligou nenhuma função do app. Assim que liberar, ela aparece aqui.</Body>
        </Card>
      ) : null}
      {s?.charges && s.charges.overdue_cents > 0 ? (
        <Card style={{ backgroundColor: colors.dangerSoft, borderColor: colors.dangerSoft }} onPress={() => router.push('/financeiro')}>
          <Body style={{ color: colors.danger }}>Você tem {money(s.charges.overdue_cents)} vencido. Toque para ver o boleto.</Body>
        </Card>
      ) : null}
      <View style={styles.grid}>
        {tiles.map((t) => (
          <Card key={t.label} style={styles.tile} onPress={() => router.push(t.href)} accessibilityLabel={`${t.label}: ${t.value}`}>
            <Text style={styles.tileLabel}>{t.label}</Text>
            <Text style={[styles.tileValue, t.danger && { color: colors.danger }]}>{t.value}</Text>
          </Card>
        ))}
      </View>
      {s?.next_reservations && s.next_reservations.length > 0 ? (
        <>
          <SectionTitle>Próximas reservas</SectionTitle>
          {s.next_reservations.map((r) => (
            <Card key={r.id} onPress={() => router.push('/reservas')}>
              <CardTitle>{r.area}</CardTitle>
              <Body muted>
                {date(r.reserved_on)}
                {r.starts_time ? ` · ${r.starts_time}` : ''}
              </Body>
            </Card>
          ))}
        </>
      ) : null}
      {s?.assemblies && s.assemblies.length > 0 ? (
        <>
          <SectionTitle>Assembleias</SectionTitle>
          {s.assemblies.map((a) => (
            <Card key={a.id} onPress={() => router.push({ pathname: '/assembleias/[id]', params: { id: String(a.id) } })}>
              <CardTitle>{a.title}</CardTitle>
              <Body muted>{date(a.first_call_at)}</Body>
            </Card>
          ))}
        </>
      ) : null}
      {s?.announcements && s.announcements.length > 0 ? (
        <>
          <SectionTitle>Comunicados</SectionTitle>
          {s.announcements.map((a) => (
            <Card key={a.id} onPress={() => router.push('/comunicados')}>
              <CardTitle>{a.subject}</CardTitle>
              <Body muted>{date(a.published_at)}</Body>
            </Card>
          ))}
        </>
      ) : null}
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
