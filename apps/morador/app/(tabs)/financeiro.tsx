import * as Clipboard from 'expo-clipboard';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, Text, View } from 'react-native';
import { ApiError } from '../../src/api/client';
import { isFeatureOn, statementLines, type StatementLine } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { date, money } from '../../src/lib/format';
import { openProtectedFile } from '../../src/lib/openFile';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, space } from '../../src/theme';
import { Body, Button, Card, CardTitle, Empty, ErrorBox, Loading, Row, Screen, StatusBadge } from '../../src/ui/kit';

export default function Financeiro() {
  const { api, client, me, residentId } = useAuth();
  const charges = useResource(() => api.charges(), [residentId]);
  const [opening, setOpening] = useState<number | null>(null);
  const lines = statementLines(charges.data);
  const open = lines.filter((l) => !['paid', 'settled', 'cancelled', 'canceled'].includes(l.status));
  const done = lines.filter((l) => !open.includes(l));

  async function copy(text: string, what: string) {
    await Clipboard.setStringAsync(text);
    Alert.alert('Copiado', `${what} copiado. Cole no app do seu banco.`);
  }

  async function pdf(line: StatementLine) {
    if (!line.charge) return;
    setOpening(line.id);
    try {
      await openProtectedFile(api.chargePdfUrl(line.charge.id), client.authHeaders(), `boleto-${line.due_date ?? line.id}.pdf`);
    } catch (e) {
      Alert.alert('Boleto', e instanceof ApiError ? e.message : 'Não foi possível abrir o boleto.');
    } finally {
      setOpening(null);
    }
  }

  function renderLine(line: StatementLine, withActions: boolean) {
    const charge = line.charge;
    return (
      <Card key={line.id}>
        <Row>
          <CardTitle>{line.description}</CardTitle>
          <StatusBadge status={line.status} />
        </Row>
        <Row>
          <Body muted>{line.paid_at ? `Pago em ${date(line.paid_at)}` : `Vence em ${date(line.due_date)}`}</Body>
          <Text style={{ fontFamily: fonts.bold, fontSize: 16, color: colors.text }}>{money(line.amount_cents)}</Text>
        </Row>
        {withActions && charge ? (
          <View style={{ gap: space[2], marginTop: space[1] }}>
            {charge.digitable_line ? (
              <Button title="Copiar linha digitável" variant="secondary" onPress={() => copy(charge.digitable_line as string, 'Linha digitável')} />
            ) : null}
            {charge.pix_copy_paste ? (
              <Button title="Copiar Pix copia e cola" variant="secondary" onPress={() => copy(charge.pix_copy_paste as string, 'Código Pix')} />
            ) : null}
            {charge.pdf_available ? <Button title="Abrir boleto (PDF)" onPress={() => pdf(line)} busy={opening === line.id} /> : null}
          </View>
        ) : null}
      </Card>
    );
  }

  return (
    <Screen refreshing={charges.refreshing} onRefresh={charges.reload}>
      {isFeatureOn(me, 'accountability') ? (
        <Card onPress={() => router.push('/prestacao-de-contas')} accessibilityLabel="Abrir prestação de contas">
          <CardTitle>Prestação de contas</CardTitle>
          <Body muted>Veja para onde foi o dinheiro do condomínio, mês a mês.</Body>
        </Card>
      ) : null}
      {charges.loading ? <Loading /> : null}
      {charges.error ? <ErrorBox error={charges.error} onRetry={charges.reload} /> : null}
      {charges.data ? (
        <>
          <Text style={{ fontFamily: fonts.semibold, fontSize: 15, color: colors.text }}>Em aberto</Text>
          {open.length === 0 ? <Empty text="Nenhuma conta em aberto. Tudo em dia!" /> : open.map((l) => renderLine(l, true))}
          <Text style={{ fontFamily: fonts.semibold, fontSize: 15, color: colors.text, marginTop: space[3] }}>Histórico</Text>
          {done.length === 0 ? <Empty text="Ainda não há pagamentos registrados." /> : done.map((l) => renderLine(l, false))}
        </>
      ) : null}
    </Screen>
  );
}
