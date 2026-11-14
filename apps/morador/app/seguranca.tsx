import Ionicons from '@expo/vector-icons/Ionicons';
import * as Clipboard from 'expo-clipboard';
import * as Linking from 'expo-linking';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { useResource } from '../src/lib/useResource';
import { colors, fonts, radius, space } from '../src/theme';
import { Body, Button, Card, CardTitle, ErrorBox, Field, Loading, Screen, SectionTitle } from '../src/ui/kit';
import { dialog } from '../src/ui/Dialog';

/** Separa a chave em grupos de 4 para ler e digitar com menos erro. */
function groups(secret: string): string {
  return secret.replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim();
}

/**
 * Verificação em duas etapas da conta do morador. É opcional: o morador entra só com a senha
 * e liga aqui se quiser. Quem também é da equipe tem a verificação obrigatória (required) e não
 * vê o botão de desligar. Ligar, desligar e gerar novos códigos pedem o código atual do autenticador.
 */
export default function Seguranca() {
  const { api } = useAuth();
  const status = useResource(() => api.twoFactor(), []);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState<null | 'confirm' | 'disable' | 'recovery'>(null);
  const [error, setError] = useState<string | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  const s = status.data;

  async function run(action: 'confirm' | 'disable' | 'recovery') {
    setError(null);
    const clean = code.replace(/\s+/g, '');
    if (!/^\d{6}$/.test(clean)) {
      setError('Digite o código de 6 números que aparece no aplicativo autenticador.');
      return;
    }
    setBusy(action);
    try {
      if (action === 'disable') {
        await api.disableTwoFactor(clean);
        void dialog.notice({ title: 'Verificação desligada', message: 'Você volta a entrar só com a senha.', tone: 'info', icon: 'shield-outline' });
      } else {
        const result = action === 'confirm' ? await api.confirmTwoFactor(clean) : await api.newRecoveryCodes(clean);
        setCodes(result.recovery_codes ?? []);
      }
      setCode('');
      status.reload();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Não foi possível concluir. Tente de novo.');
    } finally {
      setBusy(null);
    }
  }

  async function copy(text: string, what: string) {
    await Clipboard.setStringAsync(text);
    dialog.toast(`${what} copiad${what.endsWith('s') ? 'os' : 'a'}.`);
  }

  async function openAuthenticator(uri: string) {
    try {
      await Linking.openURL(uri);
    } catch {
      void dialog.notice({ title: 'Nenhum autenticador encontrado', message: 'Instale um aplicativo autenticador (como Google Authenticator ou Microsoft Authenticator) ou copie a chave e cole nele.', tone: 'info', icon: 'key-outline' });
    }
  }

  const codeField = (
    <Field
      label="Código do autenticador"
      value={code}
      onChangeText={setCode}
      keyboardType="number-pad"
      maxLength={7}
      placeholder="000000"
      textContentType="oneTimeCode"
      autoComplete="one-time-code"
      error={error ?? undefined}
    />
  );

  return (
    <Screen title="Verificação em duas etapas" back refreshing={status.refreshing} onRefresh={status.reload}>
      <Body muted>
        Uma proteção a mais para a sua conta: além da senha, o app pede um código que muda a cada 30 segundos no seu aplicativo autenticador. É opcional.
      </Body>
      {status.loading ? <Loading /> : null}
      {status.error ? <ErrorBox error={status.error} onRetry={status.reload} /> : null}

      {codes && codes.length > 0 ? (
        <Card style={styles.codesCard}>
          <CardTitle>Guarde estes códigos de recuperação</CardTitle>
          <Body muted>Se perder o celular, cada código vale uma entrada. Eles só aparecem agora.</Body>
          <View style={styles.codes}>
            {codes.map((c) => (
              <Text key={c} style={styles.code} selectable>
                {c}
              </Text>
            ))}
          </View>
          <Button title="Copiar os códigos" variant="secondary" onPress={() => copy(codes.join('\n'), 'Códigos')} />
          <Button title="Já guardei" onPress={() => setCodes(null)} />
        </Card>
      ) : null}

      {s && !s.configured && !s.enabled ? (
        <Card>
          <CardTitle>Indisponível no momento</CardTitle>
          <Body muted>A verificação em duas etapas ainda não foi preparada no servidor. Fale com a administração.</Body>
        </Card>
      ) : null}

      {s?.enabled ? (
        <>
          <Card>
            <View style={styles.statusRow}>
              <View style={[styles.statusIcon, { backgroundColor: colors.successSoft }]}>
                <Ionicons name="shield-checkmark" size={22} color={colors.success} />
              </View>
              <View style={{ flex: 1 }}>
                <CardTitle>Ligada</CardTitle>
                <Body muted>
                  {s.recovery_remaining === 1 ? 'Resta 1 código de recuperação.' : `Restam ${s.recovery_remaining} códigos de recuperação.`}
                </Body>
              </View>
            </View>
            {s.required ? <Body muted>Sua conta também é da equipe da administração, por isso a verificação não pode ser desligada.</Body> : null}
          </Card>
          <SectionTitle>Mudar</SectionTitle>
          <Card>
            {codeField}
            <Button title="Gerar novos códigos de recuperação" variant="secondary" busy={busy === 'recovery'} disabled={!!busy} onPress={() => run('recovery')} />
            {!s.required ? <Button title="Desligar a verificação" variant="danger" busy={busy === 'disable'} disabled={!!busy} onPress={() => run('disable')} /> : null}
          </Card>
        </>
      ) : null}

      {s && !s.enabled && s.setup ? (
        <>
          <SectionTitle>Ligar em 3 passos</SectionTitle>
          <Card>
            <Step n={1} text="Instale um aplicativo autenticador, como Google Authenticator ou Microsoft Authenticator." />
            <Step n={2} text="Adicione a sua conta Connect nele. Neste celular, toque no botão abaixo. Se não abrir, copie a chave e cole no autenticador." />
            <Button title="Abrir no autenticador" onPress={() => openAuthenticator(s.setup!.uri)} />
            <View style={styles.secretBox}>
              <Text style={styles.secret} selectable>
                {groups(s.setup.secret)}
              </Text>
            </View>
            <Button title="Copiar a chave" variant="secondary" onPress={() => copy(s.setup!.secret.replace(/\s+/g, ''), 'Chave')} />
            <Step n={3} text="Digite aqui o código de 6 números que o autenticador mostra." />
            {codeField}
            <Button title="Ligar a verificação" busy={busy === 'confirm'} disabled={!!busy} onPress={() => run('confirm')} />
          </Card>
        </>
      ) : null}
    </Screen>
  );
}

function Step({ n, text }: { n: number; text: string }) {
  return (
    <View style={styles.step}>
      <View style={styles.stepNumber}>
        <Text style={styles.stepNumberText}>{n}</Text>
      </View>
      <Body style={{ flex: 1 }}>{text}</Body>
    </View>
  );
}

const styles = StyleSheet.create({
  statusRow: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  statusIcon: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  step: { flexDirection: 'row', gap: space[3], alignItems: 'flex-start', marginTop: space[1] },
  stepNumber: { width: 24, height: 24, borderRadius: 12, backgroundColor: colors.brandSoft, alignItems: 'center', justifyContent: 'center', marginTop: 1 },
  stepNumberText: { fontFamily: fonts.bold, fontSize: 13, color: colors.brandStrong },
  secretBox: { backgroundColor: colors.surfaceMuted, borderRadius: radius.md, padding: space[3], alignItems: 'center' },
  secret: { fontFamily: fonts.semibold, fontSize: 16, letterSpacing: 1.5, color: colors.text, textAlign: 'center' },
  codesCard: { borderColor: colors.accent, backgroundColor: colors.accentSoft },
  codes: { flexDirection: 'row', flexWrap: 'wrap', gap: space[2] },
  code: {
    fontFamily: fonts.semibold,
    fontSize: 15,
    letterSpacing: 1,
    color: colors.text,
    backgroundColor: colors.surface,
    borderRadius: radius.sm,
    paddingHorizontal: space[3],
    paddingVertical: 6,
    overflow: 'hidden',
  },
});
