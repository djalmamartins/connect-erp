import { useState } from 'react';
import { Alert, View } from 'react-native';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { confirm } from '../src/lib/confirm';
import { date, parseDate } from '../src/lib/format';
import { useResource } from '../src/lib/useResource';
import { space } from '../src/theme';
import { Body, Button, Card, CardTitle, Empty, ErrorBox, Field, Loading, Row, Screen, SectionTitle, StatusBadge } from '../src/ui/kit';

export default function Visitantes() {
  const { api, residentId } = useAuth();
  const list = useResource(() => api.visits(), [residentId]);
  const [name, setName] = useState('');
  const [doc, setDoc] = useState('');
  const [day, setDay] = useState('');
  const [until, setUntil] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function save() {
    const e: Record<string, string> = {};
    const isoDate = parseDate(day);
    const untilDate = until.trim() ? parseDate(until) : null;
    if (name.trim().length < 3) e.visitor_name = 'Informe o nome do visitante.';
    if (!isoDate) e.expected_on = 'Use o formato DD/MM/AAAA.';
    if (until.trim() && !untilDate) e.expected_until = 'Use o formato DD/MM/AAAA.';
    else if (isoDate && untilDate && untilDate < isoDate) e.expected_until = 'Precisa ser depois do primeiro dia.';
    setErrors(e);
    if (Object.keys(e).length > 0 || !isoDate) return;
    setBusy(true);
    try {
      await api.createVisit({
        visitor_name: name.trim(),
        expected_on: isoDate,
        ...(doc.trim() ? { visitor_document: doc.trim() } : {}),
        ...(untilDate ? { expected_until: untilDate } : {}),
      });
      setName('');
      setDoc('');
      setDay('');
      setUntil('');
      Alert.alert('Visita avisada', 'A portaria já sabe quem vem.');
      await list.reload();
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fields);
        Alert.alert('Visitante', err.message);
      }
    } finally {
      setBusy(false);
    }
  }

  async function cancel(id: number) {
    if (!(await confirm('Cancelar visita', 'A portaria deixa de esperar este visitante.', 'Cancelar visita'))) return;
    try {
      await api.cancelVisit(id);
      await list.reload();
    } catch (err) {
      Alert.alert('Visitante', err instanceof ApiError ? err.message : 'Não foi possível cancelar.');
    }
  }

  return (
    <Screen refreshing={list.refreshing} onRefresh={list.reload}>
      {list.error?.isFeatureDisabled ? (
        <ErrorBox error={list.error} />
      ) : (
        <Card>
          <CardTitle>Avisar visita</CardTitle>
          <View style={{ gap: space[3] }}>
            <Field label="Nome do visitante" value={name} onChangeText={setName} maxLength={120} error={errors.visitor_name} autoCapitalize="words" />
            <Field label="Documento (opcional)" value={doc} onChangeText={setDoc} maxLength={30} error={errors.visitor_document} hint="RG ou CPF, para a portaria conferir." />
            <Row>
              <View style={{ flex: 1 }}>
                <Field label="Dia" placeholder="DD/MM/AAAA" value={day} onChangeText={setDay} keyboardType="numbers-and-punctuation" error={errors.expected_on} />
              </View>
              <View style={{ flex: 1 }}>
                <Field label="Até (opcional)" placeholder="DD/MM/AAAA" value={until} onChangeText={setUntil} keyboardType="numbers-and-punctuation" error={errors.expected_until} />
              </View>
            </Row>
            <Button title="Avisar a portaria" onPress={save} busy={busy} />
          </View>
        </Card>
      )}
      <SectionTitle>Visitas avisadas</SectionTitle>
      {list.loading ? <Loading /> : null}
      {list.error && !list.error.isFeatureDisabled ? <ErrorBox error={list.error} onRetry={list.reload} /> : null}
      {list.data && list.data.length === 0 ? <Empty text="Nenhuma visita avisada." /> : null}
      {list.data?.map((v) => (
        <Card key={v.id}>
          <Row>
            <CardTitle>{v.visitor_name}</CardTitle>
            <StatusBadge kind="visit" status={v.status} />
          </Row>
          <Body muted>
            {date(v.expected_on)}
            {v.expected_until && v.expected_until !== v.expected_on ? ` até ${date(v.expected_until)}` : ''}
            {v.visitor_document ? ` · doc. ${v.visitor_document}` : ''}
          </Body>
          {v.status === 'active' ? <Button title="Cancelar" variant="danger" onPress={() => cancel(v.id)} /> : null}
        </Card>
      ))}
    </Screen>
  );
}
