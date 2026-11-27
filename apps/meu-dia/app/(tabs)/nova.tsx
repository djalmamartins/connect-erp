import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Text } from 'react-native';
import { ApiError, type FieldErrors } from '../../src/api/client';
import type { EventType, Person } from '../../src/api/team';
import { useAuth } from '../../src/auth/AuthContext';
import { addDays, dayKey } from '../../src/lib/day';
import { parseDate, parseTime } from '../../src/lib/format';
import { colors, fonts } from '../../src/theme';
import { dialog } from '../../src/ui/Dialog';
import { Body, Button, Card, Choice, Field, Screen } from '../../src/ui/kit';

const TYPES: { value: EventType; label: string }[] = [
  { value: 'task', label: 'Tarefa' },
  { value: 'meeting', label: 'Compromisso' },
  { value: 'support', label: 'Atendimento' },
  { value: 'deadline', label: 'Prazo' },
];

function br(iso: string): string {
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
}

/**
 * Nova tarefa na agenda (POST /api/v1/my-day/events). Para você ou, com "ver a equipe", para outra pessoa: ela recebe
 * um aviso no celular e a tarefa aparece no Meu Dia dela. Vindo de um chamado em "Para agendar", já chega preenchida.
 */
export default function NewTask() {
  const { api, me } = useAuth();
  const params = useLocalSearchParams<{ ticket_id?: string; title?: string; type?: string; for?: string; copy?: string; description?: string; date?: string; start?: string; end?: string }>();
  const today = dayKey(new Date());
  const [title, setTitle] = useState('');
  const [date, setDate] = useState(br(today));
  const [start, setStart] = useState('09:00');
  const [end, setEnd] = useState('');
  const [type, setType] = useState<EventType>('task');
  const [description, setDescription] = useState('');
  const [assignee, setAssignee] = useState<number | null>(null);
  const [ticketId, setTicketId] = useState<number | null>(null);
  const [team, setTeam] = useState<Person[]>([]);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (params.ticket_id) {
      setTicketId(Number(params.ticket_id) || null);
      setTitle(typeof params.title === 'string' ? params.title.slice(0, 180) : '');
      setType('support');
    }
    if (params.for) setAssignee(Number(params.for) || null);
  }, [params.ticket_id, params.title, params.for]);

  // "Repetir em outro dia" (ADR 0679): chega com os campos do compromisso, no dia seguinte.
  useEffect(() => {
    if (params.copy !== '1') return;
    setTicketId(null);
    setTitle(typeof params.title === 'string' ? params.title.slice(0, 180) : '');
    if (params.type === 'meeting' || params.type === 'task' || params.type === 'deadline' || params.type === 'support') setType(params.type);
    setDescription(typeof params.description === 'string' ? params.description : '');
    if (typeof params.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(params.date)) setDate(br(params.date));
    if (typeof params.start === 'string' && /^\d{2}:\d{2}$/.test(params.start)) setStart(params.start);
    setEnd(typeof params.end === 'string' && /^\d{2}:\d{2}$/.test(params.end) ? params.end : '');
  }, [params.copy, params.title, params.type, params.description, params.date, params.start, params.end]);

  useEffect(() => {
    if (me?.can_see_team) {
      api.team().then(setTeam, () => setTeam([]));
    }
  }, [api, me?.can_see_team]);

  function reset() {
    setTitle('');
    setDate(br(today));
    setStart('09:00');
    setEnd('');
    setType('task');
    setDescription('');
    setAssignee(null);
    setTicketId(null);
    setErrors({});
    router.setParams({ ticket_id: '', title: '', type: '', for: '' });
  }

  async function save() {
    const local: FieldErrors = {};
    const iso = parseDate(date);
    const s = parseTime(start);
    const e = end.trim() === '' ? null : parseTime(end);
    if (title.trim().length < 3) local.title = 'Diga em poucas palavras o que é (pelo menos 3 letras).';
    if (!iso) local.date = 'Data inválida. Use DD/MM/AAAA.';
    if (!s) local.start = 'Hora inválida (ex.: 09:00).';
    if (end.trim() !== '' && !e) local.end = 'Hora inválida.';
    if (s && e && e <= s) local.end = 'O fim precisa ser depois do início.';
    setErrors(local);
    if (Object.keys(local).length) return;
    setBusy(true);
    try {
      await api.createTask({
        title: title.trim(),
        date: iso!,
        start: s!,
        ...(e ? { end: e } : {}),
        type,
        ...(description.trim() ? { description: description.trim() } : {}),
        ...(assignee && assignee !== me?.id ? { assigned_to: assignee } : {}),
        ...(ticketId ? { ticket_id: ticketId } : {}),
      });
      const other = assignee && assignee !== me?.id ? team.find((p) => p.id === assignee)?.name : null;
      reset();
      dialog.toast(other ? `Tarefa enviada para ${other}` : 'Tarefa marcada');
      router.navigate({ pathname: '/', params: { date: iso! } });
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fields).length) setErrors(err.fields);
      else await dialog.notice({ title: 'Não deu para salvar', message: err instanceof ApiError ? err.message : 'Tente de novo.', tone: 'error' });
    } finally {
      setBusy(false);
    }
  }

  const people: { value: number; label: string }[] = [
    { value: me?.id ?? 0, label: 'Para mim' },
    ...team.filter((p) => p.id !== me?.id).map((p) => ({ value: p.id, label: p.name })),
  ];

  return (
    <Screen tab title="Nova tarefa" subtitle={ticketId ? 'Horário para atender o chamado' : 'Entra no Meu Dia de quem vai fazer'}>
      <Card style={{ gap: 14 }}>
        <Field label="O que fazer" value={title} onChangeText={setTitle} error={errors.title} maxLength={180} placeholder="Ex.: Ligar para o síndico do Lafayette" />
        <Choice label="Tipo" options={TYPES} value={type} onChange={setType} />
        <Field label="Dia" value={date} onChangeText={setDate} mask="date" error={errors.date} />
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
        <Field label="Termina às (opcional)" value={end} onChangeText={setEnd} mask="time" error={errors.end} />
        {me?.can_see_team && team.length > 0 ? (
          <>
            <Choice label="Para quem" options={people} value={assignee ?? me.id} onChange={(v) => setAssignee(v === me.id ? null : v)} />
            {errors.assigned_to ? <Text style={{ color: colors.danger, fontFamily: fonts.medium }}>{errors.assigned_to}</Text> : null}
          </>
        ) : (
          <Body muted>A tarefa entra no seu dia. Para marcar para outra pessoa, peça a permissão "Ver o dia da equipe" no ERP.</Body>
        )}
        <Field label="Detalhes (opcional)" value={description} onChangeText={setDescription} multiline maxLength={2000} />
        {errors.ticket_id ? <Text style={{ color: colors.danger, fontFamily: fonts.medium }}>{errors.ticket_id}</Text> : null}
        <Button title="Salvar tarefa" onPress={save} busy={busy} />
        {ticketId || title ? <Button title="Limpar" variant="secondary" onPress={reset} /> : null}
      </Card>
    </Screen>
  );
}
