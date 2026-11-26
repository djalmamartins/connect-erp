import Ionicons from '@expo/vector-icons/Ionicons';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import type { Ticket, View as QueueView } from '../../src/api/sac';
import { useAuth } from '../../src/auth/AuthContext';
import { apiBaseUrl } from '../../src/config';
import { meuDiaWebUrl, openMeuDia } from '../../src/lib/apps';
import { setBadge } from '../../src/lib/push';
import { badgeCount, context, dueText, PRIORITY_LABELS, priorityTone, sortQueue, STATUS_LABELS, statusTone, ticketKind, VIEWS } from '../../src/lib/tickets';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Badge } from '../../src/ui/Badge';
import { Card, Empty, ErrorBox, Loading, Screen } from '../../src/ui/kit';

/** Fila do SAC: abas Meus, Sem responsável, Atrasados e Todos; atrasado fica em vermelho e vai para o topo. */
export default function Fila() {
  const { api, me } = useAuth();
  const [view, setView] = useState<QueueView>('mine');
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const summary = useResource(() => api.summary(), []);
  const day = useResource(() => api.myDay(), []);
  const list = useResource(() => api.tickets(view, search), [view, search]);

  useEffect(() => {
    void setBadge(badgeCount(summary.data));
  }, [summary.data]);

  // Volta de um chamado: atualiza a fila e os números.
  useFocusEffect(
    useCallback(() => {
      void summary.reload();
      void list.reload();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []),
  );

  const reload = async () => {
    await Promise.all([summary.reload(), list.reload(), day.reload()]);
  };
  const s = summary.data;
  const counts: Partial<Record<QueueView, number>> = s ? { mine: s.mine_open, unassigned: s.unassigned, overdue: s.overdue } : {};
  const tickets = list.data ? sortQueue(list.data) : [];

  return (
    <Screen title="SAC" eyebrow={me ? `Olá, ${me.first_name || me.name}` : undefined} tab refreshing={list.refreshing} onRefresh={reload}>
      {s && s.mine_overdue + s.overdue > 0 ? (
        <Pressable onPress={() => setView('overdue')} accessibilityRole="button" style={styles.alert}>
          <Ionicons name="alarm" size={22} color={colors.danger} />
          <Text style={styles.alertText}>
            {s.overdue === 1 ? '1 chamado atrasado' : `${s.overdue} chamados atrasados`}
            {s.mine_overdue > 0 ? ` (${s.mine_overdue} seu${s.mine_overdue > 1 ? 's' : ''})` : ''}. Toque para ver.
          </Text>
        </Pressable>
      ) : null}

      {day.data ? (
        <Card onPress={() => void openMeuDia(meuDiaWebUrl(apiBaseUrl()))} accessibilityLabel="Abrir o Meu Dia">
          <View style={styles.dayRow}>
            <View style={styles.dayIcon}>
              <Ionicons name="calendar" size={20} color={colors.accent} />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={styles.dayTitle}>Meu Dia de hoje</Text>
              <Text style={styles.daySub}>
                {day.data.summary.commitments} compromisso{day.data.summary.commitments === 1 ? '' : 's'} · {day.data.summary.to_schedule} para agendar
                {day.data.summary.overdue > 0 ? ` · ${day.data.summary.overdue} atrasado${day.data.summary.overdue === 1 ? '' : 's'}` : ''}
              </Text>
            </View>
            <Ionicons name="open-outline" size={18} color={colors.faint} />
          </View>
        </Card>
      ) : null}

      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.tabs}>
        {VIEWS.map((v) => {
          const active = v.key === view;
          const n = counts[v.key];
          return (
            <Pressable key={v.key} onPress={() => setView(v.key)} accessibilityRole="tab" accessibilityState={{ selected: active }} style={[styles.tab, active && styles.tabActive]}>
              <Text style={[styles.tabText, active && styles.tabTextActive]}>{v.label}</Text>
              {n ? (
                <View style={[styles.count, v.key === 'overdue' && styles.countDanger, active && v.key !== 'overdue' && styles.countActive]}>
                  <Text style={[styles.countText, (v.key === 'overdue' || active) && { color: colors.onBrand }]}>{n}</Text>
                </View>
              ) : null}
            </Pressable>
          );
        })}
      </ScrollView>

      <View style={styles.search}>
        <Ionicons name="search" size={18} color={colors.faint} />
        <TextInput
          value={q}
          onChangeText={setQ}
          onSubmitEditing={() => setSearch(q.trim())}
          placeholder="Assunto ou protocolo (SAC, OCO)"
          placeholderTextColor={colors.faint}
          returnKeyType="search"
          style={styles.searchInput}
          accessibilityLabel="Buscar chamado"
          autoCapitalize="characters"
        />
        {search ? (
          <Pressable onPress={() => { setQ(''); setSearch(''); }} accessibilityLabel="Limpar busca" hitSlop={8}>
            <Ionicons name="close-circle" size={18} color={colors.faint} />
          </Pressable>
        ) : null}
      </View>

      {list.loading ? (
        <Loading />
      ) : list.error ? (
        <ErrorBox error={list.error} onRetry={list.reload} />
      ) : tickets.length === 0 ? (
        <Empty text={view === 'mine' ? 'Nenhum chamado seu em aberto. Veja os sem responsável.' : 'Nada por aqui.'} />
      ) : (
        tickets.map((t) => <TicketRow key={t.id} ticket={t} />)
      )}
    </Screen>
  );
}

function TicketRow({ ticket: t }: { ticket: Ticket }) {
  const oc = ticketKind(t) === 'ocorrencia';
  return (
    <Card onPress={() => router.push(`/chamados/${t.id}`)} accessibilityLabel={`${t.protocol}, ${t.subject}${t.late ? ', atrasado' : ''}`} style={t.late ? styles.lateCard : undefined}>
      <View style={styles.rowHead}>
        <Text style={[styles.protocol, t.late && { color: colors.danger }]}>{t.protocol}</Text>
        <Badge tone={statusTone(t.status)} label={STATUS_LABELS[t.status]} />
      </View>
      <Text style={styles.subject} numberOfLines={2}>
        {oc ? 'Ocorrência: ' : ''}
        {t.subject}
      </Text>
      {context(t) ? <Text style={styles.meta} numberOfLines={1}>{context(t)}</Text> : null}
      <View style={styles.rowFoot}>
        <View style={styles.due}>
          <Ionicons name={t.late ? 'alarm' : 'time-outline'} size={14} color={t.late ? colors.danger : colors.muted} />
          <Text style={[styles.dueText, t.late && styles.dueLate]}>{dueText(t)}</Text>
        </View>
        <Badge tone={priorityTone(t.priority)} label={PRIORITY_LABELS[t.priority]} />
      </View>
      <Text style={styles.assignee}>{t.assigned_to ? `Com ${t.assigned_to.name}` : 'Sem responsável'}</Text>
    </Card>
  );
}

const styles = StyleSheet.create({
  alert: { flexDirection: 'row', alignItems: 'center', gap: space[3], backgroundColor: colors.dangerSoft, borderRadius: radius.md, padding: space[3], borderWidth: 1, borderColor: '#f6c7cb' },
  alertText: { flex: 1, fontFamily: fonts.semibold, fontSize: 14, color: colors.danger },
  dayRow: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  dayIcon: { width: 42, height: 42, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.accentSoft },
  dayTitle: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  daySub: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  tabs: { gap: space[2], paddingVertical: space[1] },
  tab: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: space[3], height: 36, borderRadius: radius.pill, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  tabActive: { backgroundColor: colors.brand, borderColor: colors.brand },
  tabText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.text },
  tabTextActive: { color: colors.onBrand },
  count: { minWidth: 20, height: 20, borderRadius: 10, paddingHorizontal: 5, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.brandSoft },
  countActive: { backgroundColor: colors.brandStrong },
  countDanger: { backgroundColor: colors.danger },
  countText: { fontFamily: fonts.bold, fontSize: 11, color: colors.brandStrong },
  search: { flexDirection: 'row', alignItems: 'center', gap: space[2], backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, paddingHorizontal: space[3], height: 44 },
  searchInput: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  lateCard: { borderColor: colors.danger, borderLeftWidth: 4 },
  rowHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: space[2] },
  protocol: { fontFamily: fonts.semibold, fontSize: 13, color: colors.muted, letterSpacing: 0.3 },
  subject: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text, marginTop: space[2] },
  meta: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted, marginTop: 2 },
  rowFoot: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: space[3] },
  due: { flexDirection: 'row', alignItems: 'center', gap: 4, flex: 1 },
  dueText: { fontFamily: fonts.medium, fontSize: 13, color: colors.muted },
  dueLate: { color: colors.danger, fontFamily: fonts.semibold },
  assignee: { fontFamily: fonts.regular, fontSize: 12, color: colors.faint, marginTop: space[1] },
});
