import { useLocalSearchParams } from 'expo-router';
import { useAuth } from '../../src/auth/AuthContext';
import { date } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { Body, Card, CardTitle, ErrorBox, Loading, Screen, SectionTitle, StatusBadge, Title } from '../../src/ui/kit';

export default function Assembleia() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { api, residentId } = useAuth();
  const res = useResource(() => api.assembly(Number(id)), [id, residentId]);
  const a = res.data;
  return (
    <Screen refreshing={res.refreshing} onRefresh={res.reload}>
      {res.loading ? <Loading /> : null}
      {res.error ? <ErrorBox error={res.error} onRetry={res.reload} /> : null}
      {a ? (
        <>
          <Title>{a.title}</Title>
          <StatusBadge status={a.status} />
          <Body muted>
            {date(a.date ?? null)}
            {a.location ? ` · ${a.location}` : ''}
          </Body>
          <SectionTitle>Pauta</SectionTitle>
          {(a.agenda ?? []).length === 0 ? <Body muted>Pauta ainda não publicada.</Body> : null}
          {(a.agenda ?? []).map((item, index) => (
            <Card key={`${item.order ?? index}-${item.title}`}>
              <CardTitle>
                {item.order ?? index + 1}. {item.title}
              </CardTitle>
              {item.result ? <Body muted>Resultado: {item.result}</Body> : null}
            </Card>
          ))}
        </>
      ) : null}
    </Screen>
  );
}
