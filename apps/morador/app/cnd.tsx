import Ionicons from '@expo/vector-icons/Ionicons';
import * as Clipboard from 'expo-clipboard';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
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
 * CND — certidão negativa de débitos da unidade (revisão de 15/11). O morador emite a do dia,
 * baixa o PDF e passa o código de verificação para quem for conferir.
 */
export default function Cnd() {
  const { api, client, residentId } = useAuth();
  const list = useResource(() => api.certificates(), [residentId]);
  const [issuing, setIssuing] = useState(false);
  const [opening, setOpening] = useState<number | null>(null);

  async function issue() {
    setIssuing(true);
    try {
      const c = await api.issueCertificate();
      await list.reload();
      await dialog.notice({
        title: c?.result === 'clear' ? 'Nada consta' : 'Certidão emitida',
        message:
          c?.result === 'clear'
            ? 'A unidade está sem débitos hoje. Baixe o PDF para enviar a quem pediu.'
            : `A certidão saiu com débitos de ${money(c?.total_cents ?? 0)}. Veja os boletos em aberto para regularizar.`,
        tone: c?.result === 'clear' ? 'success' : 'info',
        icon: c?.result === 'clear' ? 'ribbon' : undefined,
      });
    } catch (e) {
      await dialog.notice({ title: 'Não deu para emitir', message: e instanceof ApiError ? e.message : 'Tente de novo em instantes.', tone: 'error' });
    } finally {
      setIssuing(false);
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
  return (
    <Screen title="CND" subtitle="Certidão negativa de débitos" back refreshing={list.refreshing} onRefresh={list.reload}>
      {list.loading && !list.data ? (
        <Loading />
      ) : list.error ? (
        <ErrorBox error={list.error} onRetry={list.reload} />
      ) : (
        <>
          <Card>
            <View style={{ gap: space[3] }}>
              <Body muted>
                A certidão mostra se a unidade tem débitos com o condomínio na data de hoje. Ela traz um código para quem receber
                conferir se é verdadeira.
              </Body>
              <Button title="Emitir certidão" onPress={issue} busy={issuing} />
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
