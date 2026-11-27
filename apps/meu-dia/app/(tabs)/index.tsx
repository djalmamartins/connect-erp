import Ionicons from '@expo/vector-icons/Ionicons';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { Day, DayEvent, EventStatus, OverdueItem, Ticket } from '../../src/api/team';
import { useAuth } from '../../src/auth/AuthContext';
import { usePerson } from '../../src/auth/PersonContext';
import { dayProgress, eventTone, postponeOptions, type EventTone, KIND_LABEL, lateLabel, longDay, overdueItems, relativeDay, shortDay, stripTone } from '../../src/lib/day';
import { syncReminders } from '../../src/lib/notify';
import { openInSac } from '../../src/lib/sac';
import { cacheKey, offline, savedLabel } from '../../src/lib/offline';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { dialog } from '../../src/ui/Dialog';
import { Body, Card, Empty, ErrorBox, IconRow, Loading, Screen, SectionTitle } from '../../src/ui/kit';

/**
 * Hoje: o dia de trabalho, igual à tela /meu-dia do ERP.
 * O que ficou para trás aparece em vermelho no topo de HOJE e continua aparecendo todo dia até ser fechado
 * (ADR 0658). Os dias passados com pendência ficam vermelhos na faixa de dias.
 */
export default function Today() {
  const { api, me } = useAuth();
  const { person } = usePerson();
  const params = useLocalSearchParams<{ date?: string }>();
  const [date, setDate] = useState<string | undefined>(typeof params.date === 'string' ? params.date : undefined);
  useEffect(() => {
    if (typeof params.date === 'string') setDate(params.date);
  }, [params.date]);

  const personId = person && person.id !== me?.id ? person.id : null;
  // Sem internet, mostra a última cópia deste dia guardada no aparelho (ADR 0669).
  const [staleAt, setStaleAt] = useState<string | null>(null);
  const day = useResource(async () => {
    const key = cacheKey(date, personId);
    try {
      const fresh = await api.day(date, personId);
      setStaleAt(null);
      if (me) void offline.save(me.id, key, fresh);
      return fresh;
    } catch (e) {
      if (me && e instanceof ApiError && (e.code === 'network' || e.code === 'timeout')) {
        const saved = await offline.load(me.id, key);
        if (saved) {
          setStaleAt(saved.savedAt);
          return saved.day;
        }
      }
      throw e;
    }
  }, [date, personId]);

  // Ao voltar para a aba (por exemplo, depois de criar uma tarefa), atualiza.
  useFocusEffect(
    useCallback(() => {
      void day.reload();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []),
  );

  // Os lembretes do aparelho seguem o dia de hoje da própria pessoa.
  useEffect(() => {
    const d = day.data;
    if (d && personId === null && d.date === d.today) void syncReminders(d);
  }, [day.data, personId]);

  if (day.loading && !day.data) return <Loading />;
  const d = day.data;
  const isToday = !!d && d.date === d.today;
  const title = d ? relativeDay(d.date, d.today) : 'Hoje';

  async function changeStatus(event: DayEvent) {
    const done = event.status === 'completed';
    const options: { label: string; status: EventStatus; danger?: boolean }[] =
      event.status === 'scheduled'
        ? [
            { label: 'Concluir', status: 'completed' },
            { label: 'Cancelar', status: 'cancelled', danger: true },
          ]
        : [{ label: done ? 'Reabrir' : 'Marcar de novo', status: 'scheduled' }];
    const canMove = event.status === 'scheduled';
    const choice = await dialog.choose({
      title: event.title,
      message: [event.start + (event.end ? `–${event.end}` : ''), event.condominium, event.protocol ? `Chamado ${event.protocol}` : null, event.description || null].filter(Boolean).join('\n'),
      options: [
        ...options.map((o) => ({ label: o.label, danger: o.danger, icon: o.status === 'completed' ? ('checkmark-circle-outline' as const) : o.status === 'cancelled' ? ('close-circle-outline' as const) : ('refresh' as const) })),
        ...(canMove ? [{ label: 'Adiar ou mudar o horário', icon: 'time-outline' as const }] : []),
      ],
    });
    if (choice === null) return;
    if (choice === options.length) {
      await postpone(event);
      return;
    }
    try {
      await api.setStatus(event.id, options[choice]!.status);
      dialog.toast(options[choice]!.status === 'completed' ? 'Concluída' : options[choice]!.status === 'cancelled' ? 'Cancelada' : 'Reaberta');
      await day.reload();
    } catch (e) {
      await dialog.notice({ title: 'Não deu certo', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
    }
  }

  /** Adiar (ADR 0668): atalhos rápidos ou outro dia e hora na tela de adiar. */
  async function postpone(event: DayEvent) {
    const today = d?.today ?? event.starts_at.slice(0, 10);
    const shortcuts = postponeOptions(event, today);
    const choice = await dialog.choose({
      title: 'Adiar para quando?',
      message: event.title,
      options: [...shortcuts.map((o) => ({ label: o.label, icon: 'arrow-forward-circle-outline' as const })), { label: 'Outro dia e hora', icon: 'calendar-outline' as const }],
    });
    if (choice === null) return;
    const pick = shortcuts[choice];
    if (!pick) {
      router.push({ pathname: '/adiar/[id]', params: { id: String(event.id), title: event.title, date: event.starts_at.slice(0, 10) < today ? today : event.starts_at.slice(0, 10), start: event.start } });
      return;
    }
    try {
      await api.moveEvent(event.id, pick.date, pick.start);
      dialog.toast(`Adiado para ${shortDay(pick.date)} às ${pick.start}`);
      await day.reload();
    } catch (e) {
      await dialog.notice({ title: 'Não deu para adiar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
    }
  }

  async function openLate(item: OverdueItem) {
    if (item.kind === 'event' && d) {
      const event = [...d.week_events, ...d.events].find((e) => e.id === item.id);
      if (event) return changeStatus(event);
      const ok = await dialog.confirm({ title: item.title, message: `${lateLabel(item.days_late)}. Marcar como concluída?`, confirm: 'Concluir', tone: 'info', undo: true });
      if (ok) {
        try {
          await api.setStatus(item.id, 'completed');
          dialog.toast('Concluída');
          await day.reload();
        } catch (e) {
          await dialog.notice({ title: 'Não deu certo', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
        }
      }
      return;
    }
    if (item.kind === 'ticket' || item.ticket_id) {
      const choice = await dialog.choose({
        title: item.title,
        message: `${item.protocol ? `Chamado ${item.protocol}. ` : ''}${lateLabel(item.days_late)}. O chamado fecha no SAC; aqui você pode marcar um horário para atender.`,
        options: [
          { label: 'Abrir no app do SAC', icon: 'chatbubbles-outline' },
          { label: 'Marcar horário para atender', icon: 'time-outline' },
        ],
      });
      if (choice === 0) await openInSac(item.ticket_id ?? item.id);
      if (choice === 1) schedule({ id: item.ticket_id ?? item.id, subject: item.title } as Ticket);
      return;
    }
    await dialog.notice({ title: item.title, message: `${KIND_LABEL[item.kind]} · ${lateLabel(item.days_late)}. Resolva pelo ERP; quando fechar, sai daqui sozinho.` });
  }

  async function openTicket(ticket: Ticket) {
    const choice = await dialog.choose({
      title: ticket.subject,
      message: [ticket.protocol, ticket.status_label, ticket.condominium].filter(Boolean).join(' · '),
      options: [
        { label: 'Abrir no app do SAC', icon: 'chatbubbles-outline' },
        { label: 'Marcar horário para atender', icon: 'time-outline' },
      ],
    });
    if (choice === 0) await openInSac(ticket.id);
    if (choice === 1) schedule(ticket);
  }

  function schedule(ticket: Pick<Ticket, 'id' | 'subject'>) {
    router.navigate({ pathname: '/nova', params: { ticket_id: String(ticket.id), title: ticket.subject, type: 'support', for: personId ? String(personId) : '' } });
  }

  return (
    <Screen
      tab
      title={title}
      eyebrow={d ? longDay(d.date) : undefined}
      subtitle={personId && d ? `Dia de ${d.person.name}` : undefined}
      refreshing={day.refreshing}
      onRefresh={day.reload}
      right={
        d && !isToday ? (
          <Pressable onPress={() => setDate(d.today)} style={styles.todayBtn} accessibilityRole="button" accessibilityLabel="Voltar para hoje">
            <Text style={styles.todayText}>Hoje</Text>
          </Pressable>
        ) : null
      }
    >
      {day.error && !d ? <ErrorBox error={day.error} onRetry={day.reload} /> : null}
      {staleAt && d ? (
        <View style={styles.offline} accessibilityRole="alert">
          <Ionicons name="cloud-offline-outline" size={18} color={colors.warning} />
          <Text style={styles.offlineText}>Sem conexão. Mostrando os dados {savedLabel(staleAt, new Date())}. Puxe para atualizar.</Text>
        </View>
      ) : null}
      {d ? <DayBody d={d} isToday={isToday} onPick={setDate} onEvent={changeStatus} onLate={openLate} onSchedule={openTicket} /> : null}
    </Screen>
  );
}

function DayBody({
  d,
  isToday,
  onPick,
  onEvent,
  onLate,
  onSchedule,
}: {
  d: Day;
  isToday: boolean;
  onPick(date: string): void;
  onEvent(e: DayEvent): void;
  onLate(i: OverdueItem): void;
  onSchedule(t: Ticket): void;
}) {
  const late = overdueItems(d);
  const events = d.events.filter((e) => e.status !== 'cancelled');
  return (
    <>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.strip}>
        {d.strip.map((s) => {
          const tone = stripTone(s, d.today, late);
          return (
            <Pressable
              key={s.date}
              onPress={() => onPick(s.date)}
              accessibilityRole="button"
              accessibilityLabel={`${shortDay(s.date)}${s.count ? `, ${s.count} itens` : ''}${tone === 'late' ? ', com tarefa atrasada' : ''}`}
              accessibilityState={{ selected: s.selected }}
              style={[styles.dayChip, s.selected && styles.dayChipOn, tone === 'late' && !s.selected && styles.dayChipLate]}
            >
              <Text style={[styles.dayWeek, s.selected && styles.onBrand, tone === 'late' && !s.selected && styles.lateInk]}>{s.weekday}</Text>
              <Text style={[styles.dayNum, s.selected && styles.onBrand, tone === 'late' && !s.selected && styles.lateInk]}>{s.day}</Text>
              <View style={[styles.dot, { backgroundColor: tone === 'late' ? colors.danger : tone === 'busy' ? (s.selected ? colors.onBrand : colors.brand) : 'transparent' }]} />
              {s.today ? <Text style={[styles.todayMark, s.selected && styles.onBrand]}>hoje</Text> : null}
            </Pressable>
          );
        })}
      </ScrollView>

      {isToday && late.length > 0 ? (
        <View style={styles.lateBox} accessibilityRole="summary">
          <View style={styles.lateHead}>
            <Ionicons name="alert-circle" size={22} color={colors.danger} />
            <Text style={styles.lateTitle}>
              {late.length === 1 ? '1 tarefa atrasada' : `${late.length} tarefas atrasadas`}
            </Text>
          </View>
          <Body style={styles.lateHint}>Ficaram para trás e continuam aqui todo dia até serem fechadas.</Body>
          {late.map((item) => (
            <Pressable key={`${item.kind}-${item.id}`} onPress={() => onLate(item)} style={({ pressed }) => [styles.lateRow, pressed && { opacity: 0.7 }]} accessibilityRole="button">
              <View style={{ flex: 1, gap: 2 }}>
                <Text style={styles.lateItem} numberOfLines={2}>
                  {item.title}
                </Text>
                <Text style={styles.lateMeta}>
                  {KIND_LABEL[item.kind]} · {lateLabel(item.days_late)} (era para {shortDay(item.due_on)}){item.condominium ? ` · ${item.condominium}` : ''}
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={18} color={colors.danger} />
            </Pressable>
          ))}
        </View>
      ) : null}

      {isToday ? <Progress d={d} late={late.length} /> : null}

      <View style={styles.stats}>
        <Stat label="Compromissos" value={d.summary.commitments} />
        <Stat label="Feitos" value={d.summary.done} />
        <Stat label="Para agendar" value={d.summary.to_schedule} />
        <Stat label="Atrasadas" value={late.length} danger={late.length > 0} />
      </View>

      <SectionTitle>Agenda do dia</SectionTitle>
      {events.length === 0 ? <Empty text="Nada marcado para este dia." /> : null}
      {events.map((e) => (
        <EventCard key={e.id} e={e} tone={eventTone(e, d.today)} onPress={() => onEvent(e)} />
      ))}

      {d.day_deadlines.length > 0 ? (
        <>
          <SectionTitle>Prazos de chamados neste dia</SectionTitle>
          {d.day_deadlines.map((t) => (
            <TicketRow key={t.id} t={t} onPress={() => onSchedule(t)} />
          ))}
        </>
      ) : null}

      {d.day_visits.length > 0 ? (
        <>
          <SectionTitle>Vistorias</SectionTitle>
          {d.day_visits.map((v) => (
            <IconRow
              key={v.plan_id}
              icon="clipboard-outline"
              tint={v.overdue ? colors.danger : colors.info}
              bg={v.overdue ? colors.dangerSoft : colors.infoSoft}
              title={`${v.template} · ${v.condominium}`}
              subtitle={[v.frequency_label, v.overdue ? `Vencida desde ${shortDay(v.due_on)}` : null, v.address].filter(Boolean).join(' · ')}
              onPress={() => router.push({ pathname: '/visita/[id]', params: { id: String(v.condominium_id), template: v.template, template_id: v.template_id ? String(v.template_id) : '', open: v.open_inspection_id ? String(v.open_inspection_id) : '' } })}
              accessibilityLabel={`${v.template} em ${v.condominium}. Toque para ver o painel da visita`}
            />
          ))}
        </>
      ) : null}

      {isToday ? (
        <>
          <SectionTitle>Para agendar</SectionTitle>
          {d.to_schedule.length === 0 ? <Empty text="Nenhum chamado esperando horário." /> : null}
          {d.to_schedule.map((t) => (
            <TicketRow key={t.id} t={t} onPress={() => onSchedule(t)} />
          ))}
          {d.waiting.length > 0 ? (
            <>
              <SectionTitle>Aguardando o cliente</SectionTitle>
              {d.waiting.map((t) => (
                <TicketRow key={t.id} t={t} onPress={() => void openInSac(t.id)} />
              ))}
            </>
          ) : null}
          {d.unassigned && d.unassigned.length > 0 ? (
            <>
              <SectionTitle>Chamados do SAC sem responsável</SectionTitle>
              {d.unassigned.map((t) => (
                <TicketRow key={t.id} t={t} onPress={() => void openInSac(t.id)} />
              ))}
            </>
          ) : null}
          {d.deadlines.length > 0 ? (
            <>
              <SectionTitle>Vencimentos dos condomínios</SectionTitle>
              {d.deadlines.slice(0, 10).map((x) => (
                <IconRow key={x.id} icon="document-text-outline" tint={x.days < 0 ? colors.danger : colors.warning} bg={x.days < 0 ? colors.dangerSoft : colors.warningSoft} title={x.title} subtitle={[x.label, x.condominium].filter(Boolean).join(' · ')} />
              ))}
            </>
          ) : null}
        </>
      ) : null}
    </>
  );
}

/** Topo verde-escuro de Hoje: N de M feitos, barra dourada, atrasadas e o próximo compromisso. */
function Progress({ d, late }: { d: Day; late: number }) {
  const now = new Date();
  const hhmm = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
  const p = dayProgress(d, late, hhmm);
  if (p.total === 0 && late === 0) return null;
  return (
    <View style={styles.progress} accessibilityRole="summary" accessibilityLabel={`${p.done} de ${p.total} feitos, ${late} atrasadas`}>
      <View style={styles.progressHead}>
        <Text style={styles.progressTitle}>
          {p.done} de {p.total} {p.total === 1 ? 'feito' : 'feitos'}
        </Text>
        {late > 0 ? <Text style={styles.progressLate}>{late === 1 ? '1 atrasada' : `${late} atrasadas`}</Text> : null}
      </View>
      <View style={styles.track}>
        <View style={[styles.fill, { width: `${p.percent}%` }]} />
      </View>
      <Text style={styles.progressNext} numberOfLines={1}>
        {p.next ? `Próximo: ${p.next.start} · ${p.next.title}` : p.total > 0 && p.done === p.total ? 'Tudo feito por hoje.' : 'Nenhum compromisso pela frente hoje.'}
      </Text>
    </View>
  );
}

const TONE: Record<EventTone, { bar: string; icon: string; bg: string }> = {
  done: { bar: colors.success, icon: colors.success, bg: colors.successSoft },
  late: { bar: colors.danger, icon: colors.danger, bg: colors.dangerSoft },
  meeting: { bar: colors.info, icon: colors.info, bg: colors.infoSoft },
  task: { bar: colors.brand, icon: colors.brandStrong, bg: colors.brandSoft },
  deadline: { bar: colors.warning, icon: colors.warning, bg: colors.warningSoft },
  support: { bar: colors.accent, icon: colors.warning, bg: colors.accentSoft },
};

/** Compromisso com a hora à esquerda e a borda colorida pelo tipo (verde feito, vermelho atrasado). */
function EventCard({ e, tone, onPress }: { e: DayEvent; tone: EventTone; onPress: () => void }) {
  const t = TONE[tone];
  const done = tone === 'done';
  const icon = done ? 'checkmark-circle' : tone === 'late' ? 'alert-circle' : e.type === 'support' ? 'chatbubbles-outline' : e.type === 'deadline' ? 'flag-outline' : e.type === 'task' ? 'checkbox-outline' : 'people-outline';
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.event, { borderLeftColor: t.bar }, pressed && { opacity: 0.75 }]}
      accessibilityRole="button"
      accessibilityLabel={`${e.start}, ${e.title}${done ? ', concluída' : tone === 'late' ? ', atrasada' : ''}. Toque para concluir ou cancelar`}
    >
      <View style={styles.eventTime}>
        <Text style={[styles.eventStart, done && styles.eventDoneText]}>{e.start}</Text>
        {e.end ? <Text style={styles.eventEnd}>{e.end}</Text> : null}
      </View>
      <View style={{ flex: 1, gap: 2 }}>
        <Text style={[styles.eventTitle, done && styles.eventDoneText]} numberOfLines={2}>
          {e.title}
        </Text>
        <Text style={[styles.eventMeta, tone === 'late' && { color: colors.danger }]} numberOfLines={2}>
          {[e.type_label, e.condominium, e.protocol, done ? 'Concluída' : tone === 'late' ? 'Atrasada' : null].filter(Boolean).join(' · ')}
        </Text>
      </View>
      <View style={[styles.eventIcon, { backgroundColor: t.bg }]}>
        <Ionicons name={icon} size={18} color={t.icon} />
      </View>
    </Pressable>
  );
}

function TicketRow({ t, onPress }: { t: Ticket; onPress?: () => void }) {
  const red = t.overdue || t.priority === 'urgent';
  return (
    <IconRow
      icon="chatbubble-ellipses-outline"
      tint={red ? colors.danger : colors.brandStrong}
      bg={red ? colors.dangerSoft : colors.brandSoft}
      title={t.subject}
      subtitle={[t.protocol, t.tag, t.condominium, t.overdue ? `Prazo passou (${shortDay(t.due_on)})` : t.due_on ? `Prazo ${shortDay(t.due_on)}` : null].filter(Boolean).join(' · ')}
      onPress={onPress}
      accessibilityLabel={onPress ? `${t.subject}. Toque para abrir` : t.subject}
    />
  );
}

function Stat({ label, value, danger }: { label: string; value: number; danger?: boolean }) {
  return (
    <Card style={[styles.stat, danger && { backgroundColor: colors.dangerSoft, borderColor: colors.dangerSoft }]}>
      <Text style={[styles.statValue, danger && { color: colors.danger }]}>{value}</Text>
      <Text style={[styles.statLabel, danger && { color: colors.danger }]}>{label}</Text>
    </Card>
  );
}

const styles = StyleSheet.create({
  strip: { gap: space[2], paddingVertical: space[1] },
  dayChip: { width: 52, paddingVertical: space[2], borderRadius: radius.md, alignItems: 'center', backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, gap: 2 },
  dayChipOn: { backgroundColor: colors.brand, borderColor: colors.brand },
  dayChipLate: { backgroundColor: colors.dangerSoft, borderColor: colors.danger },
  dayWeek: { fontFamily: fonts.medium, fontSize: 12, color: colors.muted, textTransform: 'capitalize' },
  dayNum: { fontFamily: fonts.bold, fontSize: 18, color: colors.text },
  dot: { width: 6, height: 6, borderRadius: 3 },
  todayMark: { fontFamily: fonts.semibold, fontSize: 10, color: colors.brandStrong },
  onBrand: { color: colors.onBrand },
  lateInk: { color: colors.danger },
  lateBox: { backgroundColor: colors.dangerSoft, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.danger, padding: space[4], gap: space[2] },
  lateHead: { flexDirection: 'row', alignItems: 'center', gap: space[2] },
  lateTitle: { fontFamily: fonts.bold, fontSize: 17, color: colors.danger },
  lateHint: { color: colors.danger, fontSize: 13 },
  lateRow: { flexDirection: 'row', alignItems: 'center', gap: space[2], backgroundColor: colors.surface, borderRadius: radius.md, padding: space[3] },
  lateItem: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  lateMeta: { fontFamily: fonts.regular, fontSize: 12.5, color: colors.danger },
  stats: { flexDirection: 'row', gap: space[2] },
  stat: { flex: 1, alignItems: 'center', paddingVertical: space[3], paddingHorizontal: space[1] },
  statValue: { fontFamily: fonts.bold, fontSize: 20, color: colors.text },
  statLabel: { fontFamily: fonts.medium, fontSize: 11, color: colors.muted, textAlign: 'center' },
  progress: { backgroundColor: colors.sidebar, borderRadius: radius.xl, padding: space[4], gap: space[2], borderBottomWidth: 3, borderBottomColor: colors.accent },
  progressHead: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between' },
  progressTitle: { fontFamily: fonts.bold, fontSize: 20, color: colors.onBrand },
  progressLate: { fontFamily: fonts.semibold, fontSize: 14, color: '#ff8a8f' },
  track: { height: 8, borderRadius: radius.pill, backgroundColor: 'rgba(255,255,255,0.15)', overflow: 'hidden' },
  fill: { height: 8, borderRadius: radius.pill, backgroundColor: colors.accent },
  progressNext: { fontFamily: fonts.regular, fontSize: 13, color: colors.sidebarInk },
  event: { flexDirection: 'row', alignItems: 'center', gap: space[3], backgroundColor: colors.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.border, borderLeftWidth: 4, paddingVertical: space[3], paddingHorizontal: space[3], minHeight: 56 },
  eventTime: { width: 46, alignItems: 'flex-start' },
  eventStart: { fontFamily: fonts.bold, fontSize: 15, color: colors.text },
  eventEnd: { fontFamily: fonts.regular, fontSize: 12, color: colors.muted },
  eventTitle: { fontFamily: fonts.semibold, fontSize: 15.5, color: colors.text },
  eventMeta: { fontFamily: fonts.regular, fontSize: 12.5, color: colors.muted },
  eventDoneText: { color: colors.muted, textDecorationLine: 'line-through' },
  eventIcon: { width: 34, height: 34, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  offline: { flexDirection: 'row', alignItems: 'center', gap: space[2], backgroundColor: colors.warningSoft, borderRadius: radius.md, padding: space[3] },
  offlineText: { flex: 1, fontFamily: fonts.medium, fontSize: 13, color: colors.warning },
  todayBtn: { paddingHorizontal: space[3], paddingVertical: space[2], borderRadius: radius.pill, backgroundColor: colors.brandSoft },
  todayText: { fontFamily: fonts.semibold, color: colors.brandStrong },
});
