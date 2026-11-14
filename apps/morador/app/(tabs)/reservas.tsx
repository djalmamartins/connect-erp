import { useState } from 'react';
import { Alert, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import { confirm } from '../../src/lib/confirm';
import { date, money, parseDate, parseTime } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { space } from '../../src/theme';
import { Body, Button, Card, CardTitle, Choice, Empty, ErrorBox, Field, Loading, Row, Screen, SectionTitle, StatusBadge } from '../../src/ui/kit';

export default function Reservas() {
  const { api, residentId } = useAuth();
  const areas = useResource(() => api.commonAreas(), [residentId]);
  const list = useResource(() => api.reservations(), [residentId]);
  const [areaId, setAreaId] = useState<number | null>(null);
  const [day, setDay] = useState('');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [waitlistOffer, setWaitlistOffer] = useState<{ areaId: number; date: string } | null>(null);

  const area = areas.data?.find((a) => a.id === areaId) ?? null;

  async function book() {
    const e: Record<string, string> = {};
    const isoDate = parseDate(day);
    const startTime = start ? parseTime(start) : null;
    const endTime = end ? parseTime(end) : null;
    if (!areaId) e.common_area_id = 'Escolha o espaço.';
    if (!isoDate) e.date = 'Use o formato DD/MM/AAAA.';
    if (start && !startTime) e.start_time = 'Use HH:MM.';
    if (end && !endTime) e.end_time = 'Use HH:MM.';
    setErrors(e);
    setWaitlistOffer(null);
    if (Object.keys(e).length > 0 || !areaId || !isoDate) return;
    setBusy(true);
    try {
      await api.createReservation({
        common_area_id: areaId,
        date: isoDate,
        ...(startTime ? { start_time: startTime } : {}),
        ...(endTime ? { end_time: endTime } : {}),
      });
      setDay('');
      setStart('');
      setEnd('');
      Alert.alert('Reserva enviada', area?.requires_approval ? 'A administração vai confirmar a sua reserva.' : 'A sua reserva está feita.');
      await list.reload();
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fields);
        if (err.status === 409) {
          setWaitlistOffer({ areaId, date: isoDate });
        }
        Alert.alert('Reserva', err.message);
      }
    } finally {
      setBusy(false);
    }
  }

  async function joinWaitlist() {
    if (!waitlistOffer) return;
    setBusy(true);
    try {
      await api.joinWaitlist({ common_area_id: waitlistOffer.areaId, date: waitlistOffer.date });
      setWaitlistOffer(null);
      Alert.alert('Lista de espera', 'Pronto! Avisamos se a data ficar livre.');
    } catch (err) {
      Alert.alert('Lista de espera', err instanceof ApiError ? err.message : 'Não foi possível entrar na lista.');
    } finally {
      setBusy(false);
    }
  }

  async function cancel(id: number) {
    if (!(await confirm('Cancelar reserva', 'Quer mesmo cancelar esta reserva?', 'Cancelar reserva'))) return;
    try {
      await api.cancelReservation(id);
      await list.reload();
    } catch (err) {
      Alert.alert('Reserva', err instanceof ApiError ? err.message : 'Não foi possível cancelar.');
    }
  }

  const reload = async () => {
    await Promise.all([areas.reload(), list.reload()]);
  };

  return (
    <Screen refreshing={areas.refreshing || list.refreshing} onRefresh={reload}>
      {areas.loading ? <Loading /> : null}
      {areas.error ? <ErrorBox error={areas.error} onRetry={areas.reload} /> : null}
      {areas.data ? (
        <Card>
          <CardTitle>Nova reserva</CardTitle>
          {areas.data.length === 0 ? (
            <Body muted>Nenhum espaço disponível para reserva no momento.</Body>
          ) : (
            <View style={{ gap: space[3] }}>
              <Choice label="Espaço" options={areas.data.map((a) => ({ value: a.id, label: a.name }))} value={areaId} onChange={setAreaId} />
              {errors.common_area_id ? <Body style={{ color: '#d92d36' }}>{errors.common_area_id}</Body> : null}
              {area?.rules ? <Body muted>{area.rules}</Body> : null}
              {area?.fee_cents ? <Body muted>Taxa de uso: {money(area.fee_cents)} (vem no seu boleto).</Body> : null}
              <Field label="Data" placeholder="DD/MM/AAAA" value={day} onChangeText={setDay} keyboardType="numbers-and-punctuation" error={errors.date} />
              <Row>
                <View style={{ flex: 1 }}>
                  <Field label="Início" placeholder="HH:MM" value={start} onChangeText={setStart} keyboardType="numbers-and-punctuation" error={errors.start_time} />
                </View>
                <View style={{ flex: 1 }}>
                  <Field label="Fim" placeholder="HH:MM" value={end} onChangeText={setEnd} keyboardType="numbers-and-punctuation" error={errors.end_time} />
                </View>
              </Row>
              <Button title="Reservar" onPress={book} busy={busy} />
              {waitlistOffer ? (
                <Button title="Entrar na lista de espera desta data" variant="secondary" onPress={joinWaitlist} busy={busy} />
              ) : null}
            </View>
          )}
        </Card>
      ) : null}

      <SectionTitle>Minhas reservas</SectionTitle>
      {list.error && !areas.error ? <ErrorBox error={list.error} onRetry={list.reload} /> : null}
      {list.data && list.data.length === 0 ? <Empty text="Você ainda não tem reservas." /> : null}
      {list.data?.map((r) => (
        <Card key={r.id}>
          <Row>
            <CardTitle>{r.common_area_name ?? 'Espaço'}</CardTitle>
            <StatusBadge status={r.status} />
          </Row>
          <Body muted>
            {date(r.date)}
            {r.start_time ? ` · ${r.start_time.slice(0, 5)}` : ''}
            {r.end_time ? ` às ${r.end_time.slice(0, 5)}` : ''}
          </Body>
          {['pending', 'approved', 'confirmed', 'scheduled'].includes(r.status) ? (
            <Button title="Cancelar" variant="danger" onPress={() => cancel(r.id)} />
          ) : null}
        </Card>
      ))}
    </Screen>
  );
}
