import { useState } from 'react';
import { Alert } from 'react-native';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { date } from '../src/lib/format';
import { openProtectedFile } from '../src/lib/openFile';
import { useResource } from '../src/lib/useResource';
import { Body, Card, CardTitle, Empty, ErrorBox, Loading, Screen } from '../src/ui/kit';

export default function Documentos() {
  const { api, client, residentId } = useAuth();
  const res = useResource(() => api.documents(), [residentId]);
  const [opening, setOpening] = useState<number | null>(null);

  async function open(id: number, name: string, mime?: string | null) {
    if (opening !== null) return;
    setOpening(id);
    try {
      await openProtectedFile(api.documentFileUrl(id), client.authHeaders(), name, mime ?? 'application/pdf');
    } catch (e) {
      Alert.alert('Documento', e instanceof ApiError ? e.message : 'Não foi possível abrir o documento.');
    } finally {
      setOpening(null);
    }
  }

  return (
    <Screen title="Documentos" back refreshing={res.refreshing} onRefresh={res.reload}>
      {res.loading ? <Loading /> : null}
      {res.error ? <ErrorBox error={res.error} onRetry={res.reload} /> : null}
      {res.data && res.data.length === 0 ? <Empty text="A administração ainda não publicou documentos." /> : null}
      {res.data?.map((d) => (
        <Card key={d.id} onPress={() => open(d.id, d.title, d.mime)} accessibilityLabel={`Abrir ${d.title}`}>
          <CardTitle>{d.title}</CardTitle>
          <Body muted>
            {[d.folder_label, date(d.uploaded_at.slice(0, 10))].filter(Boolean).join(' · ')}
            {opening === d.id ? ' · abrindo…' : ''}
          </Body>
        </Card>
      ))}
    </Screen>
  );
}
