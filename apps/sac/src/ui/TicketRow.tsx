import Ionicons from '@expo/vector-icons/Ionicons';
import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import type { Ticket } from '../api/sac';
import { ago, CHANNEL_LABELS, channelOf, dueText, initials, isFinal, placeLine, STATUS_LABELS, ticketKind, type Channel } from '../lib/tickets';
import { colors, fonts, radius, space } from '../theme';

/** Cor do selo de cada canal: verde só no WhatsApp, dourado no app, cinza nos outros (prévia aprovada em 27/11). */
const CHANNEL_TONE: Record<Channel, { bg: string; fg: string }> = {
  whatsapp: { bg: colors.successSoft, fg: colors.success },
  app: { bg: colors.accentSoft, fg: colors.warning },
  telefone: { bg: colors.surfaceMuted, fg: colors.info },
  presencial: { bg: colors.surfaceMuted, fg: colors.info },
  site: { bg: colors.surfaceMuted, fg: colors.info },
  email: { bg: colors.surfaceMuted, fg: colors.info },
  equipe: { bg: colors.surfaceMuted, fg: colors.info },
};

/**
 * Linha da fila do SAC: protocolo dourado e há quanto tempo; assunto; canal, onde e quem pediu;
 * iniciais de quem atende. Atrasado ganha borda vermelha e o prazo em vermelho.
 */
export function TicketRow({ ticket: t }: { ticket: Ticket }) {
  const channel = channelOf(t);
  const tone = CHANNEL_TONE[channel];
  const who = initials(t.assigned_to?.name);
  const oc = ticketKind(t) === 'ocorrencia';
  return (
    <Pressable
      onPress={() => router.push(`/chamados/${t.id}`)}
      accessibilityRole="button"
      accessibilityLabel={`${t.protocol}, ${t.subject}, ${CHANNEL_LABELS[channel]}${t.late ? ', atrasado' : ''}${t.assigned_to ? `, com ${t.assigned_to.name}` : ', sem responsável'}`}
      style={({ pressed }) => [styles.row, t.late && styles.late, pressed && { opacity: 0.75 }]}
    >
      <View style={styles.l1}>
        <Text style={[styles.protocol, t.late && { color: colors.danger }]}>{t.protocol}</Text>
        <Text style={styles.ago}>{ago(t.created_at)}</Text>
      </View>
      <Text style={styles.subject} numberOfLines={2}>
        {oc ? 'Ocorrência: ' : ''}
        {t.subject}
      </Text>
      <View style={styles.l3}>
        <View style={[styles.chip, { backgroundColor: tone.bg }]}>
          {channel === 'whatsapp' ? <Ionicons name="logo-whatsapp" size={11} color={tone.fg} /> : null}
          <Text style={[styles.chipText, { color: tone.fg }]}>{CHANNEL_LABELS[channel]}</Text>
        </View>
        <Text style={styles.place} numberOfLines={1}>
          {placeLine(t)}
        </Text>
        {who ? (
          <View style={styles.who} accessibilityLabel={`Com ${t.assigned_to?.name}`}>
            <Text style={styles.whoText}>{who}</Text>
          </View>
        ) : (
          <View style={[styles.who, styles.nobody]}>
            <Ionicons name="person-outline" size={11} color={colors.faint} />
          </View>
        )}
      </View>
      {t.late || !isFinal(t.status) ? (
        <Text style={[styles.due, t.late && styles.dueLate]}>
          {t.late ? dueText(t) : `${STATUS_LABELS[t.status]} · ${dueText(t).toLowerCase()}`}
        </Text>
      ) : (
        <Text style={styles.due}>{STATUS_LABELS[t.status]}</Text>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { backgroundColor: colors.surface, borderRadius: 13, borderWidth: 1, borderColor: '#e5e7e5', paddingVertical: space[3], paddingHorizontal: space[3] + 2, gap: 4 },
  late: { borderColor: colors.danger, borderLeftWidth: 4 },
  l1: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  protocol: { fontFamily: fonts.bold, fontSize: 12, color: colors.accent, fontVariant: ['tabular-nums'], letterSpacing: 0.2 },
  ago: { fontFamily: fonts.regular, fontSize: 12, color: colors.faint },
  subject: { fontFamily: fonts.bold, fontSize: 16, color: colors.text },
  l3: { flexDirection: 'row', alignItems: 'center', gap: space[2] },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 3, borderRadius: radius.sm, paddingHorizontal: 7, paddingVertical: 2 },
  chipText: { fontFamily: fonts.bold, fontSize: 11 },
  place: { flex: 1, fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  who: { width: 24, height: 24, borderRadius: 12, backgroundColor: colors.sidebar, alignItems: 'center', justifyContent: 'center' },
  whoText: { fontFamily: fonts.bold, fontSize: 10, color: colors.onDark },
  nobody: { backgroundColor: colors.surfaceMuted, borderWidth: 1, borderStyle: 'dashed', borderColor: colors.faint },
  due: { fontFamily: fonts.medium, fontSize: 12, color: colors.faint },
  dueLate: { color: colors.danger, fontFamily: fonts.semibold },
});
