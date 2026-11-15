import Ionicons from '@expo/vector-icons/Ionicons';
import { router, useLocalSearchParams } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useState, type ComponentProps } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ApiError } from '../src/api/client';
import { useAuth } from '../src/auth/AuthContext';
import { useProfile } from '../src/auth/ProfileContext';
import { pickPhoto, type PhotoSource } from '../src/lib/photo';
import { colors, fonts, radius, space } from '../src/theme';
import { Avatar } from '../src/ui/Avatar';
import { dialog } from '../src/ui/Dialog';

type IconName = ComponentProps<typeof Ionicons>['name'];

/** Dicas do passo a passo, na ordem do menu de baixo. */
const TIPS: { icon: IconName; title: string; text: string; color: string }[] = [
  {
    icon: 'home',
    color: '#07884c',
    title: 'Início',
    text: 'Os serviços que você mais usa ficam em botões grandes: boletos, reservas, chamados, visitantes, documentos e prestação de contas. O cartão verde mostra o que está em aberto, e o olho esconde os valores.',
  },
  {
    icon: 'flash',
    color: '#c5a131',
    title: 'Resolva fácil',
    text: 'Tudo o que você resolve sem ligar para ninguém: 2ª via e Pix do boleto, reserva de áreas, assembleia digital, documentos e prestação de contas do condomínio.',
  },
  {
    icon: 'business',
    color: '#18587d',
    title: 'Unidade',
    text: 'Autorize a entrada de visitantes, veja as encomendas que chegaram na portaria, cadastre pets e veículos e acompanhe os seus chamados.',
  },
  {
    icon: 'notifications',
    color: '#d92d36',
    title: 'Notificações',
    text: 'O sino ao lado da sua foto avisa quando sai um boleto, quando o síndico aprova uma reserva e quando chega um comunicado. O número mostra o que é novo.',
  },
  {
    icon: 'person-circle',
    color: '#6d4bd8',
    title: 'Perfil',
    text: 'Troque a sua foto, ligue a entrada com Face ID ou digital e, se quiser mais segurança, a verificação em duas etapas.',
  },
];

/**
 * Boas-vindas no primeiro acesso (pedido do Djalma, 15/11/2026): foto do perfil e um passo a passo
 * com dicas. Também abre pelo Perfil ("Rever as dicas"), sem marcar nada de novo no servidor.
 */
export default function BoasVindas() {
  const { me } = useAuth();
  const { onboarding, setPhoto, completeOnboarding } = useProfile();
  const { rever } = useLocalSearchParams<{ rever?: string }>();
  const review = rever === '1';
  const [step, setStep] = useState(review ? 1 : 0);
  const [busy, setBusy] = useState<PhotoSource | null>(null);
  const name = me?.current?.name ?? me?.user.name ?? '';
  const first = name.split(' ')[0] || '';
  const last = TIPS.length;
  const hasPhoto = !!onboarding?.has_photo;

  async function photo(source: PhotoSource) {
    const file = await pickPhoto(source);
    if (!file) {
      return;
    }
    setBusy(source);
    try {
      await setPhoto(file);
      dialog.toast('Foto salva');
    } catch (e) {
      await dialog.notice({
        title: 'Não deu para salvar a foto',
        message: e instanceof ApiError ? (e.fields.photo ?? e.message) : 'Tente de novo em instantes.',
        tone: 'error',
      });
    } finally {
      setBusy(null);
    }
  }

  async function finish() {
    if (!review) {
      await completeOnboarding();
    }
    if (router.canGoBack() && review) {
      router.back();
    } else {
      router.replace('/');
    }
  }

  const tip = step > 0 ? TIPS[step - 1] : null;

  return (
    <SafeAreaView style={styles.page} edges={['top', 'bottom']}>
      <StatusBar style="light" />
      <View style={styles.top}>
        <View style={styles.dots} accessibilityLabel={`Passo ${step + 1} de ${last + 1}`}>
          {Array.from({ length: last + 1 }, (_, i) => (
            <View key={i} style={[styles.dot, i === step && styles.dotOn]} />
          ))}
        </View>
        {step < last ? (
          <Pressable onPress={finish} accessibilityRole="button" hitSlop={10}>
            <Text style={styles.skip}>Pular</Text>
          </Pressable>
        ) : null}
      </View>
      <ScrollView contentContainerStyle={styles.body}>
        {tip ? (
          <>
            <View style={[styles.tipIcon, { backgroundColor: tip.color }]}>
              <Ionicons name={tip.icon} size={46} color="#ffffff" />
            </View>
            <Text style={styles.eyebrow}>Dica {step} de {last}</Text>
            <Text style={styles.title}>{tip.title}</Text>
            <Text style={styles.text}>{tip.text}</Text>
          </>
        ) : (
          <>
            <Avatar name={name} size={128} />
            <Text style={styles.title}>Boas-vindas{first ? `, ${first}` : ''}!</Text>
            <Text style={styles.text}>
              Este é o app do seu condomínio. Para começar, coloque uma foto no seu perfil. Ela aparece para você no app e
              ajuda a portaria a reconhecer quem é morador.
            </Text>
            <View style={styles.photoButtons}>
              <PhotoButton icon="camera" label="Tirar foto" busy={busy === 'camera'} onPress={() => photo('camera')} />
              <PhotoButton icon="images" label="Escolher da galeria" busy={busy === 'library'} onPress={() => photo('library')} />
            </View>
          </>
        )}
      </ScrollView>
      <View style={styles.footer}>
        <Pressable
          onPress={() => (step < last ? setStep(step + 1) : finish())}
          accessibilityRole="button"
          style={({ pressed }) => [styles.next, pressed && { opacity: 0.85 }]}
        >
          <Text style={styles.nextText}>{step === 0 ? (hasPhoto ? 'Continuar' : 'Agora não') : step < last ? 'Próxima dica' : 'Começar'}</Text>
          <Ionicons name={step < last ? 'arrow-forward' : 'checkmark'} size={20} color={colors.sidebar} />
        </Pressable>
        {step > (review ? 1 : 0) ? (
          <Pressable onPress={() => setStep(step - 1)} accessibilityRole="button" style={styles.back} hitSlop={6}>
            <Text style={styles.backText}>Voltar</Text>
          </Pressable>
        ) : null}
      </View>
    </SafeAreaView>
  );
}

function PhotoButton({ icon, label, busy, onPress }: { icon: IconName; label: string; busy: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      disabled={busy}
      accessibilityRole="button"
      accessibilityState={{ busy }}
      style={({ pressed }) => [styles.photoButton, (pressed || busy) && { opacity: 0.7 }]}
    >
      <Ionicons name={icon} size={24} color={colors.accent} />
      <Text style={styles.photoText}>{busy ? 'Enviando…' : label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.sidebar },
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: space[5], paddingTop: space[3], minHeight: 44 },
  dots: { flexDirection: 'row', gap: 6 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: 'rgba(255,255,255,0.25)' },
  dotOn: { width: 24, backgroundColor: colors.accent },
  skip: { fontFamily: fonts.semibold, fontSize: 16, color: colors.sidebarInk },
  body: { flexGrow: 1, alignItems: 'center', justifyContent: 'center', padding: space[6], gap: space[4] },
  tipIcon: { width: 112, height: 112, borderRadius: 34, alignItems: 'center', justifyContent: 'center', borderWidth: 3, borderColor: 'rgba(255,255,255,0.18)' },
  eyebrow: { fontFamily: fonts.semibold, fontSize: 13, letterSpacing: 0.6, textTransform: 'uppercase', color: colors.accent, marginTop: space[2] },
  title: { fontFamily: fonts.bold, fontSize: 28, color: '#ffffff', textAlign: 'center' },
  text: { fontFamily: fonts.regular, fontSize: 17, lineHeight: 25, color: colors.sidebarInk, textAlign: 'center', maxWidth: 420 },
  photoButtons: { flexDirection: 'row', gap: space[3], alignSelf: 'stretch', marginTop: space[2] },
  photoButton: {
    flex: 1,
    minHeight: 96,
    borderRadius: 22,
    backgroundColor: 'rgba(255,255,255,0.08)',
    borderWidth: 1,
    borderColor: 'rgba(197,161,49,0.45)',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space[2],
    padding: space[3],
  },
  photoText: { fontFamily: fonts.semibold, fontSize: 15, color: '#ffffff', textAlign: 'center' },
  footer: { paddingHorizontal: space[5], paddingBottom: space[4], gap: space[1] },
  next: {
    height: 54,
    borderRadius: radius.pill,
    backgroundColor: colors.accent,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: space[2],
  },
  nextText: { fontFamily: fonts.bold, fontSize: 17, color: colors.sidebar },
  back: { height: 44, alignItems: 'center', justifyContent: 'center' },
  backText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.sidebarInk },
});
