import * as Clipboard from 'expo-clipboard';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import { isFeatureOn, type StatementLine } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { date, money } from '../../src/lib/format';
import { openProtectedFile } from '../../src/lib/openFile';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, space } from '../../src/theme';
import { Body, Button, Card, CardTitle, Empty, ErrorBox, Loading, Row, Screen, SectionTitle, StatusBadge } from '../../src/ui/kit';

export default function Financeiro() {
  const { api, client, me, residentId } = useAuth();
  const charges = useResource(() => api.charges(), [residentId]);
  const [opening, setOpening] = useState<number | null>(null);
  const lines = charges.data?.lines ?? [];
  const pending = lines.filter((l) => l.situation === 'open' || l.situation === 'overdue').sort((a, b) => a.due_on.localeCompare(b.due_on));
  const history = lines.filter((l) => !pending.includes(l));
  const totals = charges.data?.totals;

  async function copy(text: string, what: string) {
    await Clipboard.setStringAsync(text);
    Alert.alert('Copiado', `${what} copiado. Cole no app do seu banco.`);
  }

  async function pdf(line: StatementLine) {
    if (!line.charge || opening !== null) return;
    setOpening(line.entry_id);
    try {
      await openProtectedFile(api.chargePdfUrl(line.charge.id), client.authHeaders(), `boleto-${line.due_on}.pdf`);
    } catch (e) {
      Alert.alert('Boleto', e instanceof ApiError ? e.message : 'Não foi possível abrir o boleto.');
    } finally {
      setOpening(null);
    }
  }

  function renderLine(line: StatementLine, withActions: boolean) {
    const charge = line.charge;
    return (
      <Card key={line.entry_id}>
        <Row>
          <CardTitle>{line.description}</CardTitle>
          <StatusBadge kind="situation" status={line.situation} />
        </Row>
        <Row>
          <Body muted>{line.paid_on ? `Pago em ${date(line.paid_on)}` : `Vence em ${date(line.due_on)}`}</Body>
          <Text style={{ fontFamily: fonts.bold, fontSize: 16, color: colors.text }}>{money(line.amount_cents)}</Text>
        </Row>
        {withActions && charge ? (
          <View style={{ gap: space[2], marginTop: space[1] }}>
            {charge.pix_copy_paste ? (
              <Button title="Copiar Pix copia e cola" onPress={() => copy(charge.pix_copy_paste as string, 'Código Pix')} />
            ) : null}
            {charge.digitable_line ? (
              <Button title="Copiar linha digitável" variant="secondary" onPress={() => copy(charge.digitable_line as string, 'Linha digitável')} />
            ) : null}
            {charge.pdf_available ? (
              <Button title="Abrir boleto (PDF)" variant="secondary" onPress={() => pdf(line)} busy={opening === line.entry_id} />
            ) : null}
          </View>
        ) : null}
        {withActions && !charge ? <Body muted>O boleto desta conta ainda não foi emitido.</Body> : null}
      </Card>
    );
  }

  return (
    <Screen title="Financeiro" tab refreshing={charges.refreshing} onRefresh={charges.reload}>
      {isFeatureOn(me, 'accountability') ? (
        <Card onPress={() => router.push('/prestacao-de-contas')} accessibilityLabel="Abrir prestação de contas">
          <CardTitle>Prestação de contas</CardTitle>
          <Body muted>Veja para onde foi o dinheiro do condomínio, mês a mês.</Body>
        </Card>
      ) : null}
      {charges.loading ? <Loading /> : null}
      {charges.error ? <ErrorBox error={charges.error} onRetry={charges.reload} /> : null}
      {charges.data && totals ? (
        <>
          <Card>
            <Row>
              <Body muted>Em aberto</Body>
              <Text style={{ fontFamily: fonts.bold, fontSize: 16, color: colors.text }}>{money(totals.open_cents + totals.overdue_cents)}</Text>
            </Row>
            {totals.overdue_cents > 0 ? (
              <Row>
                <Body muted>Vencido</Body>
                <Text style={{ fontFamily: fonts.bold, fontSize: 16, color: colors.danger }}>{money(totals.overdue_cents)}</Text>
              </Row>
            ) : null}
            <Body muted>
              De {date(charges.data.from)} a {date(charges.data.to)}
            </Body>
          </Card>
          <SectionTitle>A pagar</SectionTitle>
          {pending.length === 0 ? <Empty text="Nenhuma conta em aberto. Tudo em dia!" /> : pending.map((l) => renderLine(l, true))}
          <SectionTitle>Histórico</SectionTitle>
          {history.length === 0 ? <Empty text="Ainda não há pagamentos no período." /> : history.map((l) => renderLine(l, false))}
        </>
      ) : null}
    </Screen>
  );
}
