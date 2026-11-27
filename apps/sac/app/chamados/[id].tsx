import Ionicons from '@expo/vector-icons/Ionicons';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Linking, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { Message, TicketPriority, TicketStatus } from '../../src/api/sac';
import { useAuth } from '../../src/auth/AuthContext';
import { authorLabel, CHANNEL_LABELS, channelOf, context, dueText, isFinal, PRIORITY_LABELS, priorityTone, STATUS_LABELS, statusTone, when } from '../../src/lib/tickets';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Badge } from '../../src/ui/Badge';
import { dialog } from '../../src/ui/Dialog';
import { Button, Card, ErrorBox, Loading, Screen } from '../../src/ui/kit';

const STATUSES: TicketStatus[] = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];
const PRIORITIES: TicketPriority[] = ['low', 'medium', 'high', 'urgent'];

/** Um chamado do SAC: dados, conversa (com notas internas), resposta, assumir, situação, prioridade e responsável. */
export default function Chamado() {
  const { api, me } = useAuth();
  const { id } = useLocalSearchParams<{ id: string }>();
  const ticketId = Number(id);
  const detail = useResource(() => api.ticket(ticketId), [ticketId]);
  const options = useResource(() => api.options(), []);
  const [text, setText] = useState('');
  const [internal, setInternal] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const d = detail.data;
  const t = d?.ticket;

  async function act(key: string, run: () => Promise<unknown>, done: string) {
    setBusy(key);
    try {
      await run();
      dialog.toast(done);
      await detail.reload();
    } catch (e) {
      await dialog.notice({ title: 'Não deu certo', message: e instanceof ApiError ? e.message : 'Tente de novo em instantes.', tone: 'error' });
    } finally {
      setBusy(null);
    }
  }

  async function send(resolve: boolean) {
    const body = text.trim();
    if (body.length < 2) {
      await dialog.notice({ title: 'Escreva a resposta', message: 'A mensagem precisa ter pelo menos 2 letras.', tone: 'info' });
      return;
    }
    await act(resolve ? 'resolve' : 'send', async () => {
      await api.answer(ticketId, body, internal, resolve && !internal);
      setText('');
    }, internal ? 'Nota interna registrada' : resolve ? 'Resposta enviada e chamado resolvido' : 'Resposta enviada');
  }

  async function pickStatus() {
    const i = await dialog.choose({ title: 'Situação do chamado', options: STATUSES.map((s) => ({ label: STATUS_LABELS[s] })) });
    if (i !== null && t && STATUSES[i] !== t.status) await act('status', () => api.triage(ticketId, { status: STATUSES[i] }), 'Situação alterada');
  }

  async function pickPriority() {
    const i = await dialog.choose({ title: 'Prioridade', options: PRIORITIES.map((p) => ({ label: PRIORITY_LABELS[p] })) });
    if (i !== null && t && PRIORITIES[i] !== t.priority) await act('priority', () => api.triage(ticketId, { priority: PRIORITIES[i] }), 'Prioridade alterada');
  }

  async function pickAgent() {
    const agents = options.data?.agents ?? [];
    const i = await dialog.choose({ title: 'Passar para', options: [{ label: 'Ninguém (sem responsável)' }, ...agents.map((a) => ({ label: a.name }))] });
    if (i === null) return;
    const target = i === 0 ? null : agents[i - 1].id;
    await act('assign', () => api.triage(ticketId, { assigned_to: target }), target === null ? 'Chamado sem responsável' : 'Responsável alterado');
  }

  async function useTemplate() {
    const list = options.data?.templates ?? [];
    if (list.length === 0) return;
    const i = await dialog.choose({ title: 'Resposta rápida', options: list.map((x) => ({ label: x.title })) });
    if (i !== null) setText((cur) => (cur ? `${cur}\n\n` : '') + list[i].body);
  }

  const mine = t?.assigned_to?.id === me?.id;
  const attend = d?.can_attend ?? false;

  return (
    <Screen title={t?.protocol ?? 'Chamado'} back refreshing={detail.refreshing} onRefresh={detail.reload}>
      {detail.loading ? (
        <Loading />
      ) : detail.error ? (
        <ErrorBox error={detail.error} onRetry={detail.reload} />
      ) : t && d ? (
        <>
          <Card style={t.late ? styles.lateCard : undefined}>
            <View style={styles.badges}>
              <Badge tone={statusTone(t.status)} label={STATUS_LABELS[t.status]} />
              <Badge tone={priorityTone(t.priority)} label={PRIORITY_LABELS[t.priority]} />
              {t.late ? <Badge tone="danger" label="Atrasado" /> : null}
            </View>
            <Text style={styles.subject}>{t.subject}</Text>
            {context(t) ? <Text style={styles.meta}>{context(t)}</Text> : null}
            <View style={styles.due}>
              <Ionicons name={t.late ? 'alarm' : 'time-outline'} size={15} color={t.late ? colors.danger : colors.muted} />
              <Text style={[styles.meta, t.late && { color: colors.danger, fontFamily: fonts.semibold }]}>{dueText(t)}</Text>
            </View>
            <Text style={styles.meta}>Responsável: {t.assigned_to ? t.assigned_to.name : 'ninguém ainda'}</Text>
            {t.requester.phone || t.requester.email ? (
              <View style={styles.contact}>
                {t.requester.phone ? (
                  <Pressable onPress={() => void Linking.openURL(`tel:${t.requester.phone}`)} accessibilityRole="button" style={styles.chip}>
                    <Ionicons name="call-outline" size={15} color={colors.brandStrong} />
                    <Text style={styles.chipText}>Ligar</Text>
                  </Pressable>
                ) : null}
                {t.requester.email ? (
                  <Pressable onPress={() => void Linking.openURL(`mailto:${t.requester.email}?subject=${encodeURIComponent(t.protocol)}`)} accessibilityRole="button" style={styles.chip}>
                    <Ionicons name="mail-outline" size={15} color={colors.brandStrong} />
                    <Text style={styles.chipText}>E-mail</Text>
                  </Pressable>
                ) : null}
              </View>
            ) : null}
          </Card>

          {attend ? (
            <View style={styles.actions}>
              {!mine && !isFinal(t.status) ? <Button title="Assumir" onPress={() => void act('take', () => api.take(ticketId), 'Chamado é seu agora')} busy={busy === 'take'} /> : null}
              <View style={styles.actionRow}>
                <Pill icon="swap-horizontal" label="Situação" onPress={pickStatus} />
                <Pill icon="flag-outline" label="Prioridade" onPress={pickPriority} />
                <Pill icon="person-add-outline" label="Passar" onPress={pickAgent} />
              </View>
            </View>
          ) : null}

          <Text style={styles.section}>Conversa</Text>
          <Text style={styles.sys}>
            {channelOf(t) === 'equipe' ? 'Aberto pela equipe' : `Aberto pelo ${CHANNEL_LABELS[channelOf(t)]}`} · {when(t.created_at)}
          </Text>
          <Bubble message={{ id: 0, author: t.requester.name ?? 'Quem pediu', from_team: false, message: t.message, internal: false, created_at: t.created_at, attachments: [] }} />
          {d.messages.map((m) => (
            <Bubble key={m.id} message={m} />
          ))}

          {attend ? (
            <Card>
              <View style={styles.toggle}>
                <Pressable onPress={() => setInternal(false)} style={[styles.seg, !internal && styles.segOn]} accessibilityRole="radio" accessibilityState={{ selected: !internal }}>
                  <Text style={[styles.segText, !internal && styles.segTextOn]}>Responder</Text>
                </Pressable>
                <Pressable onPress={() => setInternal(true)} style={[styles.seg, internal && styles.segNote]} accessibilityRole="radio" accessibilityState={{ selected: internal }}>
                  <Text style={[styles.segText, internal && { color: colors.warning }]}>Nota interna</Text>
                </Pressable>
              </View>
              <TextInput
                value={text}
                onChangeText={setText}
                multiline
                placeholder={internal ? 'Só a equipe vê esta nota' : 'Resposta para quem pediu'}
                placeholderTextColor={colors.faint}
                style={[styles.input, internal && { backgroundColor: colors.warningSoft }]}
                accessibilityLabel={internal ? 'Nota interna' : 'Resposta'}
              />
              {(options.data?.templates.length ?? 0) > 0 && !internal ? (
                <Pressable onPress={useTemplate} style={styles.template} accessibilityRole="button">
                  <Ionicons name="flash-outline" size={15} color={colors.brandStrong} />
                  <Text style={styles.chipText}>Resposta rápida</Text>
                </Pressable>
              ) : null}
              <View style={{ gap: space[2], marginTop: space[3] }}>
                <Button title={internal ? 'Salvar nota' : 'Enviar resposta'} onPress={() => void send(false)} busy={busy === 'send'} />
                {!internal && !isFinal(t.status) ? <Button title="Enviar e resolver" variant="secondary" onPress={() => void send(true)} busy={busy === 'resolve'} /> : null}
              </View>
            </Card>
          ) : (
            <Card>
              <Text style={styles.meta}>Você acompanha este chamado. Responder e mudar a situação é com a equipe de atendimento.</Text>
            </Card>
          )}

          {d.events.length > 0 ? (
            <>
              <Text style={styles.section}>Histórico</Text>
              <Card>
                {d.events.slice(0, 15).map((e, i) => (
                  <Text key={i} style={styles.event}>
                    {when(e.created_at)} · {e.author}: {e.label}
                    {e.new ? ` → ${e.new}` : ''}
                  </Text>
                ))}
              </Card>
            </>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

function Pill({ icon, label, onPress }: { icon: React.ComponentProps<typeof Ionicons>['name']; label: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={styles.pill} accessibilityRole="button" accessibilityLabel={label}>
      <Ionicons name={icon} size={16} color={colors.brandStrong} />
      <Text style={styles.chipText}>{label}</Text>
    </Pressable>
  );
}

function Bubble({ message: m }: { message: Message }) {
  const team = m.from_team || m.internal;
  return (
    <View style={[styles.bubble, team ? styles.bubbleTeam : styles.bubbleRequester, m.internal && styles.bubbleNote]}>
      <Text style={[styles.author, m.internal && { color: colors.warning }]}>{authorLabel(m)}</Text>
      <Text style={styles.body}>{m.message}</Text>
      {m.attachments.map((a) => (
        <Text key={a.url} style={styles.attachment}>
          📎 {a.name}
        </Text>
      ))}
      <Text style={styles.time}>{when(m.created_at)}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  lateCard: { borderColor: colors.danger, borderLeftWidth: 4 },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: space[2] },
  subject: { fontFamily: fonts.bold, fontSize: 18, color: colors.text, marginTop: space[3] },
  meta: { fontFamily: fonts.regular, fontSize: 14, color: colors.muted, marginTop: 4 },
  due: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 4 },
  contact: { flexDirection: 'row', gap: space[2], marginTop: space[3] },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: space[3], height: 32, borderRadius: radius.pill, backgroundColor: colors.brandSoft },
  chipText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.brandStrong },
  actions: { gap: space[2] },
  actionRow: { flexDirection: 'row', gap: space[2] },
  pill: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 4, height: 40, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  section: { fontFamily: fonts.semibold, fontSize: 13, color: colors.muted, textTransform: 'uppercase', letterSpacing: 0.6, marginTop: space[2] },
  bubble: { borderRadius: radius.lg, padding: space[3], maxWidth: '88%', gap: 4 },
  bubbleRequester: { alignSelf: 'flex-start', backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderBottomLeftRadius: 4 },
  // Resposta da equipe em dourado-claro; nota interna no centro, tracejada em amarelo (prévia aprovada em 27/11).
  bubbleTeam: { alignSelf: 'flex-end', backgroundColor: colors.accentSoft, borderWidth: 1, borderColor: colors.accentLine, borderBottomRightRadius: 4 },
  bubbleNote: { alignSelf: 'center', maxWidth: '94%', backgroundColor: colors.warningSoft, borderWidth: 1, borderStyle: 'dashed', borderColor: '#d9b54a', borderRadius: radius.md, borderBottomRightRadius: radius.md },
  author: { fontFamily: fonts.semibold, fontSize: 12, color: colors.muted },
  sys: { alignSelf: 'center', fontFamily: fonts.medium, fontSize: 12, color: colors.muted, backgroundColor: '#e6e8e6', borderRadius: radius.pill, paddingHorizontal: space[3], paddingVertical: 3, overflow: 'hidden' },
  body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.text },
  attachment: { fontFamily: fonts.medium, fontSize: 13, color: colors.info },
  time: { fontFamily: fonts.regular, fontSize: 11, color: colors.faint, alignSelf: 'flex-end' },
  toggle: { flexDirection: 'row', backgroundColor: colors.surfaceMuted, borderRadius: radius.md, padding: 3, marginBottom: space[3] },
  seg: { flex: 1, height: 34, alignItems: 'center', justifyContent: 'center', borderRadius: radius.sm },
  segOn: { backgroundColor: colors.surface },
  segNote: { backgroundColor: colors.surface },
  segText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.muted },
  segTextOn: { color: colors.brandStrong },
  input: { minHeight: 110, textAlignVertical: 'top', borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: space[3], fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  template: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: space[2], alignSelf: 'flex-start' },
  event: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted, paddingVertical: 3 },
});
