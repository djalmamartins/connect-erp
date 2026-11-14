import Ionicons from '@expo/vector-icons/Ionicons';
import { BlurView } from 'expo-blur';
import { useEffect, useRef, useState, type ComponentProps } from 'react';
import { Animated, Easing, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, fonts, radius, space } from '../theme';

type IconName = ComponentProps<typeof Ionicons>['name'];
export type DialogTone = 'success' | 'error' | 'info' | 'danger';

type Request = {
  title: string;
  message?: string;
  tone: DialogTone;
  icon?: IconName;
  /** Texto do botão principal. */
  confirm: string;
  /** Sem cancelar = aviso de um botão só. */
  cancel?: string;
  resolve: (ok: boolean) => void;
};

const TONES: Record<DialogTone, { icon: IconName; tint: string; bg: string; button: string }> = {
  success: { icon: 'checkmark-circle', tint: colors.success, bg: colors.successSoft, button: colors.brand },
  error: { icon: 'alert-circle', tint: colors.danger, bg: colors.dangerSoft, button: colors.brand },
  info: { icon: 'information-circle', tint: colors.brandStrong, bg: colors.brandSoft, button: colors.brand },
  danger: { icon: 'trash', tint: colors.danger, bg: colors.dangerSoft, button: colors.danger },
};

/**
 * Janelas do próprio app no lugar do Alert do sistema (pedido do Djalma, 14/11/2026): confirmação,
 * aviso e um recado rápido (toast). O DialogHost fica uma vez só no _layout e atende uma fila.
 */
let listener: ((r: Request) => void) | null = null;
let toastListener: ((text: string) => void) | null = null;
const queue: Request[] = [];

function open(r: Omit<Request, 'resolve'>): Promise<boolean> {
  return new Promise((resolve) => {
    const req = { ...r, resolve };
    if (listener) listener(req);
    else queue.push(req);
  });
}

export const dialog = {
  /** Pergunta antes de uma ação. Resolve true se a pessoa confirmou. */
  confirm: (o: { title: string; message?: string; confirm: string; cancel?: string; tone?: DialogTone; icon?: IconName }) =>
    open({ tone: 'danger', cancel: 'Voltar', ...o }),
  /** Aviso com um botão (Entendi). */
  notice: async (o: { title: string; message?: string; tone?: DialogTone; icon?: IconName; confirm?: string }) => {
    await open({ tone: 'info', confirm: 'Entendi', ...o });
  },
  /** Recado rápido no topo, que some sozinho (ex.: "Código copiado"). */
  toast: (text: string) => {
    toastListener?.(text);
  },
};

export function DialogHost() {
  const [current, setCurrent] = useState<Request | null>(null);
  const pending = useRef<Request[]>([]);
  const fade = useRef(new Animated.Value(0)).current;
  const [toast, setToast] = useState<string | null>(null);
  const toastAnim = useRef(new Animated.Value(0)).current;
  const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const insets = useSafeAreaInsets();

  useEffect(() => {
    listener = (r) => {
      setCurrent((c) => {
        if (c) {
          pending.current.push(r);
          return c;
        }
        return r;
      });
    };
    toastListener = (text) => {
      setToast(text);
      if (toastTimer.current) clearTimeout(toastTimer.current);
      Animated.spring(toastAnim, { toValue: 1, useNativeDriver: true, friction: 8 }).start();
      toastTimer.current = setTimeout(() => {
        Animated.timing(toastAnim, { toValue: 0, duration: 200, useNativeDriver: true }).start(() => setToast(null));
      }, 2000);
    };
    if (queue.length > 0) {
      const [first, ...rest] = queue.splice(0);
      pending.current.push(...rest);
      setCurrent(first);
    }
    return () => {
      listener = null;
      toastListener = null;
    };
  }, [toastAnim]);

  useEffect(() => {
    if (current) {
      fade.setValue(0);
      Animated.timing(fade, { toValue: 1, duration: 220, easing: Easing.out(Easing.cubic), useNativeDriver: true }).start();
    }
  }, [current, fade]);

  function close(ok: boolean) {
    const req = current;
    if (!req) return;
    Animated.timing(fade, { toValue: 0, duration: 150, useNativeDriver: true }).start(() => {
      req.resolve(ok);
      setCurrent(pending.current.shift() ?? null);
    });
  }

  const tone = current ? TONES[current.tone] : TONES.info;
  const scale = fade.interpolate({ inputRange: [0, 1], outputRange: [0.92, 1] });
  const toastY = toastAnim.interpolate({ inputRange: [0, 1], outputRange: [-80, 0] });

  return (
    <>
      <Modal visible={!!current} transparent animationType="none" statusBarTranslucent onRequestClose={() => close(false)}>
        <Animated.View style={[StyleSheet.absoluteFill, { opacity: fade }]}>
          <BlurView intensity={20} tint="dark" style={StyleSheet.absoluteFill} />
          <Pressable style={[StyleSheet.absoluteFill, styles.backdrop]} onPress={current?.cancel ? () => close(false) : undefined} accessibilityLabel="Fechar" />
        </Animated.View>
        <View style={styles.center} pointerEvents="box-none">
          {current ? (
            <Animated.View style={[styles.card, { opacity: fade, transform: [{ scale }] }]} accessibilityViewIsModal accessibilityRole="alert">
              <View style={[styles.icon, { backgroundColor: tone.bg }]}>
                <Ionicons name={current.icon ?? tone.icon} size={30} color={tone.tint} />
              </View>
              <Text style={styles.title}>{current.title}</Text>
              {current.message ? <Text style={styles.message}>{current.message}</Text> : null}
              <View style={styles.buttons}>
                <Pressable
                  onPress={() => close(true)}
                  accessibilityRole="button"
                  style={({ pressed }) => [styles.primary, { backgroundColor: tone.button }, pressed && { opacity: 0.85 }]}
                >
                  <Text style={styles.primaryText}>{current.confirm}</Text>
                </Pressable>
                {current.cancel ? (
                  <Pressable onPress={() => close(false)} accessibilityRole="button" style={({ pressed }) => [styles.secondary, pressed && { opacity: 0.6 }]}>
                    <Text style={styles.secondaryText}>{current.cancel}</Text>
                  </Pressable>
                ) : null}
              </View>
            </Animated.View>
          ) : null}
        </View>
      </Modal>
      {toast ? (
        <Animated.View pointerEvents="none" style={[styles.toast, { top: insets.top + 8, opacity: toastAnim, transform: [{ translateY: toastY }] }]}>
          <Ionicons name="checkmark-circle" size={18} color={colors.onBrand} />
          <Text style={styles.toastText} accessibilityLiveRegion="polite">
            {toast}
          </Text>
        </Animated.View>
      ) : null}
    </>
  );
}

const styles = StyleSheet.create({
  backdrop: { backgroundColor: 'rgba(13,46,39,0.35)' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space[5] },
  card: {
    width: '100%',
    maxWidth: 360,
    backgroundColor: colors.surface,
    borderRadius: 28,
    paddingTop: space[6],
    paddingHorizontal: space[5],
    paddingBottom: space[4],
    alignItems: 'center',
    shadowColor: '#0d2e27',
    shadowOpacity: 0.18,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 12 },
    elevation: 12,
  },
  icon: { width: 64, height: 64, borderRadius: 32, alignItems: 'center', justifyContent: 'center', marginBottom: space[3] },
  title: { fontFamily: fonts.bold, fontSize: 19, color: colors.text, textAlign: 'center' },
  message: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.muted, textAlign: 'center', marginTop: space[2] },
  buttons: { alignSelf: 'stretch', gap: space[1], marginTop: space[5] },
  primary: { height: 50, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center' },
  primaryText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.onBrand },
  secondary: { height: 46, alignItems: 'center', justifyContent: 'center' },
  secondaryText: { fontFamily: fonts.semibold, fontSize: 16, color: colors.muted },
  toast: {
    position: 'absolute',
    alignSelf: 'center',
    maxWidth: '92%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    paddingHorizontal: 16,
    minHeight: 42,
    paddingVertical: 10,
    borderRadius: radius.pill,
    backgroundColor: colors.sidebar,
    zIndex: 100,
    shadowColor: '#000',
    shadowOpacity: 0.18,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 6 },
    elevation: 8,
  },
  toastText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.onBrand, flexShrink: 1 },
});
