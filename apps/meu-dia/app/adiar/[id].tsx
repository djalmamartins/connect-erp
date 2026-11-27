import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ApiError } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import { addDays, dayKey, shortDay } from '../../src/lib/day';
import { parseDate } from '../../src/lib/format';
import { dialog } from '../../src/ui/Dialog';
import { Body, Button, Card, Choice, Field, Screen } from '../../src/ui/kit';

function br(iso: string): string {
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
}

/**
 * Adiar um compromisso para outro dia e hora (ADR 0668), pelo POST /my-day/events/{id}/move.
 * Sem "Termina às", o servidor mantém a duração que o compromisso já tinha.
 */
export default function Postpone() {
  const { api } = useAuth();
  const params = useLocalSearchParams<{ id: string; title?: string; date?: string; start?: string }>();
  const id = Number(params.id);
  const today = dayKey(new Date());
  const [date, setDate] = useState(br(typeof params.date === 'string' && params.date ? params.date : today));
  const [start, setStart] = useState(typeof params.start === 'string' ? params.start : '09:00');
  const [end, setEnd] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function save() {
    const iso = parseDate(date);
    if (!iso) {
      setErrors({ date: 'Data inválida. Use DD/MM/AAAA.' });
      return;
    }
    setBusy(true);
    setErrors({});
    try {
      await api.moveEvent(id, iso, start, end || undefined);
      dialog.toast(`Adiado para ${shortDay(iso)} às ${start}`);
      router.navigate({ pathname: '/', params: { date: iso } });
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fields).length > 0) setErrors(e.fields as Record<string, string>);
      else await dialog.notice({ title: 'Não deu para adiar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen back title="Adiar" subtitle={typeof params.title === 'string' ? params.title : undefined}>
      <Card>
        <Field label="Novo dia" value={date} onChangeText={setDate} mask="date" error={errors.date} />
        <Choice
          label="Atalhos"
          options={[
            { value: today, label: 'Hoje' },
            { value: addDays(today, 1), label: 'Amanhã' },
            { value: addDays(today, 7), label: 'Daqui a 1 semana' },
          ]}
          value={parseDate(date)}
          onChange={(v) => setDate(br(v))}
        />
        <Field label="Começa às" value={start} onChangeText={setStart} mask="time" error={errors.start} />
        <Field label="Termina às (opcional)" value={end} onChangeText={setEnd} mask="time" error={errors.end} hint="Sem fim, mantém a mesma duração." />
        {errors.status ? <Body muted>{errors.status}</Body> : null}
        <Button title="Adiar" onPress={() => void save()} busy={busy} />
      </Card>
    </Screen>
  );
}
