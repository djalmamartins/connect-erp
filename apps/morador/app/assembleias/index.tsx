import { router } from 'expo-router';
import { useAuth } from '../../src/auth/AuthContext';
import { date } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { Body, Card, CardTitle, Empty, ErrorBox, Loading, Row, Screen, StatusBadge } from '../../src/ui/kit';

export default function Assembleias() {
  const { api, residentId } = useAuth();
  const res = useResource(() => api.assemblies(), [residentId]);
  return (
    <Screen refreshing={res.refreshing} onRefresh={res.reload}>
      {res.loading ? <Loading /> : null}
      {res.error ? <ErrorBox error={res.error} onRetry={res.reload} /> : null}
      {res.data && res.data.length === 0 ? <Empty text="Nenhuma assembleia marcada." /> : null}
      {res.data?.map((a) => (
        <Card key={a.id} onPress={() => router.push({ pathname: '/assembleias/[id]', params: { id: String(a.id) } })} accessibilityLabel={a.title}>
          <Row>
            <CardTitle>{a.title}</CardTitle>
            <StatusBadge status={a.status} />
          </Row>
          <Body muted>{date(a.date ?? null)}</Body>
        </Card>
      ))}
    </Screen>
  );
}
