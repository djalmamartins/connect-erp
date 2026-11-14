import { useAuth } from '../src/auth/AuthContext';
import { date } from '../src/lib/format';
import { useResource } from '../src/lib/useResource';
import { Body, Card, CardTitle, Empty, ErrorBox, Loading, Row, Screen, StatusBadge } from '../src/ui/kit';

export default function Encomendas() {
  const { api, residentId } = useAuth();
  const res = useResource(() => api.deliveries(), [residentId]);
  return (
    <Screen refreshing={res.refreshing} onRefresh={res.reload}>
      {res.loading ? <Loading /> : null}
      {res.error ? <ErrorBox error={res.error} onRetry={res.reload} /> : null}
      {res.data && res.data.length === 0 ? <Empty text="Nenhuma encomenda registrada para a sua unidade." /> : null}
      {res.data?.map((d) => (
        <Card key={d.id}>
          <Row>
            <CardTitle>{d.description || d.carrier || 'Encomenda'}</CardTitle>
            <StatusBadge status={d.status} />
          </Row>
          <Body muted>
            Chegou em {date(d.received_at ?? null)}
            {d.delivered_at ? ` · retirada em ${date(d.delivered_at)}` : ''}
          </Body>
        </Card>
      ))}
    </Screen>
  );
}
