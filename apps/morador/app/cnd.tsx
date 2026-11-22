import Ionicons from '@expo/vector-icons/Ionicons';
import * as Clipboard from 'expo-clipboard';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { router } from 'expo-router';
import { ApiError } from '../src/api/client';
import type { Certificate } from '../src/api/resident';
import { useAuth } from '../src/auth/AuthContext';
import { date, money } from '../src/lib/format';
import { openProtectedFile } from '../src/lib/openFile';
import { useResource } from '../src/lib/useResource';
import { colors, fonts, radius, space } from '../src/theme';
import { Body, Button, Card, Empty, ErrorBox, Loading, Screen, SectionTitle } from '../src/ui/kit';
import { dialog } from '../src/ui/Dialog';

/**
 * CND — certidão negativa de débitos da unidade (ADR 0635). O morador não emite: pede à administradora, o pedido vira
 * chamado do Help Desk, a equipe emite no ERP e a certidão aparece aqui. Com conta vencida em aberto, o servidor
 * recusa o pedido e o app só mostra o débito (nada é gerado).
 */
export default function Cnd() {
  const { api, client, residentId } = useAuth();
  const list = useResource(() => api.certificates(), [residentId]);
  const status = useResource(() => api.certificateStatus(), [residentId]);
  const [asking, setAsking] = useState(false);
  const [opening, setOpening] = useState<number | null>(null);

  async function reload() {
    await Promise.all([list.reload(), status.reload()]);
  }

  async function ask() {
    setAsking(true);
    try {
      const r = await api.requestCertificate();
      await status.reload();
      await dialog.notice({
        title: r?.created === false ? 'Pedido em andamento' : 'Pedido enviado',
        message: `A administradora recebeu o pedido (protocolo ${r?.protocol ?? ''}). A certidão aparece aqui quando for emitida.`,
        tone: 'success',
      });
    } catch (e) {
      const debt = e instanceof ApiError && e.code === 'debt_open';
      await status.reload();
      await dialog.notice({
        title: debt ? 'Há débito em aberto' : 'Não deu para pedir',
        message: e instanceof ApiError ? e.message : 'Tente de novo em instantes.',
        tone: debt ? 'info' : 'error',
      });
    } finally {
      setAsking(false);
    }
  }

  async function pdf(c: Certificate) {
    setOpening(c.id);
    try {
      await openProtectedFile(api.certificatePdfUrl(c.id), client.authHeaders(), `certidao-${c.as_of}.pdf`);
    } catch (e) {
      await dialog.notice({ title: 'Não deu para abrir', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
    } finally {
      setOpening(null);
    }
  }

  async function copy(code: string) {
    await Clipboard.setStringAsync(code);
    dialog.toast('Código copiado');
  }

  const items = list.data ?? [];
  const st = status.data;
  return (
    <Screen title="CND" subtitle="Certidão negativa de débitos" back refreshing={list.refreshing || status.refreshing} onRefresh={reload}>
      {(list.loading && !list.data) || (status.loading && !status.data) ? (
        <Loading />
      ) : list.error || status.error ? (
        <ErrorBox error={(list.error ?? status.error)!} onRetry={reload} />
      ) : (
        <>
          <Card>
            <View style={{ gap: space[3] }}>
              {st?.blocked ? (
                <>
                  <Text style={styles.result}>Há débito em aberto</Text>
                  <Body muted>{st.message}</Body>
                  <Button title="Ver boletos" variant="secondary" onPress={() => router.push('/financeiro')} />
                </>
              ) : st?.request ? (
                <>
                  <Text style={styles.result}>Pedido em andamento</Text>
                  <Body muted>
                    A administradora está preparando a certidão (protocolo {st.request.protocol}, pedido em {date(st.request.created_at)}). Ela aparece aqui
                    quando for emitida.
                  </Body>
                </>
              ) : (
                <>
                  <Body muted>
                    Peça a certidão à administradora. Ela confere a unidade, emite e a certidão aparece aqui, com um código para quem receber conferir se é
                    verdadeira. Com conta vencida em aberto, a certidão não é emitida.
                  </Body>
                  <Button title="Pedir certidão" onPress={ask} busy={asking} />
                </>
              )}
            </View>
          </Card>
          {items.length > 0 ? <SectionTitle>Emitidas nos últimos 12 meses</SectionTitle> : <Empty text="Nenhuma certidão emitida ainda." />}
          {items.map((c) => (
            <CertificateCard key={c.id} c={c} opening={opening === c.id} onPdf={() => pdf(c)} onCopy={() => copy(c.verification_code)} />
          ))}
        </>
      )}
    </Screen>
  );
}

function CertificateCard({ c, opening, onPdf, onCopy }: { c: Certificate; opening: boolean; onPdf: () => void; onCopy: () => void }) {
  const clear = c.result === 'clear';
  return (
    <Card>
      <View style={styles.row}>
        <View style={[styles.seal, { backgroundColor: clear ? colors.successSoft : colors.warningSoft }]}>
          <Ionicons name={clear ? 'ribbon' : 'alert-circle'} size={22} color={clear ? colors.success : colors.warning} />
        </View>
        <View style={{ flex: 1, gap: 2 }}>
          <Text style={styles.result}>{c.result_label}</Text>
          <Text style={styles.meta}>
            Situação em {date(c.as_of)}
            {clear ? '' : ` · ${money(c.total_cents)}`}
          </Text>
        </View>
      </View>
      <Pressable onPress={onCopy} accessibilityRole="button" accessibilityLabel={`Copiar o código ${c.verification_code}`} style={styles.code}>
        <Text style={styles.codeLabel}>Código de verificação</Text>
        <View style={styles.codeRow}>
          <Text style={styles.codeText}>{c.verification_code}</Text>
          <Ionicons name="copy-outline" size={16} color={colors.brandStrong} />
        </View>
      </Pressable>
      <View style={{ marginTop: space[3] }}>
        <Button title={opening ? 'Abrindo…' : 'Baixar PDF'} variant="secondary" onPress={onPdf} busy={opening} />
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  seal: { width: 44, height: 44, borderRadius: 22, alignItems: 'center', justifyContent: 'center' },
  result: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text },
  meta: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  code: { marginTop: space[3], padding: space[3], borderRadius: radius.md, backgroundColor: colors.surfaceMuted, gap: 2 },
  codeLabel: { fontFamily: fonts.medium, fontSize: 12, color: colors.muted },
  codeRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  codeText: { fontFamily: fonts.semibold, fontSize: 16, letterSpacing: 1, color: colors.text },
});
