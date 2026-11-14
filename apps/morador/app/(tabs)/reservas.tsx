import Ionicons from '@expo/vector-icons/Ionicons';
import { router } from 'expo-router';
import { useState, type ComponentProps } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { CommonArea, Reservation } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { areaIcon } from '../../src/lib/areas';
import { confirm } from '../../src/lib/confirm';
import { date, money, today } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Button, Card, Empty, ErrorBox, Loading, Screen, SectionTitle } from '../../src/ui/kit';
import { dialog } from '../../src/ui/Dialog';

type IconName = ComponentProps<typeof Ionicons>['name'];
type Tab = 'new' | 'mine';
type Filter = 'all' | 'free' | 'paid';

const STATUS: Record<string, { label: string; color: string }> = {
  active: { label: 'Aguardando o síndico', color: '#e0a100' },
  confirmed: { label: 'Aprovada', color: colors.success },
  inactive: { label: 'Cancelada', color: colors.danger },
};

export default function Reservas() {
  const { api, residentId } = useAuth();
  const areas = useResource(() => api.commonAreas(), [residentId]);
  const list = useResource(() => api.reservations(), [residentId]);
  const [tab, setTab] = useState<Tab>('new');
  const [filter, setFilter] = useState<Filter>('all');
  const mine = list.data?.reservations ?? [];
  const waiting = list.data?.waitlist ?? [];
  const upcoming = mine.filter((r) => r.status !== 'inactive' && r.reserved_on >= today()).length;
  const shown = (areas.data ?? []).filter((a) => filter === 'all' || (filter === 'paid' ? (a.fee_cents ?? 0) > 0 : !a.fee_cents));

  async function cancel(id: number) {
    if (!(await confirm('Cancelar reserva', 'Quer mesmo cancelar esta reserva?', 'Cancelar reserva'))) return;
    try {
      await api.cancelReservation(id);
      await list.reload();
    } catch (err) {
      void dialog.notice({ title: 'Não deu para cancelar', message: err instanceof ApiError ? err.message : 'Tente de novo em instantes.', tone: 'error' });
    }
  }

  const reload = async () => {
    await Promise.all([areas.reload(), list.reload()]);
  };

  return (
    <Screen title="Reservas" tab refreshing={areas.refreshing || list.refreshing} onRefresh={reload}>
      <View style={styles.segment} accessibilityRole="tablist">
        {(
          [
            ['new', 'Nova reserva'],
            ['mine', upcoming > 0 ? `Minhas reservas (${upcoming})` : 'Minhas reservas'],
          ] as [Tab, string][]
        ).map(([key, label]) => (
          <Pressable
            key={key}
            onPress={() => setTab(key)}
            accessibilityRole="tab"
            accessibilityState={{ selected: tab === key }}
            style={[styles.segmentItem, tab === key && styles.segmentActive]}
          >
            <Text style={[styles.segmentText, tab === key && styles.segmentTextActive]}>{label}</Text>
          </Pressable>
        ))}
      </View>

      {tab === 'new' ? (
        <>
          <View style={styles.chips}>
            {(
              [
                ['all', 'Todos', 'apps'],
                ['free', 'Gratuitos', 'gift-outline'],
                ['paid', 'Com taxa', 'cash-outline'],
              ] as [Filter, string, IconName][]
            ).map(([key, label, icon]) => (
              <Pressable
                key={key}
                onPress={() => setFilter(key)}
                accessibilityRole="button"
                accessibilityState={{ selected: filter === key }}
                style={[styles.chip, filter === key && styles.chipActive]}
              >
                <Ionicons name={icon} size={14} color={filter === key ? colors.onBrand : colors.muted} />
                <Text style={[styles.chipText, filter === key && { color: colors.onBrand }]}>{label}</Text>
              </Pressable>
            ))}
          </View>
          {areas.loading ? <Loading /> : null}
          {areas.error ? <ErrorBox error={areas.error} onRetry={areas.reload} /> : null}
          {areas.data && areas.data.length === 0 ? <Empty text="Nenhum espaço disponível para reserva no momento." /> : null}
          {areas.data && areas.data.length > 0 && shown.length === 0 ? <Empty text="Nenhum espaço com esse filtro." /> : null}
          {shown.map((a) => (
            <AreaCard key={a.id} area={a} onPress={() => router.push({ pathname: '/reservar/[id]', params: { id: String(a.id) } })} />
          ))}
        </>
      ) : (
        <>
          <View style={styles.legend}>
            {['confirmed', 'active', 'inactive'].map((k) => (
              <View key={k} style={styles.legendItem}>
                <View style={[styles.dot, { backgroundColor: STATUS[k].color }]} />
                <Text style={styles.legendText}>{STATUS[k].label}</Text>
              </View>
            ))}
          </View>
          {list.loading ? <Loading /> : null}
          {list.error ? <ErrorBox error={list.error} onRetry={list.reload} /> : null}
          {list.data && mine.length === 0 ? <Empty text="Você ainda não tem reservas. Escolha um espaço em Nova reserva." /> : null}
          {mine.map((r) => (
            <BookingCard key={r.id} reservation={r} onCancel={() => cancel(r.id)} />
          ))}
          {waiting.length > 0 ? <SectionTitle>Lista de espera</SectionTitle> : null}
          {waiting.map((w) => (
            <Card key={`w${w.id}`}>
              <Text style={styles.areaName}>{w.area}</Text>
              <Body muted>Esperando {date(w.desired_on)}. Avisamos se a data ficar livre.</Body>
            </Card>
          ))}
        </>
      )}
    </Screen>
  );
}

function AreaCard({ area, onPress }: { area: CommonArea; onPress: () => void }) {
  const paid = (area.fee_cents ?? 0) > 0;
  return (
    <Card onPress={onPress} accessibilityLabel={`Reservar ${area.name}`} style={styles.areaCard}>
      <View style={styles.areaIcon}>
        <Ionicons name={areaIcon(area.name)} size={26} color={colors.brandStrong} />
      </View>
      <View style={{ flex: 1, gap: 3 }}>
        <Text style={styles.areaName}>{area.name}</Text>
        <Text style={[styles.price, { color: paid ? colors.text : colors.success }]}>{paid ? `${money(area.fee_cents)} · vem no boleto` : 'Gratuito'}</Text>
        <View style={styles.metaRow}>
          {area.capacity ? <Meta icon="people-outline" text={`Até ${area.capacity} pessoas`} /> : null}
          {area.opening_time && area.closing_time ? <Meta icon="time-outline" text={`${area.opening_time} às ${area.closing_time}`} /> : null}
          <Meta icon="shield-checkmark-outline" text="Com aprovação do síndico" />
        </View>
      </View>
      <Ionicons name="chevron-forward" size={18} color={colors.faint} />
    </Card>
  );
}

function Meta({ icon, text }: { icon: IconName; text: string }) {
  return (
    <View style={styles.meta}>
      <Ionicons name={icon} size={13} color={colors.muted} />
      <Text style={styles.metaText}>{text}</Text>
    </View>
  );
}

function BookingCard({ reservation: r, onCancel }: { reservation: Reservation; onCancel: () => void }) {
  const st = STATUS[r.status] ?? { label: r.status, color: colors.faint };
  const canCancel = ['active', 'confirmed'].includes(r.status) && r.reserved_on >= today();
  return (
    <View style={styles.booking}>
      <View style={styles.bookingHead}>
        <View style={{ flex: 1 }}>
          <Text style={styles.areaName}>{r.area}</Text>
          <Text style={styles.bookingWhen}>
            {date(r.reserved_on)}
            {r.starts_time ? ` · ${r.starts_time}` : ''}
            {r.ends_time ? ` às ${r.ends_time}` : ''}
          </Text>
        </View>
        <View style={[styles.dot, { backgroundColor: st.color, marginTop: 6 }]} accessibilityLabel={st.label} />
      </View>
      <View style={styles.bookingBody}>
        <Body muted>
          {st.label}
          {r.fee_cents ? ` · taxa ${money(r.fee_cents)} no boleto` : ''}
        </Body>
        {canCancel ? <Button title="Cancelar reserva" variant="secondary" onPress={onCancel} /> : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  segment: { flexDirection: 'row', backgroundColor: colors.surfaceMuted, borderRadius: radius.pill, padding: 4, borderWidth: StyleSheet.hairlineWidth, borderColor: colors.border },
  segmentItem: { flex: 1, height: 38, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
  segmentActive: { backgroundColor: colors.surface, shadowColor: '#0d2e27', shadowOpacity: 0.08, shadowRadius: 6, shadowOffset: { width: 0, height: 2 }, elevation: 2 },
  segmentText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.muted },
  segmentTextActive: { color: colors.text },
  chips: { flexDirection: 'row', gap: space[2], flexWrap: 'wrap' },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 6, height: 34, paddingHorizontal: 14, borderRadius: radius.pill, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  chipActive: { backgroundColor: colors.brand, borderColor: colors.brand },
  chipText: { fontFamily: fonts.medium, fontSize: 13, color: colors.muted },
  areaCard: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  areaIcon: { width: 56, height: 56, borderRadius: 16, backgroundColor: colors.brandSoft, alignItems: 'center', justifyContent: 'center' },
  areaName: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  price: { fontFamily: fonts.semibold, fontSize: 14 },
  metaRow: { flexDirection: 'row', flexWrap: 'wrap', gap: space[3], marginTop: 2 },
  meta: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  metaText: { fontFamily: fonts.regular, fontSize: 12, color: colors.muted },
  legend: { flexDirection: 'row', flexWrap: 'wrap', gap: space[4] },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  legendText: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  dot: { width: 10, height: 10, borderRadius: 5 },
  booking: { backgroundColor: colors.surface, borderRadius: radius.xl, overflow: 'hidden', borderWidth: StyleSheet.hairlineWidth, borderColor: colors.border },
  bookingHead: { flexDirection: 'row', gap: space[2], padding: space[4], backgroundColor: colors.surfaceMuted },
  bookingWhen: { fontFamily: fonts.semibold, fontSize: 14, color: colors.text, marginTop: 2 },
  bookingBody: { padding: space[4], gap: space[3] },
});
