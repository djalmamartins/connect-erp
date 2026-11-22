import { useState } from 'react';
import { View } from 'react-native';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { confirm } from '../src/lib/confirm';
import { date, parseDate } from '../src/lib/format';
import { useResource } from '../src/lib/useResource';
import { space } from '../src/theme';
import { Body, Button, Card, CardTitle, Empty, ErrorBox, Field, Loading, Row, Screen, SectionTitle, StatusBadge } from '../src/ui/kit';
import { dialog } from '../src/ui/Dialog';

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
      void dialog.notice({ title: 'Visita avisada', message: 'A portaria já sabe quem vem.', tone: 'success', icon: 'person-add' });
      await list.reload();
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fields);
        void dialog.notice({ title: 'Não deu para avisar', message: err.message, tone: 'error' });
      }
    } finally {
      setBusy(false);
    }
  }

  async function cancel(id: number) {
    if (!(await confirm('Cancelar visita', 'A portaria deixa de esperar este visitante.', 'Cancelar visita', { undo: false, text: 'Se a visita vier, avise de novo.' }))) return;
    try {
      await api.cancelVisit(id);
      await list.reload();
    } catch (err) {
      void dialog.notice({ title: 'Não deu para cancelar', message: err instanceof ApiError ? err.message : 'Tente de novo em instantes.', tone: 'error' });
    }
  }

  return (
    <Screen title="Visitantes" back refreshing={list.refreshing} onRefresh={list.reload}>
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
                <Field label="Dia" mask="date" value={day} onChangeText={setDay} error={errors.expected_on} />
              </View>
              <View style={{ flex: 1 }}>
                <Field label="Até (opcional)" mask="date" value={until} onChangeText={setUntil} error={errors.expected_until} />
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
