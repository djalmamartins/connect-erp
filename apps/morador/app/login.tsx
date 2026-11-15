import Ionicons from '@expo/vector-icons/Ionicons';
import { StatusBar } from 'expo-status-bar';
import { useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { colors, fonts, radius, space } from '../src/theme';
import { Body, Button, Field } from '../src/ui/kit';

/**
 * Entrada do morador. A conta nasce pelo convite enviado por e-mail pela administração
 * (ADR 0090); aqui o morador só entra com e-mail ou CPF e senha, e o código do 2FA quando ativo.
 */
export default function Login() {
  const { login, verifyTwoFactor, biometric, lastLogin, api } = useAuth();
  const [forgot, setForgot] = useState(false);
  const [sent, setSent] = useState<string | null>(null);
  const [identity, setIdentity] = useState(lastLogin ?? '');
  const [useBio, setUseBio] = useState(biometric.available);
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function askReset() {
    setError(null);
    if (identity.trim() === '') {
      setError('Informe o e-mail ou CPF da sua conta.');
      return;
    }
    setBusy(true);
    try {
      setSent(await api.forgotPassword(identity.trim()));
    } catch (e) {
      setError(
        e instanceof ApiError && e.status === 429
          ? 'Muitas tentativas. Aguarde alguns minutos e tente de novo.'
          : e instanceof ApiError
            ? e.message
            : 'Não foi possível pedir agora.',
      );
    } finally {
      setBusy(false);
    }
  }

  function backToLogin() {
    setForgot(false);
    setSent(null);
    setError(null);
  }

  async function submit() {
    setError(null);
    if (!challenge && (identity.trim() === '' || password === '')) {
      setError('Informe o e-mail (ou CPF) e a senha.');
      return;
    }
    if (challenge && !/^\d{6}$/.test(code.trim())) {
      setError('O código tem 6 números.');
      return;
    }
    setBusy(true);
    try {
      if (challenge) {
        await verifyTwoFactor(challenge, code.trim());
      } else {
        const result = await login(identity.trim(), password, biometric.available && useBio);
        if (result.kind === 'two_factor') {
          setChallenge(result.challenge);
        }
      }
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Não foi possível entrar agora.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.page}>
      <StatusBar style="light" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">
          <Image source={require('../assets/logo.png')} style={styles.logo} resizeMode="contain" accessibilityLabel="Connect Condomínios" />
          <View style={styles.card}>
            <Text style={styles.title}>{forgot ? 'Esqueci minha senha' : challenge ? 'Confirme que é você' : 'Área do morador'}</Text>
            {forgot ? (
              sent ? (
                <View style={styles.sent}>
                  <View style={styles.sentIcon}>
                    <Ionicons name="mail-unread-outline" size={28} color={colors.brandStrong} />
                  </View>
                  <Body>{sent}</Body>
                  <Body muted style={styles.note}>
                    Abra o link no celular ou no computador para criar a senha nova. Ele vale por pouco tempo. Se não chegar, olhe a caixa de spam.
                  </Body>
                </View>
              ) : (
                <>
                  <Body muted>Informe o e-mail ou CPF da sua conta. Vamos mandar um link para você criar uma senha nova.</Body>
                  <Field
                    label="E-mail ou CPF"
                    value={identity}
                    onChangeText={setIdentity}
                    autoCapitalize="none"
                    autoCorrect={false}
                    keyboardType="email-address"
                    autoComplete="username"
                    textContentType="username"
                    onSubmitEditing={askReset}
                    autoFocus
                  />
                </>
              )
            ) : challenge ? (
              <>
                <Body muted>Digite o código de 6 números do seu aplicativo autenticador.</Body>
                <Field
                  label="Código"
                  value={code}
                  onChangeText={setCode}
                  keyboardType="number-pad"
                  autoComplete="one-time-code"
                  textContentType="oneTimeCode"
                  maxLength={6}
                  autoFocus
                />
              </>
            ) : (
              <>
                <Field
                  label="E-mail ou CPF"
                  value={identity}
                  onChangeText={setIdentity}
                  autoCapitalize="none"
                  autoCorrect={false}
                  keyboardType="email-address"
                  autoComplete="username"
                  textContentType="username"
                />
                <Field
                  label="Senha"
                  value={password}
                  onChangeText={setPassword}
                  secureTextEntry
                  autoComplete="password"
                  textContentType="password"
                  onSubmitEditing={submit}
                />
                {biometric.available ? (
                  <Pressable
                    onPress={() => setUseBio((v) => !v)}
                    accessibilityRole="checkbox"
                    accessibilityState={{ checked: useBio }}
                    style={styles.check}
                    hitSlop={8}
                  >
                    <Ionicons name={useBio ? 'checkbox' : 'square-outline'} size={24} color={useBio ? colors.brand : colors.muted} />
                    <Text style={styles.checkText}>Nas próximas vezes, entrar com {biometric.label}</Text>
                  </Pressable>
                ) : null}
              </>
            )}
            {error ? <Text style={styles.error} accessibilityLiveRegion="polite">{error}</Text> : null}
            {forgot ? (
              <>
                {sent ? null : <Button title="Enviar link" onPress={askReset} busy={busy} />}
                <Button title="Voltar para o login" variant="secondary" onPress={backToLogin} />
              </>
            ) : (
              <Button title={challenge ? 'Confirmar' : 'Entrar'} onPress={submit} busy={busy} />
            )}
            {forgot ? null : challenge ? (
              <Button
                title="Voltar"
                variant="secondary"
                onPress={() => {
                  setChallenge(null);
                  setCode('');
                  setError(null);
                }}
              />
            ) : (
              <>
                <Pressable
                  onPress={() => {
                    setForgot(true);
                    setError(null);
                  }}
                  accessibilityRole="link"
                  hitSlop={8}
                  style={styles.forgot}
                >
                  <Text style={styles.forgotText}>Esqueci minha senha</Text>
                </Pressable>
                <Body muted style={styles.note}>
                Primeiro acesso? Use o link do convite que a administração enviou para o seu e-mail para criar a senha.
                Depois é só entrar aqui.
                </Body>
              </>
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.sidebar },
  scroll: { flexGrow: 1, justifyContent: 'center', padding: space[5], gap: space[6] },
  logo: { width: '80%', maxWidth: 320, height: 96, alignSelf: 'center' },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, padding: space[5], gap: space[4] },
  title: { fontFamily: fonts.bold, fontSize: 20, color: colors.text },
  error: { fontFamily: fonts.medium, fontSize: 14, color: colors.danger },
  note: { fontSize: 13, lineHeight: 19 },
  forgot: { alignSelf: 'center', paddingVertical: space[1] },
  forgotText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.brandStrong },
  sent: { alignItems: 'center', gap: space[3] },
  sentIcon: { width: 56, height: 56, borderRadius: 28, backgroundColor: colors.brandSoft, alignItems: 'center', justifyContent: 'center' },
  check: { flexDirection: 'row', alignItems: 'center', gap: space[2] },
  checkText: { flex: 1, fontFamily: fonts.medium, fontSize: 15, color: colors.text },
});
