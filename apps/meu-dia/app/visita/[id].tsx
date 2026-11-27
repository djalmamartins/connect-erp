import Ionicons from '@expo/vector-icons/Ionicons';
import * as Linking from 'expo-linking';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import { useAuth } from '../../src/auth/AuthContext';
import { storage } from '../../src/auth/storage';
import { dayKey, shortDay } from '../../src/lib/day';
import { currentPosition } from '../../src/lib/location';
import { openInSac } from '../../src/lib/sac';
import { useResource } from '../../src/lib/useResource';
import { ARRIVAL_STEPS, type ArrivalStepId, arrivalKey, type MapApp, mapsUrl, readArrival, sinceLabel, toggleStep, visitCounts, visitHeadline, writeArrival } from '../../src/lib/visit';
import { colors, fonts, radius, space } from '../../src/theme';
import { dialog } from '../../src/ui/Dialog';
import { Button, Card, CardTitle, Empty, ErrorBox, Field, IconRow, Loading, Screen, SectionTitle } from '../../src/ui/kit';

/**
 * Painel da visita (ADR 0662): antes de entrar no prédio, o que acompanhar (chamados abertos, documentos e
 * vencimentos, pendências da última vistoria) e um checklist de chegada que fica só no aparelho.
 * Os dados vêm de GET /api/v1/my-day/visit/{condominium_id}, com o mesmo acesso por condomínio do ERP.
 */
export default function VisitScreen() {
  const { api, me } = useAuth();
  const params = useLocalSearchParams<{ id: string; template?: string; template_id?: string; open?: string }>();
  const templateId = Number(params.template_id) || 0;
  const openId = Number(params.open) || 0;
  const [starting, setStarting] = useState(false);
  const [noteAsk, setNoteAsk] = useState<string | null>(null);
  const [checkinNote, setCheckinNote] = useState('');
  const id = Number(params.id);
  const panel = useResource(() => api.visit(id), [id]);
  const today = dayKey(new Date());
  const key = arrivalKey(id, today);
  const [done, setDone] = useState<ArrivalStepId[]>([]);

  useEffect(() => {
    void storage.getArrival().then((raw) => setDone(readArrival(raw, key)));
  }, [key]);

  function toggle(step: ArrivalStepId) {
    const next = toggleStep(done, step);
    setDone(next);
    void storage.setArrival(writeArrival(key, next));
  }

  async function newTicket() {
    try {
      await Linking.openURL('connectsac://novo');
    } catch {
      await dialog.notice({ title: 'App do SAC não encontrado', message: 'Instale o app do SAC neste celular para abrir o chamado com foto, ou registre pelo ERP no computador.' });
    }
  }

  async function directions(name: string, address: string) {
    const apps: { app: MapApp; label: string }[] = [
      { app: 'native', label: Platform.OS === 'ios' ? 'Mapas' : 'App de mapas' },
      { app: 'google', label: 'Google Maps' },
      { app: 'waze', label: 'Waze' },
    ];
    const choice = await dialog.choose({ title: 'Como chegar', message: address, options: apps.map((a) => ({ label: a.label, icon: 'navigate-outline' as const })) });
    if (choice === null) return;
    try {
      await Linking.openURL(mapsUrl(apps[choice].app, name, address, Platform.OS));
    } catch {
      await dialog.notice({ title: 'Não deu para abrir o mapa', message: 'Copie o endereço e procure no app de mapas.' });
    }
  }

  /** Check-in com a localização do aparelho. Longe do prédio ou sem sinal, o servidor pede um motivo. */
  async function start() {
    if (openId) {
      router.push({ pathname: '/vistoria/[id]', params: { id: String(openId) } });
      return;
    }
    setStarting(true);
    try {
      const position = await currentPosition();
      const inspection = await api.startInspection({
        condominium_id: id,
        template_id: templateId,
        ...(position ?? {}),
        ...(noteAsk !== null ? { checkin_note: checkinNote } : {}),
      });
      setNoteAsk(null);
      router.replace({ pathname: '/vistoria/[id]', params: { id: String(inspection.id) } });
    } catch (e) {
      if (e instanceof ApiError && e.fields.checkin_note) {
        setNoteAsk(e.fields.checkin_note);
      } else {
        await dialog.notice({ title: 'Não deu para iniciar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
      }
    } finally {
      setStarting(false);
    }
  }

  if (panel.loading && !panel.data) return <Loading />;
  if (panel.error && !panel.data) {
    return (
      <Screen back title="Visita">
        <ErrorBox error={panel.error} onRetry={() => void panel.reload()} />
      </Screen>
    );
  }
  const p = panel.data;
  if (!p) return <Loading />;
  const c = visitCounts(p);
  const attention = c.urgent > 0 || c.expired > 0 || c.pending > 0;

  return (
    <Screen
      back
      eyebrow={typeof params.template === 'string' && params.template ? `Visita · ${params.template}` : 'Visita'}
      title={p.condominium.name}
      subtitle={p.condominium.address || undefined}
      refreshing={panel.loading}
      onRefresh={() => void panel.reload()}
    >
      <View style={styles.hero} accessibilityRole="summary">
        <Text style={styles.heroTitle}>O que acompanhar</Text>
        <View style={styles.counts}>
          <Count value={c.tickets} label={c.tickets === 1 ? 'chamado aberto' : 'chamados abertos'} tone={c.urgent > 0 ? 'danger' : 'plain'} />
          <Count value={c.documents} label="documentos e vencimentos" tone={c.expired > 0 ? 'danger' : 'plain'} />
          <Count value={c.pending} label="pendências da última vistoria" tone={c.pending > 0 ? 'warning' : 'plain'} />
        </View>
        <Text style={[styles.heroLine, attention && { color: '#ffd9db' }]}>{visitHeadline(c)}</Text>
        {p.last_visit ? (
          <Pressable
            onPress={() => router.push({ pathname: '/vistoria/[id]', params: { id: String(p.last_visit!.id) } })}
            style={styles.lastVisit}
            accessibilityRole="button"
            accessibilityLabel="Ver a última vistoria"
          >
            <Text style={[styles.heroLine, { flex: 1 }]}>
              Última vistoria: {p.last_visit.template} · {sinceLabel(p.last_visit.completed_at, today)}
              {p.last_visit.issues > 0 ? ` · ${p.last_visit.issues} ${p.last_visit.issues === 1 ? 'problema' : 'problemas'}` : ''}
            </Text>
            <Text style={styles.lastVisitLink}>Ver</Text>
            <Ionicons name="chevron-forward" size={16} color={colors.accent} />
          </Pressable>
        ) : (
          <Text style={styles.heroLine}>Nenhuma vistoria concluída neste prédio ainda.</Text>
        )}
      </View>

      {p.condominium.address ? (
        <Button title="Como chegar" variant="secondary" onPress={() => void directions(p.condominium.name, p.condominium.address)} />
      ) : null}

      <Card>
        <CardTitle>Checklist de chegada</CardTitle>
        <Text style={styles.progress}>
          {done.length} de {ARRIVAL_STEPS.length} feitos
        </Text>
        {ARRIVAL_STEPS.map((s) => {
          const on = done.includes(s.id);
          return (
            <Pressable
              key={s.id}
              onPress={() => toggle(s.id)}
              style={styles.step}
              accessibilityRole="checkbox"
              accessibilityState={{ checked: on }}
              accessibilityLabel={s.label}
            >
              <Ionicons name={on ? 'checkbox' : 'square-outline'} size={24} color={on ? colors.brand : colors.faint} />
              <Text style={[styles.stepText, on && styles.stepDone]}>{s.label}</Text>
            </Pressable>
          );
        })}
      </Card>

      <View style={{ gap: space[2] }}>
        {templateId > 0 && me?.can_inspect !== false ? (
          <>
            {noteAsk !== null ? (
              <Field label="Motivo do check-in" error={noteAsk} value={checkinNote} onChangeText={setCheckinNote} placeholder="Ex.: estou na portaria dos fundos" maxLength={500} />
            ) : null}
            <Button title={openId ? 'Continuar vistoria' : noteAsk !== null ? 'Iniciar com o motivo' : 'Iniciar vistoria'} onPress={() => void start()} busy={starting} />
          </>
        ) : null}
        <Button title="＋ Abrir chamado com foto no SAC" variant={templateId > 0 ? 'secondary' : 'primary'} onPress={() => void newTicket()} />
      </View>

      <SectionTitle>Chamados abertos</SectionTitle>
      {p.tickets.length === 0 ? <Empty text="Nenhum chamado aberto neste prédio." /> : null}
      {p.tickets.map((t) => {
        const red = t.overdue || t.priority === 'urgent' || t.priority === 'high';
        return (
          <IconRow
            key={t.id}
            icon="chatbubble-ellipses-outline"
            tint={red ? colors.danger : colors.brandStrong}
            bg={red ? colors.dangerSoft : colors.brandSoft}
            title={t.subject}
            subtitle={[t.protocol, t.priority_label, t.status_label, t.overdue ? `Prazo passou (${shortDay(t.due_on)})` : t.due_on ? `Prazo ${shortDay(t.due_on)}` : null].filter(Boolean).join(' · ')}
            onPress={() => void openInSac(t.id)}
            accessibilityLabel={`${t.subject}. Toque para abrir no app do SAC`}
          />
        );
      })}

      {p.documents.length > 0 ? (
        <>
          <SectionTitle>Documentos e vencimentos</SectionTitle>
          {p.documents.map((d) => (
            <IconRow
              key={d.id}
              icon="document-text-outline"
              tint={d.days < 0 ? colors.danger : colors.warning}
              bg={d.days < 0 ? colors.dangerSoft : colors.warningSoft}
              title={d.title}
              subtitle={d.label}
            />
          ))}
        </>
      ) : null}
    </Screen>
  );
}

function Count({ value, label, tone }: { value: number; label: string; tone: 'plain' | 'danger' | 'warning' }) {
  const color = tone === 'danger' ? '#ff8a8f' : tone === 'warning' ? colors.accent : '#ffffff';
  return (
    <View style={styles.count}>
      <Text style={[styles.countValue, { color }]}>{value}</Text>
      <Text style={styles.countLabel}>{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  hero: { backgroundColor: colors.sidebar, borderRadius: radius.xl, padding: space[4], gap: space[3], borderBottomWidth: 3, borderBottomColor: colors.accent },
  heroTitle: { fontFamily: fonts.semibold, fontSize: 13, color: colors.accent, textTransform: 'uppercase', letterSpacing: 0.6 },
  counts: { flexDirection: 'row', gap: space[2] },
  count: { flex: 1, gap: 2 },
  countValue: { fontFamily: fonts.bold, fontSize: 28 },
  countLabel: { fontFamily: fonts.regular, fontSize: 12, color: colors.sidebarInk },
  lastVisit: { flexDirection: 'row', alignItems: 'center', gap: 4, minHeight: 40 },
  lastVisitLink: { fontFamily: fonts.semibold, fontSize: 13, color: colors.accent },
  heroLine: { fontFamily: fonts.regular, fontSize: 13, color: colors.sidebarInk },
  progress: { fontFamily: fonts.medium, fontSize: 13, color: colors.muted, marginBottom: space[1] },
  step: { flexDirection: 'row', alignItems: 'center', gap: space[3], minHeight: 44 },
  stepText: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  stepDone: { color: colors.muted, textDecorationLine: 'line-through' },
});
