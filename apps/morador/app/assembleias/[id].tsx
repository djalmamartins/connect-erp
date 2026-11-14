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
    <Screen back refreshing={res.refreshing} onRefresh={res.reload}>
      {res.loading ? <Loading /> : null}
      {res.error ? <ErrorBox error={res.error} onRetry={res.reload} /> : null}
      {a ? (
        <>
          <Title>{a.title}</Title>
          <StatusBadge kind="assembly" status={a.status} />
          <Body muted>
            1ª chamada: {date(a.first_call_at)}
            {a.second_call_at ? `\n2ª chamada: ${date(a.second_call_at)}` : ''}
            {a.location ? `\n${a.location}` : ''}
          </Body>
          <SectionTitle>Pauta</SectionTitle>
          {a.items.length === 0 ? <Body muted>Pauta ainda não publicada.</Body> : null}
          {a.items.map((item) => (
            <Card key={item.position}>
              <CardTitle>
                {item.position}. {item.title}
              </CardTitle>
              {item.description ? <Body muted>{item.description}</Body> : null}
              {item.approved !== null ? <Body>{item.approved ? 'Aprovado' : 'Não aprovado'}</Body> : null}
            </Card>
          ))}
          {a.minutes ? (
            <>
              <SectionTitle>Ata</SectionTitle>
              <Card>
                <Body>{a.minutes}</Body>
              </Card>
            </>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}
