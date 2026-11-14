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
            <CardTitle>{d.carrier || 'Encomenda'}</CardTitle>
            <StatusBadge kind="delivery" status={d.status} />
          </Row>
          <Body muted>
            Para {d.recipient} · chegou em {date(d.received_on)}
            {d.received_time ? ` às ${d.received_time}` : ''}
          </Body>
          {d.tracking_code ? <Body muted>Rastreio: {d.tracking_code}</Body> : null}
          {d.picked_up_on ? (
            <Body muted>
              Retirada em {date(d.picked_up_on)}
              {d.picked_up_by ? ` por ${d.picked_up_by}` : ''}
            </Body>
          ) : null}
        </Card>
      ))}
    </Screen>
  );
}
