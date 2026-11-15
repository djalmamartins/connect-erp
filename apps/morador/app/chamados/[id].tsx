import Ionicons from '@expo/vector-icons/Ionicons';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text, TextInput, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { TicketMessage } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { canReply, ticketKind, when } from '../../src/lib/tickets';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Button, Card, ErrorBox, Loading, Screen, StatusBadge } from '../../src/ui/kit';
import { dialog } from '../../src/ui/Dialog';

/** Conversa de um chamado: mensagens do morador à direita, da administração à esquerda, e a resposta embaixo. */
export default function Chamado() {
  const { api, residentId } = useAuth();
  const { id } = useLocalSearchParams<{ id: string }>();
  const ticketId = Number(id);
  const ticket = useResource(() => api.ticket(ticketId), [ticketId, residentId]);
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState(false);
  const t = ticket.data;
  const oc = t ? ticketKind(t) === 'ocorrencia' : false;

  async function send() {
    const body = reply.trim();
    if (body.length < 2) {
      return;
    }
    setBusy(true);
    try {
      await api.replyTicket(ticketId, body);
      setReply('');
      dialog.toast('Mensagem enviada');
      await ticket.reload();
    } catch (e) {
      await dialog.notice({
        title: e instanceof ApiError && e.status === 409 ? 'Chamado fechado' : 'Não deu para enviar',
        message: e instanceof ApiError ? e.message : 'Tente de novo em instantes.',
        tone: 'error',
      });
      if (e instanceof ApiError && e.status === 409) await ticket.reload();
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title={t ? `Nº ${t.number}` : 'Chamado'} back refreshing={ticket.refreshing} onRefresh={ticket.reload}>
      {ticket.loading ? (
        <Loading />
      ) : ticket.error ? (
        <ErrorBox error={ticket.error} onRetry={ticket.reload} />
      ) : t ? (
        <>
          <Card>
            <View style={styles.head}>
              <View style={[styles.kind, { backgroundColor: oc ? colors.dangerSoft : '#fdf0e6' }]}>
                <Ionicons name={oc ? 'alert-circle' : 'chatbubbles'} size={20} color={oc ? colors.danger : '#c2611f'} />
              </View>
              <View style={{ flex: 1, gap: 2 }}>
                <Text style={styles.subject}>{t.subject}</Text>
                <Text style={styles.meta}>
                  {oc ? 'Ocorrência aberta' : 'Chamado aberto'} {when(t.created_at)}
                </Text>
              </View>
            </View>
            <View style={{ alignSelf: 'flex-start', marginTop: space[2] }}>
              <StatusBadge kind="ticket" status={t.status} />
            </View>
          </Card>
          <View style={{ gap: space[3] }}>
            {t.messages.map((m) => (
              <Bubble key={m.id} message={m} />
            ))}
          </View>
          {canReply(t.status) ? (
            <Card>
              <View style={{ gap: space[3] }}>
                <TextInput
                  value={reply}
                  onChangeText={setReply}
                  placeholder="Escreva a sua resposta"
                  placeholderTextColor={colors.faint}
                  multiline
                  maxLength={5000}
                  style={styles.input}
                  accessibilityLabel="Sua resposta"
                />
                <Button title="Enviar resposta" onPress={send} busy={busy} disabled={reply.trim().length < 2} />
              </View>
            </Card>
          ) : (
            <Text style={styles.closed}>Este chamado foi fechado. Se precisar, abra um novo.</Text>
          )}
        </>
      ) : null}
    </Screen>
  );
}

function Bubble({ message }: { message: TicketMessage }) {
  const mine = message.author === 'morador';
  return (
    <View style={[styles.bubbleRow, mine ? { justifyContent: 'flex-end' } : null]}>
      <View style={[styles.bubble, mine ? styles.mine : styles.theirs]}>
        {!mine ? <Text style={styles.author}>{message.author_name || 'Administração'}</Text> : null}
        <Text style={[styles.body, mine && { color: colors.onBrand }]}>{message.body}</Text>
        <Text style={[styles.time, mine && { color: 'rgba(255,255,255,0.75)' }]}>{when(message.created_at)}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  kind: { width: 42, height: 42, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  subject: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  meta: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  bubbleRow: { flexDirection: 'row' },
  bubble: { maxWidth: '85%', borderRadius: 18, paddingHorizontal: space[4], paddingVertical: space[3], gap: 4 },
  mine: { backgroundColor: colors.brand, borderBottomRightRadius: 6 },
  theirs: { backgroundColor: colors.surface, borderBottomLeftRadius: 6, borderWidth: StyleSheet.hairlineWidth, borderColor: colors.border },
  author: { fontFamily: fonts.semibold, fontSize: 13, color: colors.brandStrong },
  body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.text },
  time: { fontFamily: fonts.regular, fontSize: 11, color: colors.faint, alignSelf: 'flex-end' },
  input: {
    minHeight: 90,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surfaceMuted,
    padding: space[3],
    fontFamily: fonts.regular,
    fontSize: 15,
    color: colors.text,
    textAlignVertical: 'top',
  },
  closed: { fontFamily: fonts.medium, fontSize: 14, color: colors.muted, textAlign: 'center', marginVertical: space[3] },
});
