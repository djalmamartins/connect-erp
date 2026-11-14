import { useAuth } from '../src/auth/AuthContext';
import { date } from '../src/lib/format';
import { useResource } from '../src/lib/useResource';
import { Body, Card, CardTitle, Empty, ErrorBox, Loading, Screen } from '../src/ui/kit';

export default function Comunicados() {
  const { api, residentId } = useAuth();
  const res = useResource(() => api.announcements(), [residentId]);
  return (
    <Screen refreshing={res.refreshing} onRefresh={res.reload}>
      {res.loading ? <Loading /> : null}
      {res.error ? <ErrorBox error={res.error} onRetry={res.reload} /> : null}
      {res.data && res.data.length === 0 ? <Empty text="Nenhum comunicado por enquanto." /> : null}
      {res.data?.map((a) => (
        <Card key={a.id}>
          <CardTitle>{a.title}</CardTitle>
          <Body muted>{date(a.published_at ?? null)}</Body>
          {a.body ? <Body>{a.body}</Body> : null}
        </Card>
      ))}
    </Screen>
  );
}
