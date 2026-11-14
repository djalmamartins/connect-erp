import { StatusBar } from 'expo-status-bar';
import { useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
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
  const { login, verifyTwoFactor } = useAuth();
  const [identity, setIdentity] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
        const result = await login(identity.trim(), password);
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
            <Text style={styles.title}>{challenge ? 'Confirme que é você' : 'Área do morador'}</Text>
            {challenge ? (
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
              </>
            )}
            {error ? <Text style={styles.error} accessibilityLiveRegion="polite">{error}</Text> : null}
            <Button title={challenge ? 'Confirmar' : 'Entrar'} onPress={submit} busy={busy} />
            {challenge ? (
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
              <Body muted style={styles.note}>
                Primeiro acesso? Use o link do convite que a administração enviou para o seu e-mail para criar a senha.
                Depois é só entrar aqui.
              </Body>
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
});
