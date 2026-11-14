import Ionicons from '@expo/vector-icons/Ionicons';
import { useCallback, useEffect, useRef, useState, type ComponentProps } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import type { ChargesResponse, StatementLine } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { historyWindow, mergeLines } from '../../src/lib/billing';
import { copyText as copy, openBoleto, pendingLines } from '../../src/lib/boleto';
import { date, money, today } from '../../src/lib/format';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Button, Card, Empty, ErrorBox, Loading, Screen, SectionTitle } from '../../src/ui/kit';

type IconName = ComponentProps<typeof Ionicons>['name'];

/** Para de pedir meses antigos depois de duas janelas seguidas sem nenhuma conta. */
const EMPTY_WINDOWS_TO_STOP = 2;

const TONE: Record<string, { icon: IconName; tint: string; bg: string; label: string }> = {
  overdue: { icon: 'barcode-outline', tint: colors.danger, bg: '#fdeceb', label: 'Vencido' },
  open: { icon: 'barcode-outline', tint: colors.brandStrong, bg: colors.brandSoft, label: 'Em aberto' },
  paid: { icon: 'checkmark-done', tint: colors.success, bg: '#e6f6ee', label: 'Pago' },
  renegotiated: { icon: 'git-merge-outline', tint: colors.muted, bg: colors.surfaceMuted, label: 'Renegociado' },
};

/**
 * Boletos do morador. Carrega só os meses perto de hoje e, ao rolar até o fim, busca os 6 meses
 * anteriores (rolagem infinita), para o app não baixar o histórico inteiro de uma vez.
 */
export default function Boletos() {
  const { api, client, residentId } = useAuth();
  const [lines, setLines] = useState<StatementLine[]>([]);
  const [totals, setTotals] = useState<ChargesResponse['totals'] | null>(null);
  const [cursor, setCursor] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [more, setMore] = useState(false);
  const [done, setDone] = useState(false);
  const [opening, setOpening] = useState<number | null>(null);
  const emptyRun = useRef(0);
  const busy = useRef(false);

  const first = useCallback(async () => {
    const w = historyWindow(today(), null);
    try {
      const res = await api.charges(w.from, w.to);
      setLines(mergeLines([], res.lines));
      setTotals(res.totals);
      setCursor(w.from);
      setError(null);
      setDone(false);
      emptyRun.current = 0;
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'network', 'Não foi possível carregar.'));
    }
  }, [api]);

  useEffect(() => {
    setLoading(true);
    setLines([]);
    first().finally(() => setLoading(false));
  }, [first, residentId]);

  async function refresh() {
    setRefreshing(true);
    await first();
    setRefreshing(false);
  }

  async function loadMore() {
    if (busy.current || done || loading || error || cursor === null) return;
    busy.current = true;
    setMore(true);
    const w = historyWindow(today(), cursor);
    try {
      const res = await api.charges(w.from, w.to);
      setLines((cur) => mergeLines(cur, res.lines));
      setCursor(w.from);
      emptyRun.current = res.lines.length === 0 ? emptyRun.current + 1 : 0;
      if (emptyRun.current >= EMPTY_WINDOWS_TO_STOP) setDone(true);
    } catch {
      // Falha ao buscar meses antigos não apaga o que já está na tela; o botão abaixo tenta de novo.
    } finally {
      busy.current = false;
      setMore(false);
    }
  }

  async function pdf(line: StatementLine) {
    if (!line.charge || opening !== null) return;
    setOpening(line.entry_id);
    try {
      await openBoleto(api, client, line);
    } finally {
      setOpening(null);
    }
  }

  const pending = pendingLines(lines);
  const history = lines.filter((l) => !pending.includes(l));
  const openCents = totals ? totals.open_cents + totals.overdue_cents : 0;

  return (
    <Screen title="Boletos" tab refreshing={refreshing} onRefresh={refresh} onEndReached={loadMore}>
      {loading ? <Loading /> : null}
      {error ? <ErrorBox error={error} onRetry={refresh} /> : null}
      {!loading && !error && totals ? (
        <>
          <View style={styles.summary}>
            <View style={{ flex: 1, gap: 2 }}>
              <Text style={styles.summaryLabel}>Em aberto</Text>
              <Text style={styles.summaryValue}>{money(openCents)}</Text>
            </View>
            {totals.overdue_cents > 0 ? (
              <View style={[styles.pill, { backgroundColor: '#fdeceb' }]}>
                <Ionicons name="alert-circle" size={14} color={colors.danger} />
                <Text style={[styles.pillText, { color: colors.danger }]}>{money(totals.overdue_cents)} vencido</Text>
              </View>
            ) : (
              <View style={[styles.pill, { backgroundColor: '#e6f6ee' }]}>
                <Ionicons name="checkmark-circle" size={14} color={colors.success} />
                <Text style={[styles.pillText, { color: colors.success }]}>Nada vencido</Text>
              </View>
            )}
          </View>

          <SectionTitle>A pagar</SectionTitle>
          {pending.length === 0 ? <Empty text="Nenhum boleto em aberto. Tudo em dia!" /> : null}
          {pending.map((l) => (
            <BoletoCard key={l.entry_id} line={l} actions onPdf={() => pdf(l)} opening={opening === l.entry_id} />
          ))}

          <SectionTitle>Histórico</SectionTitle>
          {history.length === 0 && done ? <Empty text="Ainda não há boletos pagos." /> : null}
          {history.map((l) => (
            <BoletoCard key={l.entry_id} line={l} />
          ))}
          {more ? <Loading /> : null}
          {!more && !done ? <Button title="Ver meses anteriores" variant="secondary" onPress={loadMore} /> : null}
          {done && history.length > 0 ? <Body muted style={{ textAlign: 'center' }}>Isso é tudo.</Body> : null}
        </>
      ) : null}
    </Screen>
  );
}

function BoletoCard({ line, actions, onPdf, opening }: { line: StatementLine; actions?: boolean; onPdf?: () => void; opening?: boolean }) {
  const tone = TONE[line.situation] ?? TONE.open;
  const charge = line.charge;
  return (
    <Card>
      <View style={styles.row}>
        <View style={[styles.icon, { backgroundColor: tone.bg }]}>
          <Ionicons name={tone.icon} size={22} color={tone.tint} />
        </View>
        <View style={{ flex: 1, gap: 2 }}>
          <Text style={styles.title} numberOfLines={2}>
            {line.description}
          </Text>
          <Text style={styles.sub}>{line.paid_on ? `Pago em ${date(line.paid_on)}` : `Vence em ${date(line.due_on)}`}</Text>
        </View>
        <View style={{ alignItems: 'flex-end', gap: 2 }}>
          <Text style={styles.amount}>{money(line.amount_cents)}</Text>
          <Text style={[styles.status, { color: tone.tint }]}>{tone.label}</Text>
        </View>
      </View>
      {actions && charge ? (
        <View style={styles.actions}>
          {charge.pdf_available ? <Action icon="document-text-outline" label={opening ? 'Abrindo…' : '2ª via (PDF)'} primary onPress={onPdf} /> : null}
          {charge.digitable_line ? <Action icon="copy-outline" label="Copiar código" onPress={() => copy(charge.digitable_line as string, 'Linha digitável')} /> : null}
          {charge.pix_copy_paste ? <Action icon="qr-code-outline" label="Pix" onPress={() => copy(charge.pix_copy_paste as string, 'Código Pix')} /> : null}
        </View>
      ) : null}
      {actions && !charge ? <Body muted>O boleto desta conta ainda não foi emitido.</Body> : null}
    </Card>
  );
}

function Action({ icon, label, primary, onPress }: { icon: IconName; label: string; primary?: boolean; onPress?: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={label}
      style={({ pressed }) => [styles.action, primary && styles.actionPrimary, pressed && { opacity: 0.7 }]}
    >
      <Ionicons name={icon} size={15} color={primary ? colors.onBrand : colors.brandStrong} />
      <Text style={[styles.actionText, primary && { color: colors.onBrand }]}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  summary: { flexDirection: 'row', alignItems: 'center', gap: space[3], backgroundColor: colors.surface, borderRadius: radius.xl, padding: space[4], borderWidth: StyleSheet.hairlineWidth, borderColor: colors.border },
  summaryLabel: { fontFamily: fonts.medium, fontSize: 13, color: colors.muted },
  summaryValue: { fontFamily: fonts.bold, fontSize: 26, color: colors.text },
  pill: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 10, height: 28, borderRadius: radius.pill },
  pillText: { fontFamily: fonts.semibold, fontSize: 12 },
  row: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  icon: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  title: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  sub: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  amount: { fontFamily: fonts.bold, fontSize: 16, color: colors.text },
  status: { fontFamily: fonts.semibold, fontSize: 12 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: space[2], marginTop: space[3] },
  action: { flexDirection: 'row', alignItems: 'center', gap: 6, height: 36, paddingHorizontal: 14, borderRadius: radius.pill, backgroundColor: colors.brandSoft },
  actionPrimary: { backgroundColor: colors.brand },
  actionText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.brandStrong },
});
