import Ionicons from '@expo/vector-icons/Ionicons';
import { useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import { dialog } from '../../src/ui/Dialog';
import type { View as QueueView } from '../../src/api/sac';
import { useAuth } from '../../src/auth/AuthContext';
import { storage } from '../../src/auth/storage';
import { apiBaseUrl } from '../../src/config';
import { meuDiaWebUrl, openMeuDia } from '../../src/lib/apps';
import { setBadge } from '../../src/lib/push';
import { badgeCount, presenceText, queueCondominium, sortQueue, VIEWS } from '../../src/lib/tickets';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Card, Empty, ErrorBox, Loading, Screen } from '../../src/ui/kit';
import { TicketRow } from '../../src/ui/TicketRow';

/**
 * Fila do SAC: Meus, Sem responsável, Atrasados e Todos, com o número de cada um em dourado;
 * atrasado fica em vermelho e vai para o topo. A busca fica na aba Buscar.
 * Quem atende mais de um condomínio filtra a fila e os números por condomínio (ADR 0670).
 */
export default function Fila() {
  const { api, me } = useAuth();
  const [view, setView] = useState<QueueView>('mine');
  const options = useResource(() => api.options(), []);
  const condominiums = options.data?.condominiums ?? [];
  // null = ainda lendo o filtro guardado; 0 = todos os condomínios.
  const [condominium, setCondominium] = useState<number | null>(null);
  useEffect(() => {
    if (!options.data) return;
    void storage.getQueueCondominium().then((saved) => setCondominium(queueCondominium(saved, options.data?.condominiums ?? [])));
  }, [options.data]);
  const ready = condominium !== null;
  const summary = useResource(() => (ready ? api.summary(condominium) : Promise.resolve(null)), [condominium]);
  const day = useResource(() => api.myDay(), []);
  const list = useResource(() => (ready ? api.tickets(view, '', condominium) : Promise.resolve(null)), [view, condominium]);

  function chooseCondominium(id: number) {
    setCondominium(id);
    void storage.setQueueCondominium(id);
  }
  // Chave do balão do site só para quem atende (a API devolve 403 para quem só acompanha).
  const presence = useResource(() => (me?.can_attend_sac ? api.presence() : Promise.resolve(null)), [me?.can_attend_sac]);
  const [saving, setSaving] = useState(false);

  async function togglePresence(value: boolean) {
    setSaving(true);
    try {
      await api.setPresence(value);
      await presence.reload();
    } catch (e) {
      await dialog.notice({ title: 'Não deu certo', message: e instanceof ApiError ? e.message : 'Tente de novo em instantes.', tone: 'error' });
    } finally {
      setSaving(false);
    }
  }

  // O número no ícone é sempre da fila inteira: com um condomínio escolhido, fica o último valor geral.
  useEffect(() => {
    if (condominium === 0) void setBadge(badgeCount(summary.data));
  }, [summary.data, condominium]);

  // Volta de um chamado: atualiza a fila e os números.
  useFocusEffect(
    useCallback(() => {
      void summary.reload();
      void list.reload();
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []),
  );

  const reload = async () => {
    await Promise.all([summary.reload(), list.reload(), day.reload(), presence.reload()]);
  };
  const s = summary.data;
  const counts: Partial<Record<QueueView, number>> = s ? { mine: s.mine_open, unassigned: s.unassigned, overdue: s.overdue } : {};
  const tickets = list.data ? sortQueue(list.data) : [];

  return (
    <Screen title="Atendimento" eyebrow={me ? `Olá, ${me.first_name || me.name}` : undefined} tab refreshing={list.refreshing} onRefresh={reload}>
      {presence.data ? (
        <View style={[styles.online, !(presence.data.shown) && styles.offline]}>
          <View style={[styles.dot, !presence.data.shown && styles.dotOff]} />
          <View style={{ flex: 1 }}>
            <Text style={styles.onlineTitle}>{presenceText(presence.data).title}</Text>
            <Text style={styles.onlineDetail}>{presenceText(presence.data).detail}</Text>
          </View>
          <Switch
            value={presence.data.public}
            onValueChange={(v) => void togglePresence(v)}
            disabled={saving}
            trackColor={{ true: colors.success, false: colors.border }}
            thumbColor={colors.surface}
            accessibilityLabel="Aparecer no balão do site"
          />
        </View>
      ) : null}

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

      {condominiums.length > 1 ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips} accessibilityLabel="Filtrar por condomínio">
          {[{ id: 0, name: 'Todos os condomínios' }, ...condominiums].map((c) => {
            const on = c.id === (condominium ?? 0);
            return (
              <Pressable key={c.id} onPress={() => chooseCondominium(c.id)} accessibilityRole="button" accessibilityState={{ selected: on }} style={[styles.chip, on && styles.chipOn]}>
                <Text style={[styles.chipText, on && styles.chipTextOn]} numberOfLines={1}>
                  {c.name}
                </Text>
              </Pressable>
            );
          })}
        </ScrollView>
      ) : null}

      <View style={styles.seg} accessibilityRole="tablist">
        {VIEWS.map((v) => {
          const active = v.key === view;
          const n = counts[v.key];
          return (
            <Pressable key={v.key} onPress={() => setView(v.key)} accessibilityRole="tab" accessibilityLabel={n ? `${v.label}, ${n}` : v.label} accessibilityState={{ selected: active }} style={[styles.segItem, active && styles.segOn]}>
              <Text style={[styles.segText, active && styles.segTextOn]} numberOfLines={1} adjustsFontSizeToFit>
                {v.short}
                {n ? <Text style={[styles.segCount, v.key === 'overdue' && { color: colors.danger }]}> {n}</Text> : null}
              </Text>
            </Pressable>
          );
        })}
      </View>

      {list.loading || !ready ? (
        <Loading />
      ) : list.error ? (
        <ErrorBox error={list.error} onRetry={list.reload} />
      ) : tickets.length === 0 ? (
        <Empty text={view === 'mine' ? 'Nenhum chamado seu em aberto. Veja os sem responsável.' : condominium ? 'Nada por aqui neste condomínio.' : 'Nada por aqui.'} />
      ) : (
        tickets.map((t) => <TicketRow key={t.id} ticket={t} />)
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  alert: { flexDirection: 'row', alignItems: 'center', gap: space[3], backgroundColor: colors.dangerSoft, borderRadius: radius.md, padding: space[3], borderWidth: 1, borderColor: '#f6c7cb' },
  alertText: { flex: 1, fontFamily: fonts.semibold, fontSize: 14, color: colors.danger },
  dayRow: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  dayIcon: { width: 42, height: 42, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.accentSoft },
  dayTitle: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  daySub: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  online: { flexDirection: 'row', alignItems: 'center', gap: space[3], backgroundColor: '#f1f8f3', borderWidth: 1, borderColor: '#cfe8d8', borderRadius: radius.md, paddingVertical: space[2], paddingHorizontal: space[3] },
  offline: { backgroundColor: colors.surface, borderColor: colors.border },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: '#16a34a', borderWidth: 3, borderColor: '#d4f0dd' },
  dotOff: { backgroundColor: colors.faint, borderColor: colors.surfaceMuted },
  onlineTitle: { fontFamily: fonts.semibold, fontSize: 14, color: colors.text },
  onlineDetail: { fontFamily: fonts.regular, fontSize: 12, color: colors.muted },
  chips: { gap: space[2], paddingRight: space[2] },
  chip: { height: 32, maxWidth: 220, paddingHorizontal: space[3], borderRadius: 16, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center' },
  chipOn: { backgroundColor: colors.accentSoft, borderColor: colors.accent },
  chipText: { fontFamily: fonts.medium, fontSize: 13, color: colors.muted },
  chipTextOn: { fontFamily: fonts.semibold, color: colors.text },
  seg: { flexDirection: 'row', backgroundColor: colors.surfaceMuted, borderRadius: 10, padding: 3 },
  segItem: { flex: 1, height: 34, borderRadius: 8, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 4 },
  segOn: { backgroundColor: colors.surface, shadowColor: '#000', shadowOpacity: 0.1, shadowRadius: 2, shadowOffset: { width: 0, height: 1 }, elevation: 1 },
  segText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.muted },
  segTextOn: { color: colors.text },
  segCount: { fontFamily: fonts.bold, color: colors.accent },
});
