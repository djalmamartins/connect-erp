import { useState } from 'react';
import { Alert, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { Priority } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { date, parseDate, today } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { space } from '../../src/theme';
import { Body, Button, Card, CardTitle, Choice, Empty, ErrorBox, Field, Loading, Row, Screen, SectionTitle, StatusBadge } from '../../src/ui/kit';

const PRIORITIES: { value: Priority; label: string }[] = [
  { value: 'baixa', label: 'Baixa' },
  { value: 'media', label: 'Média' },
  { value: 'alta', label: 'Alta' },
];

export default function Ocorrencias() {
  const { api, residentId } = useAuth();
  const list = useResource(() => api.occurrences(), [residentId]);
  const [subject, setSubject] = useState('');
  const [description, setDescription] = useState('');
  const [priority, setPriority] = useState<Priority>('media');
  const [when, setWhen] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function send() {
    const e: Record<string, string> = {};
    const occurredOn = when.trim() === '' ? today() : parseDate(when);
    if (subject.trim().length < 3) e.subject = 'Escreva um assunto curto.';
    if (description.trim().length < 5) e.description = 'Conte o que aconteceu.';
    if (!occurredOn) e.occurred_on = 'Use o formato DD/MM/AAAA.';
    setErrors(e);
    if (Object.keys(e).length > 0 || !occurredOn) return;
    setBusy(true);
    try {
      await api.createOccurrence({ subject: subject.trim(), description: description.trim(), priority, occurred_on: occurredOn });
      setSubject('');
      setDescription('');
      setWhen('');
      setPriority('media');
      Alert.alert('Ocorrência registrada', 'A administração vai analisar e responder por aqui.');
      await list.reload();
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fields);
        Alert.alert('Ocorrência', err.message);
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title="Ocorrências" tab refreshing={list.refreshing} onRefresh={list.reload}>
      {list.error?.isFeatureDisabled ? (
        <ErrorBox error={list.error} />
      ) : (
        <Card>
          <CardTitle>Registrar ocorrência</CardTitle>
          <View style={{ gap: space[3] }}>
            <Field label="Assunto" value={subject} onChangeText={setSubject} maxLength={120} error={errors.subject} />
            <Field label="O que aconteceu" value={description} onChangeText={setDescription} multiline maxLength={2000} error={errors.description} />
            <Choice label="Prioridade" options={PRIORITIES} value={priority} onChange={setPriority} />
            <Field label="Quando" placeholder="DD/MM/AAAA (vazio = hoje)" value={when} onChangeText={setWhen} keyboardType="numbers-and-punctuation" error={errors.occurred_on} />
            <Button title="Enviar" onPress={send} busy={busy} />
          </View>
        </Card>
      )}
      <SectionTitle>Minhas ocorrências</SectionTitle>
      {list.loading ? <Loading /> : null}
      {list.error && !list.error.isFeatureDisabled ? <ErrorBox error={list.error} onRetry={list.reload} /> : null}
      {list.data && list.data.length === 0 ? <Empty text="Nenhuma ocorrência registrada." /> : null}
      {list.data?.map((o) => (
        <Card key={o.id}>
          <Row>
            <CardTitle>{o.subject}</CardTitle>
            <StatusBadge kind="occurrence" status={o.status} />
          </Row>
          <Body>{o.description}</Body>
          <Body muted>
            {date(o.occurred_on)}
            {o.has_ticket ? ' · em atendimento pela administração' : ''}
          </Body>
        </Card>
      ))}
    </Screen>
  );
}
