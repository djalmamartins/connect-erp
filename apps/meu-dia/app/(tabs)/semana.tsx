import Ionicons from '@expo/vector-icons/Ionicons';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../../src/auth/AuthContext';
import { usePerson } from '../../src/auth/PersonContext';
import { addDays, eventTone, longDay, overdueItems, shortDay, stripTone } from '../../src/lib/day';
import { eventActions } from '../../src/lib/eventActions';
import { useDay } from '../../src/lib/useDay';
import { colors, fonts, radius, space } from '../../src/theme';
import { Card, ErrorBox, Loading, Screen } from '../../src/ui/kit';
import { OfflineBanner } from '../../src/ui/OfflineBanner';
import { EVENT_TONE } from '../../src/ui/tones';

/**
 * Semana: de segunda a domingo, com os compromissos de cada dia nas cores de Hoje (ADR 0665). Tocar no compromisso abre o
 * mesmo menu de Hoje (concluir, cancelar, adiar; ADR 0671); tocar no resto do dia abre o dia na aba Hoje.
 */
export default function Week() {
  const { api, me } = useAuth();
  const { person } = usePerson();
  const [anchor, setAnchor] = useState<string | undefined>(undefined);
  const personId = person && person.id !== me?.id ? person.id : null;
  // Sem internet, mostra a cópia da semana guardada no aparelho, a mesma de Hoje (ADR 0673).
  const { day: week, staleAt } = useDay(anchor, personId);
  useFocusEffect(
    useCallback(() => {
      void week.reload();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []),
  );

  if (week.loading && !week.data) return <Loading />;
  const d = week.data;
  const late = d ? overdueItems(d) : [];
  const first = d?.week[0]?.date;
  const last = d?.week[6]?.date;

  return (
    <Screen
      tab
      title="Semana"
      eyebrow={first && last ? `${shortDay(first)} a ${shortDay(last)}` : undefined}
      subtitle={personId && d ? `Dia de ${d.person.name}` : undefined}
      refreshing={week.refreshing}
      onRefresh={week.reload}
    >
      {week.error && !d ? <ErrorBox error={week.error} onRetry={week.reload} /> : null}
      {staleAt && d ? <OfflineBanner savedAt={staleAt} /> : null}
      {d ? (
        <>
          <View style={styles.nav}>
            <NavButton icon="chevron-back" label="Semana anterior" onPress={() => setAnchor(addDays(d.date, -7))} />
            <Pressable onPress={() => setAnchor(undefined)} accessibilityRole="button" style={styles.navToday}>
              <Text style={styles.navTodayText}>Esta semana</Text>
            </Pressable>
            <NavButton icon="chevron-forward" label="Próxima semana" onPress={() => setAnchor(addDays(d.date, 7))} />
          </View>
          {d.week.map((day) => {
            const events = d.week_events.filter((e) => e.starts_at.slice(0, 10) === day.date && e.status !== 'cancelled');
            const tone = stripTone(day, d.today, late);
            return (
              <Card
                key={day.date}
                onPress={() => router.navigate({ pathname: '/', params: { date: day.date } })}
                accessibilityLabel={`${longDay(day.date)}, ${events.length} compromissos`}
                style={[day.today && styles.today, tone === 'late' && styles.late]}
              >
                <View style={styles.head}>
                  <Text style={[styles.dayTitle, tone === 'late' && { color: colors.danger }]}>
                    {longDay(day.date)}
                    {day.today ? ' · hoje' : ''}
                  </Text>
                  {day.count > 0 ? <Text style={styles.count}>{day.count}</Text> : null}
                </View>
                {events.length === 0 ? <Text style={styles.free}>Livre</Text> : null}
                {events.map((e) => {
                  const tone = eventTone(e, d.today);
                  const t = EVENT_TONE[tone];
                  const done = tone === 'done';
                  return (
                    <Pressable
                      key={e.id}
                      onPress={() => void eventActions(api, e, d.today, week.reload)}
                      style={({ pressed }) => [styles.event, { borderLeftColor: t.bar }, pressed && { opacity: 0.7 }]}
                      accessibilityRole="button"
                      accessibilityLabel={`${e.start}, ${e.title}${done ? ', concluída' : tone === 'late' ? ', atrasada' : ''}. Toque para concluir ou adiar`}
                    >
                      <Text style={[styles.time, tone === 'late' && { color: colors.danger }]}>{e.start}</Text>
                      <Text style={[styles.eventTitle, done && styles.done, tone === 'late' && { color: colors.danger }]} numberOfLines={1}>
                        {e.title}
                        {e.condominium ? ` · ${e.condominium}` : ''}
                      </Text>
                      {done ? <Ionicons name="checkmark-circle" size={16} color={colors.success} /> : tone === 'late' ? <Ionicons name="alert-circle" size={16} color={colors.danger} /> : null}
                    </Pressable>
                  );
                })}
              </Card>
            );
          })}
        </>
      ) : null}
    </Screen>
  );
}

function NavButton({ icon, label, onPress }: { icon: 'chevron-back' | 'chevron-forward'; label: string; onPress(): void }) {
  return (
    <Pressable onPress={onPress} accessibilityRole="button" accessibilityLabel={label} style={styles.navBtn} hitSlop={6}>
      <Ionicons name={icon} size={20} color={colors.brandStrong} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  nav: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  navBtn: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center' },
  navToday: { paddingHorizontal: space[4], paddingVertical: space[2], borderRadius: radius.pill, backgroundColor: colors.brandSoft },
  navTodayText: { fontFamily: fonts.semibold, color: colors.brandStrong },
  today: { borderColor: colors.brand, borderWidth: 1.5 },
  late: { borderColor: colors.danger, backgroundColor: colors.dangerSoft },
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: space[1] },
  dayTitle: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  count: { fontFamily: fonts.bold, fontSize: 13, color: colors.brandStrong, backgroundColor: colors.brandSoft, paddingHorizontal: space[2], paddingVertical: 2, borderRadius: radius.pill, overflow: 'hidden' },
  free: { fontFamily: fonts.regular, fontSize: 13, color: colors.faint },
  event: { flexDirection: 'row', alignItems: 'center', gap: space[2], minHeight: 40, paddingVertical: 6, paddingLeft: space[2], borderLeftWidth: 3, borderRadius: 4, marginVertical: 2 },
  time: { fontFamily: fonts.semibold, fontSize: 13, color: colors.brandStrong, width: 44 },
  eventTitle: { flex: 1, fontFamily: fonts.regular, fontSize: 14, color: colors.text },
  done: { color: colors.muted, textDecorationLine: 'line-through' },
});
