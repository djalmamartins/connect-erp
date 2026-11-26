import { StatusBar } from 'expo-status-bar';
import { useState } from 'react';
import { Image, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { colors, fonts, radius, space } from '../src/theme';
import { Body, Button, Field } from '../src/ui/kit';

/**
 * Entrada da equipe no Meu Dia: o mesmo e-mail e senha do ERP (e o código do 2FA quando ativo). Esqueceu a senha?
 * A troca é pela tela de login do ERP no computador.
 */
export default function Login() {
  const { login, verifyTwoFactor, lastLogin } = useAuth();
  const [identity, setIdentity] = useState(lastLogin ?? '');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setError(null);
    if (!challenge && (identity.trim() === '' || password === '')) {
      setError('Informe o e-mail e a senha do ERP.');
      return;
    }
    if (challenge && !/^\d{6}$/.test(code.trim()) && code.trim().length < 8) {
      setError('Digite o código de 6 números (ou um código de recuperação).');
      return;
    }
    setBusy(true);
    try {
      if (challenge) {
        await verifyTwoFactor(challenge, code.trim());
      } else {
        const result = await login(identity.trim(), password);
        if (result.kind === 'twoFactor') setChallenge(result.challenge);
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
            <Text style={styles.title}>{challenge ? 'Confirme que é você' : 'Meu Dia'}</Text>
            {challenge ? (
              <>
                <Body muted>Digite o código de 6 números do seu aplicativo autenticador.</Body>
                <Field label="Código" value={code} onChangeText={setCode} keyboardType="number-pad" autoComplete="one-time-code" textContentType="oneTimeCode" maxLength={20} autoFocus onSubmitEditing={submit} />
              </>
            ) : (
              <>
                <Body muted>Sua agenda de trabalho na Connect. Entre com o mesmo e-mail e senha do ERP.</Body>
                <Field label="E-mail" value={identity} onChangeText={setIdentity} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" autoComplete="username" textContentType="username" />
                <Field label="Senha" value={password} onChangeText={setPassword} secureTextEntry autoComplete="password" textContentType="password" onSubmitEditing={submit} />
              </>
            )}
            {error ? (
              <Text style={styles.error} accessibilityLiveRegion="polite">
                {error}
              </Text>
            ) : null}
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
                Esqueceu a senha? Troque pela tela de login do ERP no computador. Só entra quem tem acesso ao ERP.
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
