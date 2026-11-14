import * as Linking from 'expo-linking';
import { useAuth } from '../src/auth/AuthContext';
import { date, periodLabel } from '../src/lib/format';
import { useResource } from '../src/lib/useResource';
import { Body, Card, CardTitle, Empty, ErrorBox, Loading, Screen } from '../src/ui/kit';

export default function PrestacaoDeContas() {
  const { api, residentId } = useAuth();
  const res = useResource(() => api.accountability(), [residentId]);
  return (
    <Screen title="Prestação de contas" back refreshing={res.refreshing} onRefresh={res.reload}>
      {res.loading ? <Loading /> : null}
      {res.error ? <ErrorBox error={res.error} onRetry={res.reload} /> : null}
      {res.data && res.data.length === 0 ? <Empty text="Nenhuma prestação de contas publicada ainda." /> : null}
      {res.data?.map((link) => (
        <Card key={link.id} onPress={() => Linking.openURL(link.url)} accessibilityLabel={`Abrir ${periodLabel(link.competence, link.kind)}`}>
          <CardTitle>{periodLabel(link.competence, link.kind)}</CardTitle>
          <Body muted>{`Publicada em ${date(link.generated_at.slice(0, 10))}${link.version > 1 ? ` · versão ${link.version}` : ''}`}</Body>
        </Card>
      ))}
    </Screen>
  );
}
