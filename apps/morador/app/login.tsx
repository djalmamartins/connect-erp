import Ionicons from '@expo/vector-icons/Ionicons';
import { LinearGradient } from 'expo-linear-gradient';
import { StatusBar } from 'expo-status-bar';
import { useState, type ComponentProps } from 'react';
import {
  ActivityIndicator,
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type TextInputProps,
  type TextStyle,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { LOGIN_EYEBROW, LOGIN_QUOTE, loginCopy, loginStep } from '../src/lib/loginScreen';
import { fonts, space } from '../src/theme';

/**
 * Entrada do morador, no estilo da tela de login do ERP (ADR 0657): foto do prédio com o título
 * e as dicas de segurança, cartão branco com campos de ícone, link no verde da Connect e botão
 * verde-escuro com a seta dourada. No celular a foto fica no alto e o cartão sobe sobre ela.
 * A conta nasce pelo convite enviado por e-mail pela administração (ADR 0090); aqui o morador
 * só entra com e-mail ou CPF e senha, e o código do 2FA quando ativo. O aceite dos termos (LGPD,
 * ADR 0604) continua logo depois da entrada, na tela termos.tsx.
 */

/** Cores da tela de login do ERP (container/cms/studio/v1/assets/css/admin.css, .connect-login). */
const login = {
  page: '#ede9df',
  ink: '#082618',
  label: '#132b24',
  muted: '#70736f',
  border: '#d7d9d6',
  focus: '#b5942e',
  eyebrow: '#94791f',
  gold: '#d2ad3b',
  link: '#0d4b39',
  lock: '#176046',
  danger: '#b42318',
  shade: ['rgba(3,35,28,0.35)', 'rgba(3,35,28,0.62)', 'rgba(3,35,28,0.96)'] as const,
  button: ['#073628', '#00503b'] as const,
};

type IconName = ComponentProps<typeof Ionicons>['name'];

/** Na prévia web o cartão já marca o foco na borda dourada; sem o contorno padrão do navegador. */
const webNoOutline = (Platform.OS === 'web' ? { outlineStyle: 'none' } : {}) as TextStyle;

function LoginField({
  label,
  icon,
  secret,
  ...input
}: TextInputProps & { label: string; icon: IconName; secret?: boolean }) {
  const [focused, setFocused] = useState(false);
  const [visible, setVisible] = useState(false);
  return (
    <View style={styles.fieldWrap}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <View style={[styles.field, focused && styles.fieldFocused]}>
        <Ionicons name={icon} size={20} color="#7f827f" style={styles.fieldIcon} />
        <TextInput
          {...input}
          accessibilityLabel={label}
          placeholderTextColor="#9a9d99"
          secureTextEntry={secret && !visible}
          style={[styles.fieldInput, webNoOutline]}
          onFocus={(e) => {
            setFocused(true);
            input.onFocus?.(e);
          }}
          onBlur={(e) => {
            setFocused(false);
            input.onBlur?.(e);
          }}
        />
        {secret ? (
          <Pressable
            onPress={() => setVisible((v) => !v)}
            accessibilityRole="button"
            accessibilityLabel={visible ? 'Ocultar senha' : 'Mostrar senha'}
            hitSlop={8}
            style={styles.eye}
          >
            <Ionicons name={visible ? 'eye-off-outline' : 'eye-outline'} size={21} color={visible ? '#b28e23' : '#777b78'} />
          </Pressable>
        ) : null}
      </View>
    </View>
  );
}

function SubmitButton({ title, onPress, busy }: { title: string; onPress: () => void; busy: boolean }) {
  return (
    <Pressable
      onPress={onPress}
      disabled={busy}
      accessibilityRole="button"
      accessibilityState={{ busy, disabled: busy }}
      style={({ pressed }) => [styles.submitShadow, pressed && { opacity: 0.88 }]}
    >
      <LinearGradient colors={login.button} start={{ x: 0, y: 0 }} end={{ x: 1, y: 0 }} style={styles.submit}>
        {busy ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <>
            <Text style={styles.submitText}>{title}</Text>
            <Text style={styles.submitArrow}>→</Text>
          </>
        )}
      </LinearGradient>
    </Pressable>
  );
}

function TextLink({ title, onPress }: { title: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} accessibilityRole="link" hitSlop={8}>
      <Text style={styles.link}>{title}</Text>
    </Pressable>
  );
}

export default function Login() {
  const insets = useSafeAreaInsets();
  const { login: signIn, verifyTwoFactor, biometric, lastLogin, api } = useAuth();
  const [forgot, setForgot] = useState(false);
  const [sent, setSent] = useState<string | null>(null);
  const [identity, setIdentity] = useState(lastLogin ?? '');
  const [useBio, setUseBio] = useState(biometric.available);
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const copy = loginCopy(loginStep(forgot, sent !== null, challenge !== null));

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
    setChallenge(null);
    setCode('');
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
        const result = await signIn(identity.trim(), password, biometric.available && useBio);
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
    <View style={styles.page}>
      <StatusBar style="light" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ flexGrow: 1, paddingBottom: insets.bottom + space[6] }} keyboardShouldPersistTaps="handled">
          <View style={styles.hero}>
            <Image source={require('../assets/login-cover.jpg')} resizeMode="cover" style={styles.heroImage} accessibilityIgnoresInvertColors />
            <LinearGradient colors={login.shade} locations={[0, 0.45, 1]} style={StyleSheet.absoluteFill} />
            <View style={[styles.heroContent, { paddingTop: insets.top + space[5] }]}>
              <Image source={require('../assets/logo.png')} style={styles.heroLogo} resizeMode="contain" accessibilityLabel="Connect Condomínios" />
              <View style={styles.heroText}>
                <View style={styles.goldLine} />
                <Text style={styles.heroTitle} accessibilityRole="header">
                  {copy.hero[0]}
                  {'\n'}
                  <Text style={styles.heroStrong}>{copy.hero[1]}</Text>
                </Text>
                <Text style={styles.heroLead}>{copy.lead}</Text>
              </View>
            </View>
          </View>

          <View style={styles.card}>
            <View style={styles.cardHeader}>
              <Text style={styles.eyebrow}>{LOGIN_EYEBROW}</Text>
              <Text style={styles.cardTitle}>{copy.cardTitle}</Text>
              <Text style={styles.cardLead}>{copy.cardLead}</Text>
            </View>

            {forgot && sent ? (
              <View style={styles.sent}>
                <View style={styles.sentIcon}>
                  <Ionicons name="mail-unread-outline" size={28} color={login.link} />
                </View>
                <Text style={styles.sentText}>{sent}</Text>
                <Text style={styles.note}>
                  Abra o link no celular ou no computador para criar a senha nova. Ele vale por pouco tempo. Se não chegar, olhe a caixa de spam.
                </Text>
              </View>
            ) : forgot ? (
              <LoginField
                label="E-mail ou CPF"
                icon="mail-outline"
                value={identity}
                onChangeText={setIdentity}
                placeholder="seu@email.com ou CPF"
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="email-address"
                autoComplete="username"
                textContentType="username"
                onSubmitEditing={askReset}
                autoFocus
              />
            ) : challenge ? (
              <LoginField
                label="Código"
                icon="keypad-outline"
                value={code}
                onChangeText={setCode}
                placeholder="000000"
                keyboardType="number-pad"
                autoComplete="one-time-code"
                textContentType="oneTimeCode"
                maxLength={6}
                onSubmitEditing={submit}
                autoFocus
              />
            ) : (
              <>
                <LoginField
                  label="E-mail ou CPF"
                  icon="mail-outline"
                  value={identity}
                  onChangeText={setIdentity}
                  placeholder="seu@email.com ou CPF"
                  autoCapitalize="none"
                  autoCorrect={false}
                  keyboardType="email-address"
                  autoComplete="username"
                  textContentType="username"
                />
                <LoginField
                  label="Senha"
                  icon="lock-closed-outline"
                  secret
                  value={password}
                  onChangeText={setPassword}
                  placeholder="Sua senha"
                  autoComplete="password"
                  textContentType="password"
                  onSubmitEditing={submit}
                />
              </>
            )}

            {error ? (
              <Text style={styles.error} accessibilityLiveRegion="polite">
                {error}
              </Text>
            ) : null}

            {!forgot && !challenge ? (
              <View style={styles.options}>
                {biometric.available ? (
                  <Pressable
                    onPress={() => setUseBio((v) => !v)}
                    accessibilityRole="checkbox"
                    accessibilityState={{ checked: useBio }}
                    style={styles.check}
                    hitSlop={8}
                  >
                    <Ionicons name={useBio ? 'checkbox' : 'square-outline'} size={18} color={useBio ? login.link : '#8a8d89'} />
                    <Text style={styles.checkText}>Entrar com {biometric.label}</Text>
                  </Pressable>
                ) : (
                  <Text style={styles.optionsHint}>Área restrita</Text>
                )}
                <TextLink
                  title="Esqueci minha senha"
                  onPress={() => {
                    setForgot(true);
                    setError(null);
                  }}
                />
              </View>
            ) : (
              <View style={styles.options}>
                <Text style={styles.optionsHint}>Área restrita</Text>
                <TextLink title="Voltar para o login" onPress={backToLogin} />
              </View>
            )}

            {forgot && sent ? null : (
              <SubmitButton
                title={forgot ? 'Enviar link' : challenge ? 'Confirmar' : 'Entrar'}
                onPress={forgot ? askReset : submit}
                busy={busy}
              />
            )}

            {!forgot && !challenge ? (
              <Text style={styles.note}>
                Primeiro acesso? Use o link do convite que a administração enviou para o seu e-mail para criar a senha.
              </Text>
            ) : null}

            <View style={styles.footer}>
              <Ionicons name="lock-closed-outline" size={14} color={login.lock} />
              <Text style={styles.footerText}>Ambiente seguro e monitorado</Text>
            </View>
          </View>

          <View style={styles.tips} accessibilityLabel="Dicas de segurança">
            {copy.tips.map((tip) => (
              <View key={tip.title} style={styles.tip}>
                <View style={styles.tipIcon}>
                  <Ionicons name={tip.icon} size={22} color={login.gold} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.tipTitle}>{tip.title}</Text>
                  <Text style={styles.tipText}>{tip.text}</Text>
                </View>
              </View>
            ))}
            <Text style={styles.quote}>“{LOGIN_QUOTE}”</Text>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: login.page },
  hero: { minHeight: 330, backgroundColor: login.ink, overflow: 'hidden' },
  heroImage: { position: 'absolute', top: 0, left: 0, width: '100%', height: '100%' },
  heroContent: { flex: 1, paddingHorizontal: space[6], paddingBottom: 96, justifyContent: 'space-between', gap: space[6] },
  heroLogo: { width: 190, height: 63, marginLeft: -12 },
  heroText: { gap: space[3] },
  goldLine: { width: 56, height: 2, backgroundColor: '#c5a131' },
  heroTitle: { fontFamily: fonts.bold, fontSize: 27, lineHeight: 33, color: '#fff', letterSpacing: -0.5 },
  heroStrong: { color: login.gold },
  heroLead: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: 'rgba(255,255,255,0.92)' },
  card: {
    marginTop: -72,
    marginHorizontal: space[4] + 2,
    paddingHorizontal: space[6],
    paddingTop: space[7],
    paddingBottom: space[6],
    backgroundColor: '#fff',
    borderRadius: 18,
    gap: space[4],
    shadowColor: '#2a2312',
    shadowOpacity: 0.16,
    shadowRadius: 30,
    shadowOffset: { width: 0, height: 18 },
    elevation: 8,
  },
  cardHeader: { alignItems: 'center', gap: space[2], marginBottom: space[2] },
  eyebrow: { fontFamily: fonts.bold, fontSize: 10, letterSpacing: 2, color: login.eyebrow, marginBottom: space[2] },
  cardTitle: { fontFamily: fonts.bold, fontSize: 23, color: login.ink, textAlign: 'center' },
  cardLead: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: login.muted, textAlign: 'center' },
  fieldWrap: { gap: 9 },
  fieldLabel: { fontFamily: fonts.bold, fontSize: 13, color: login.label },
  field: { flexDirection: 'row', alignItems: 'center', height: 55, borderWidth: 1, borderColor: login.border, borderRadius: 9, backgroundColor: '#fff' },
  fieldFocused: { borderColor: login.focus },
  fieldIcon: { marginLeft: 16 },
  fieldInput: { flex: 1, minWidth: 0, width: '100%', height: '100%', paddingHorizontal: 13, fontFamily: fonts.regular, fontSize: 15, color: '#10231d' },
  eye: { width: 48, height: '100%', alignItems: 'center', justifyContent: 'center' },
  options: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space[3], marginTop: -space[1] },
  optionsHint: { fontFamily: fonts.medium, fontSize: 12, color: login.link },
  check: { flexDirection: 'row', alignItems: 'center', gap: 6, flexShrink: 1 },
  checkText: { fontFamily: fonts.regular, fontSize: 12, color: '#66706c', flexShrink: 1 },
  link: { fontFamily: fonts.semibold, fontSize: 13, color: login.link, textDecorationLine: 'underline' },
  submitShadow: {
    borderRadius: 8,
    shadowColor: '#004331',
    shadowOpacity: 0.18,
    shadowRadius: 22,
    shadowOffset: { width: 0, height: 9 },
    elevation: 4,
  },
  submit: { height: 55, borderRadius: 8, alignItems: 'center', justifyContent: 'center' },
  submitText: { fontFamily: fonts.bold, fontSize: 15, color: '#fff' },
  submitArrow: { position: 'absolute', right: 20, fontSize: 24, color: login.gold },
  error: { fontFamily: fonts.medium, fontSize: 14, color: login.danger },
  note: { fontFamily: fonts.regular, fontSize: 12, lineHeight: 18, color: '#66706c', textAlign: 'center' },
  sent: { alignItems: 'center', gap: space[3] },
  sentIcon: { width: 56, height: 56, borderRadius: 28, backgroundColor: '#e7f1ec', alignItems: 'center', justifyContent: 'center' },
  sentText: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: login.ink, textAlign: 'center' },
  footer: {
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    gap: space[2],
    marginTop: space[3],
    paddingTop: space[5],
    borderTopWidth: 1,
    borderTopColor: '#e4e5e2',
  },
  footerText: { fontFamily: fonts.regular, fontSize: 12, color: '#6d716e' },
  tips: { marginTop: space[6], marginHorizontal: space[4] + 2, padding: space[5], gap: space[3], backgroundColor: login.ink, borderRadius: 18 },
  tip: {
    flexDirection: 'row',
    gap: space[3],
    padding: space[3] + 2,
    borderRadius: 13,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.1)',
    backgroundColor: 'rgba(255,255,255,0.04)',
  },
  tipIcon: { width: 30, alignItems: 'center', paddingTop: 2 },
  tipTitle: { fontFamily: fonts.bold, fontSize: 14, color: '#fff', marginBottom: 3 },
  tipText: { fontFamily: fonts.regular, fontSize: 12, lineHeight: 18, color: 'rgba(255,255,255,0.84)' },
  quote: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 20, color: '#fff', marginTop: space[2], fontStyle: 'italic' },
});
