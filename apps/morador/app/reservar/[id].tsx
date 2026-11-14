import Ionicons from '@expo/vector-icons/Ionicons';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import { areaIcon } from '../../src/lib/areas';
import { busyOn, dayState, monthGrid, shiftMonth, type DayState } from '../../src/lib/calendar';
import { date, MONTHS, money, parseTime, today } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Button, Card, ErrorBox, Field, Loading, Row, Screen } from '../../src/ui/kit';

const WEEK = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];

/**
 * Reserva pelo calendário do mês: o morador vê os dias livres, os já tomados e os fora do prazo
 * da área, escolhe o dia e o horário. Os horários ocupados vêm sem dizer quem reservou.
 */
export default function Reservar() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const areaId = Number(id);
  const { api, residentId } = useAuth();
  const areas = useResource(() => api.commonAreas(), [residentId]);
  const area = areas.data?.find((a) => a.id === areaId) ?? null;
  const now = today();
  const [month, setMonth] = useState(now.slice(0, 7));
  const availability = useResource(() => api.areaAvailability(areaId, month), [residentId, areaId, month]);
  const busy = availability.data?.busy ?? [];
  const [day, setDay] = useState<string | null>(null);
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busyAction, setBusyAction] = useState(false);

  useEffect(() => {
    if (area) {
      setStart((v) => v || area.opening_time || '');
      setEnd((v) => v || area.closing_time || '');
    }
  }, [area]);

  const areaWindow = { min_advance_days: area?.min_advance_days ?? null, max_advance_days: area?.max_advance_days ?? null };
  const weeks = useMemo(() => monthGrid(month), [month]);
  const [y, m] = month.split('-').map(Number);
  const state = day ? dayState(day, now, areaWindow, busy) : null;
  const taken = day ? busyOn(day, busy) : [];

  async function book() {
    if (!day || !area) return;
    const e: Record<string, string> = {};
    const s = start ? parseTime(start) : null;
    const f = end ? parseTime(end) : null;
    if (start && !s) e.starts_time = 'Use HH:MM.';
    if (end && !f) e.ends_time = 'Use HH:MM.';
    setErrors(e);
    if (Object.keys(e).length > 0) return;
    setBusyAction(true);
    try {
      await api.createReservation({ common_area_id: area.id, reserved_on: day, ...(s ? { starts_time: s } : {}), ...(f ? { ends_time: f } : {}) });
      Alert.alert(
        'Pedido enviado para o síndico',
        `Seu pedido de ${area.name} para ${date(day)} foi enviado para avaliação do síndico. Avisamos aqui no app quando for aprovado.`,
      );
      router.back();
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.fields);
        Alert.alert('Reserva', err.message);
        await availability.reload();
      }
    } finally {
      setBusyAction(false);
    }
  }

  async function waitlist() {
    if (!day || !area) return;
    setBusyAction(true);
    try {
      await api.joinWaitlist({ common_area_id: area.id, desired_on: day });
      Alert.alert('Lista de espera', 'Pronto! Avisamos se a data ficar livre.');
      router.back();
    } catch (err) {
      Alert.alert('Lista de espera', err instanceof ApiError ? err.message : 'Não foi possível entrar na lista.');
    } finally {
      setBusyAction(false);
    }
  }

  if (areas.loading) return <Loading />;
  if (areas.error) return <ErrorBox error={areas.error} onRetry={areas.reload} />;

  return (
    <Screen title={area?.name ?? 'Reserva'} back refreshing={availability.refreshing} onRefresh={availability.reload}>
      {area ? (
        <Card style={styles.head}>
          <View style={styles.icon}>
            <Ionicons name={areaIcon(area.name)} size={24} color={colors.brandStrong} />
          </View>
          <View style={{ flex: 1, gap: 2 }}>
            <Text style={styles.price}>{area.fee_cents ? `${money(area.fee_cents)} · vem no boleto` : 'Gratuito'}</Text>
            <Body muted>
              {[area.capacity ? `Até ${area.capacity} pessoas` : null, area.opening_time && area.closing_time ? `${area.opening_time} às ${area.closing_time}` : null, 'com aprovação do síndico']
                .filter(Boolean)
                .join(' · ')}
            </Body>
          </View>
        </Card>
      ) : null}

      <Card>
        <View style={styles.monthBar}>
          <Pressable onPress={() => setMonth(shiftMonth(month, -1))} disabled={month <= now.slice(0, 7)} accessibilityRole="button" accessibilityLabel="Mês anterior" hitSlop={10}>
            <Ionicons name="chevron-back" size={22} color={month <= now.slice(0, 7) ? colors.border : colors.text} />
          </Pressable>
          <Text style={styles.monthTitle}>
            {MONTHS[m - 1].replace(/^./, (c) => c.toUpperCase())} de {y}
          </Text>
          <Pressable onPress={() => setMonth(shiftMonth(month, 1))} accessibilityRole="button" accessibilityLabel="Próximo mês" hitSlop={10}>
            <Ionicons name="chevron-forward" size={22} color={colors.text} />
          </Pressable>
        </View>
        <View style={styles.week}>
          {WEEK.map((d, i) => (
            <Text key={i} style={styles.weekDay}>
              {d}
            </Text>
          ))}
        </View>
        {availability.error ? <ErrorBox error={availability.error} onRetry={availability.reload} /> : null}
        {weeks.map((w, i) => (
          <View key={i} style={styles.week}>
            {w.map((d, j) =>
              d ? (
                <DayCell key={d} day={d} state={dayState(d, now, areaWindow, busy)} selected={d === day} onPress={() => setDay(d)} />
              ) : (
                <View key={`e${j}`} style={styles.cell} />
              ),
            )}
          </View>
        ))}
        <View style={styles.legend}>
          <Legend color={colors.surface} border={colors.border} text="Livre" />
          <Legend color={colors.warningSoft} border="#e0a100" text="Parte do dia" />
          <Legend color={colors.dangerSoft} border={colors.danger} text="Ocupado" />
          <Legend color={colors.brand} border={colors.brand} text="Escolhido" />
        </View>
      </Card>

      {day && state ? (
        <Card>
          <Text style={styles.dayTitle}>{date(day)}</Text>
          {state === 'past' || state === 'outside' ? (
            <Body muted>
              {state === 'past'
                ? 'Esta data já passou.'
                : `Fora do prazo de reserva deste espaço${area?.min_advance_days ? ` (mínimo ${area.min_advance_days} dia(s) de antecedência)` : ''}${area?.max_advance_days ? ` (até ${area.max_advance_days} dias à frente)` : ''}.`}
            </Body>
          ) : (
            <>
              {taken.length > 0 ? (
                <View style={{ gap: 4 }}>
                  <Text style={styles.label}>Já reservado</Text>
                  {taken.map((t, i) => (
                    <Body key={i} muted>
                      {t.starts_time && t.ends_time ? `${t.starts_time} às ${t.ends_time}` : 'O dia inteiro'}
                    </Body>
                  ))}
                </View>
              ) : null}
              {state === 'full' ? (
                <Button title="Entrar na lista de espera" onPress={waitlist} busy={busyAction} />
              ) : (
                <>
                  <Row>
                    <View style={{ flex: 1 }}>
                      <Field label="Das" placeholder="HH:MM" value={start} onChangeText={setStart} keyboardType="numbers-and-punctuation" error={errors.starts_time} />
                    </View>
                    <View style={{ flex: 1 }}>
                      <Field label="Até" placeholder="HH:MM" value={end} onChangeText={setEnd} keyboardType="numbers-and-punctuation" error={errors.ends_time} />
                    </View>
                  </Row>
                  {errors.reserved_on ? <Text style={styles.error}>{errors.reserved_on}</Text> : null}
                  <Button title="Reservar" onPress={book} busy={busyAction} />
                  {state === 'partial' ? <Button title="Entrar na lista de espera" variant="secondary" onPress={waitlist} busy={busyAction} /> : null}
                </>
              )}
            </>
          )}
          {area?.rules ? <Body muted>{area.rules}</Body> : null}
        </Card>
      ) : (
        <Body muted style={{ textAlign: 'center' }}>
          Toque num dia para ver os horários.
        </Body>
      )}
    </Screen>
  );
}

const CELL_STYLE: Record<DayState, { bg: string; fg: string; border: string }> = {
  past: { bg: 'transparent', fg: colors.border, border: 'transparent' },
  outside: { bg: 'transparent', fg: colors.faint, border: 'transparent' },
  free: { bg: colors.surface, fg: colors.text, border: colors.border },
  partial: { bg: colors.warningSoft, fg: colors.warning, border: '#e0a100' },
  full: { bg: colors.dangerSoft, fg: colors.danger, border: colors.danger },
};

const STATE_LABEL: Record<DayState, string> = { past: 'passou', outside: 'fora do prazo', free: 'livre', partial: 'parte do dia ocupada', full: 'ocupado' };

function DayCell({ day, state, selected, onPress }: { day: string; state: DayState; selected: boolean; onPress: () => void }) {
  const c = CELL_STYLE[state];
  return (
    <Pressable
      onPress={onPress}
      disabled={state === 'past'}
      accessibilityRole="button"
      accessibilityLabel={`${date(day)}, ${STATE_LABEL[state]}`}
      accessibilityState={{ selected }}
      style={styles.cell}
    >
      <View style={[styles.dayCircle, { backgroundColor: selected ? colors.brand : c.bg, borderColor: selected ? colors.brand : c.border }]}>
        <Text style={[styles.dayText, { color: selected ? colors.onBrand : c.fg }]}>{Number(day.slice(8))}</Text>
      </View>
    </Pressable>
  );
}

function Legend({ color, border, text }: { color: string; border: string; text: string }) {
  return (
    <View style={styles.legendItem}>
      <View style={[styles.legendDot, { backgroundColor: color, borderColor: border }]} />
      <Text style={styles.legendText}>{text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  icon: { width: 48, height: 48, borderRadius: 14, backgroundColor: colors.brandSoft, alignItems: 'center', justifyContent: 'center' },
  price: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  monthBar: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: space[2] },
  monthTitle: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  week: { flexDirection: 'row' },
  weekDay: { flex: 1, textAlign: 'center', fontFamily: fonts.medium, fontSize: 12, color: colors.muted, paddingVertical: 6 },
  cell: { flex: 1, alignItems: 'center', paddingVertical: 3 },
  dayCircle: { width: 38, height: 38, borderRadius: 19, alignItems: 'center', justifyContent: 'center', borderWidth: 1 },
  dayText: { fontFamily: fonts.semibold, fontSize: 15 },
  legend: { flexDirection: 'row', flexWrap: 'wrap', gap: space[3], marginTop: space[3], justifyContent: 'center' },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  legendDot: { width: 14, height: 14, borderRadius: 7, borderWidth: 1 },
  legendText: { fontFamily: fonts.regular, fontSize: 12, color: colors.muted },
  dayTitle: { fontFamily: fonts.bold, fontSize: 18, color: colors.text },
  label: { fontFamily: fonts.semibold, fontSize: 14, color: colors.text },
  error: { fontFamily: fonts.medium, fontSize: 14, color: colors.danger },
});
