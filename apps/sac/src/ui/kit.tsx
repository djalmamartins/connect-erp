import Ionicons from '@expo/vector-icons/Ionicons';
import { BlurView } from 'expo-blur';
import { router } from 'expo-router';
import { useEffect, useRef, type ComponentProps, type ReactNode } from 'react';
import {
  ActivityIndicator,
  Animated,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type NativeScrollEvent,
  type NativeSyntheticEvent,
  type TextInputProps,
  type StyleProp,
  type ViewStyle,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import type { ApiError } from '../api/client';
import { statusLabel, statusTone, type StatusKind, type Tone } from '../lib/format';
import { applyMask, MASK_INPUT, type MaskName } from '../lib/masks';
import { colors, controlHeight, fonts, radius, space } from '../theme';

/** Espaço livre no fim das telas com a barra de baixo flutuante. */
export const TAB_BAR_SPACE = 104;

export function Screen({
  children,
  refreshing,
  onRefresh,
  title,
  subtitle,
  eyebrow,
  back,
  tab,
  right,
  onEndReached,
}: {
  children: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  /** Título grande no topo, no estilo do iPhone; some e vira barra pequena ao rolar. */
  title?: string;
  subtitle?: string;
  /** Linha pequena acima do título, como a data no Início. */
  eyebrow?: string;
  /** Mostra o botão de voltar (telas abertas a partir do Mais ou do Início). */
  back?: boolean | 'close';
  /** Tela de aba: deixa espaço para a barra flutuante. */
  tab?: boolean;
  /** Ação à direita do título, como o avatar no Início. */
  right?: ReactNode;
  /** Chamado perto do fim da rolagem (rolagem infinita, como o histórico de boletos). */
  onEndReached?: () => void;
}) {
  const insets = useSafeAreaInsets();
  const scrollY = useRef(new Animated.Value(0)).current;
  const compact = scrollY.interpolate({ inputRange: [24, 56], outputRange: [0, 1], extrapolate: 'clamp' });
  const barHeight = insets.top + 48;

  return (
    <View style={styles.screen}>
      <Animated.ScrollView
        style={styles.screen}
        contentContainerStyle={[
          styles.screenContent,
          { paddingTop: insets.top + (back ? space[2] : space[4]) },
          tab && { paddingBottom: insets.bottom + TAB_BAR_SPACE },
        ]}
        keyboardShouldPersistTaps="handled"
        scrollEventThrottle={16}
        onScroll={Animated.event([{ nativeEvent: { contentOffset: { y: scrollY } } }], {
          useNativeDriver: true,
          listener: onEndReached
            ? (e: NativeSyntheticEvent<NativeScrollEvent>) => {
                const { contentOffset, layoutMeasurement, contentSize } = e.nativeEvent;
                if (contentOffset.y + layoutMeasurement.height >= contentSize.height - 400) onEndReached();
              }
            : undefined,
        })}
        refreshControl={
          onRefresh ? (
            <RefreshControl
              refreshing={!!refreshing}
              onRefresh={onRefresh}
              // No iPhone a roda nativa fica invisível e quem aparece é a logo (PullLogo). O Android
              // não deixa a tela passar do topo, então lá fica a roda nativa na cor da marca.
              tintColor={Platform.OS === 'ios' ? 'transparent' : colors.brand}
              colors={[colors.brand]}
              progressViewOffset={insets.top}
            />
          ) : undefined
        }
      >
        {back ? <BackButton mode={back} /> : null}
        {title ? (
          <View style={styles.largeHeader}>
            <View style={{ flex: 1 }}>
              {eyebrow ? <Text style={styles.eyebrow}>{eyebrow}</Text> : null}
              <Text style={styles.largeTitle} accessibilityRole="header">
                {title}
              </Text>
              {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
            </View>
            {right}
          </View>
        ) : null}
        {children}
      </Animated.ScrollView>
      {onRefresh && Platform.OS !== 'android' ? <PullLogo scrollY={scrollY} refreshing={!!refreshing} top={insets.top + 6} /> : null}
      {title ? (
        <Animated.View pointerEvents="none" style={[styles.compactBar, { height: barHeight, paddingTop: insets.top, opacity: compact }]}>
          <BlurView intensity={70} tint="light" style={StyleSheet.absoluteFill} />
          <View style={[StyleSheet.absoluteFill, styles.compactTint]} />
          <Text style={styles.compactTitle} numberOfLines={1}>
            {title}
          </Text>
        </Animated.View>
      ) : null}
    </View>
  );
}

const SYMBOL = require('../../assets/intro-symbol.png');
/** Quanto puxar para a logo ficar inteira (o iPhone dispara a atualização perto disso). */
const PULL = 70;

/**
 * Puxar para atualizar com a logo da Connect: ao puxar, o símbolo aparece e cresce; puxando além
 * do ponto, ele estica como elástico. Ao soltar, enquanto carrega, ele quica e pulsa (o carregando).
 */
function PullLogo({ scrollY, refreshing, top }: { scrollY: Animated.Value; refreshing: boolean; top: number }) {
  const spin = useRef(new Animated.Value(0)).current;
  const shown = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    if (!refreshing) {
      Animated.timing(shown, { toValue: 0, duration: 180, useNativeDriver: true }).start(() => spin.setValue(0));
      return;
    }
    shown.setValue(1);
    const loop = Animated.loop(Animated.timing(spin, { toValue: 1, duration: 1100, useNativeDriver: true }));
    loop.start();
    return () => loop.stop();
  }, [refreshing, shown, spin]);

  const pullOpacity = scrollY.interpolate({ inputRange: [-PULL * 0.8, -12, 0], outputRange: [1, 0, 0], extrapolate: 'clamp' });
  const opacity = Animated.add(pullOpacity, shown).interpolate({ inputRange: [0, 1, 2], outputRange: [0, 1, 1], extrapolate: 'clamp' });
  const grow = scrollY.interpolate({ inputRange: [-PULL, 0], outputRange: [1, 0.35], extrapolate: 'clamp' });
  const stretchY = scrollY.interpolate({ inputRange: [-PULL * 2, -PULL, 0], outputRange: [1.35, 1, 1], extrapolate: 'clamp' });
  const stretchX = scrollY.interpolate({ inputRange: [-PULL * 2, -PULL, 0], outputRange: [0.85, 1, 1], extrapolate: 'clamp' });
  const bounce = spin.interpolate({ inputRange: [0, 0.5, 1], outputRange: [0, -7, 0] });
  const pulse = spin.interpolate({ inputRange: [0, 0.5, 1], outputRange: [1, 0.86, 1] });

  return (
    <Animated.View
      pointerEvents="none"
      accessibilityLabel={refreshing ? 'Atualizando' : undefined}
      style={[pullStyles.wrap, { top, opacity }]}
    >
      <Animated.Image
        source={SYMBOL}
        resizeMode="contain"
        style={[pullStyles.symbol, { transform: [{ scale: refreshing ? 1 : grow }, { scaleX: stretchX }, { scaleY: stretchY }, { translateY: bounce }, { scale: pulse }] }]}
      />
    </Animated.View>
  );
}

const pullStyles = StyleSheet.create({
  wrap: { position: 'absolute', left: 0, right: 0, alignItems: 'center', zIndex: 5 },
  symbol: { width: 30, height: 39 },
});

function BackButton({ mode }: { mode: true | 'close' }) {
  const close = mode === 'close';
  return (
    <Pressable
      onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))}
      accessibilityRole="button"
      accessibilityLabel={close ? 'Fechar' : 'Voltar'}
      hitSlop={10}
      style={({ pressed }) => [styles.backButton, pressed && styles.pressed]}
    >
      <Ionicons name={close ? 'close' : 'chevron-back'} size={22} color={colors.text} />
    </Pressable>
  );
}

export function Title({ children }: { children: ReactNode }) {
  return <Text style={styles.title}>{children}</Text>;
}

export function SectionTitle({ children }: { children: ReactNode }) {
  return <Text style={styles.sectionTitle}>{children}</Text>;
}

export function Body({ children, muted, style }: { children: ReactNode; muted?: boolean; style?: object }) {
  return <Text style={[styles.body, muted && styles.muted, style]}>{children}</Text>;
}

export function Card({ children, style, onPress, accessibilityLabel }: { children: ReactNode; style?: StyleProp<ViewStyle>; onPress?: () => void; accessibilityLabel?: string }) {
  if (onPress) {
    return (
      <Pressable
        onPress={onPress}
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel}
        style={({ pressed }) => [styles.card, pressed && styles.pressed, style]}
      >
        {children}
      </Pressable>
    );
  }
  return <View style={[styles.card, style]}>{children}</View>;
}

export function Row({ children, style }: { children: ReactNode; style?: ViewStyle }) {
  return <View style={[styles.row, style]}>{children}</View>;
}

const TONES: Record<Tone, { bg: string; fg: string }> = {
  success: { bg: colors.successSoft, fg: colors.success },
  warning: { bg: colors.warningSoft, fg: colors.warning },
  danger: { bg: colors.dangerSoft, fg: colors.danger },
  info: { bg: colors.infoSoft, fg: colors.info },
  neutral: { bg: colors.surfaceMuted, fg: colors.muted },
};

export function StatusBadge({ kind, status }: { kind: StatusKind; status: string }) {
  const tone = TONES[statusTone(kind, status)];
  return (
    <View style={[styles.badge, { backgroundColor: tone.bg }]}>
      <Text style={[styles.badgeText, { color: tone.fg }]}>{statusLabel(kind, status)}</Text>
    </View>
  );
}

export function Button({
  title,
  onPress,
  variant = 'primary',
  disabled,
  busy,
}: {
  title: string;
  onPress: () => void;
  variant?: 'primary' | 'secondary' | 'danger';
  disabled?: boolean;
  busy?: boolean;
}) {
  const off = disabled || busy;
  return (
    <Pressable
      onPress={onPress}
      disabled={off}
      accessibilityRole="button"
      accessibilityState={{ disabled: !!off, busy: !!busy }}
      style={({ pressed }) => [
        styles.button,
        variant === 'secondary' && styles.buttonSecondary,
        variant === 'danger' && styles.buttonDanger,
        pressed && styles.pressed,
        off && styles.disabled,
      ]}
    >
      {busy ? (
        <ActivityIndicator color={variant === 'primary' ? colors.onBrand : colors.brand} />
      ) : (
        <Text
          style={[
            styles.buttonText,
            variant === 'secondary' && { color: colors.brandStrong },
            variant === 'danger' && { color: colors.danger },
          ]}
        >
          {title}
        </Text>
      )}
    </Pressable>
  );
}

/** mask: digita só os números e o campo põe barras, pontos e traço sozinho (src/lib/masks.ts). */
export function Field({ label, hint, error, mask, ...input }: TextInputProps & { label: string; hint?: string; error?: string; mask?: MaskName }) {
  const masked = mask ? MASK_INPUT[mask] : null;
  const onChangeText = mask && input.onChangeText ? (text: string) => input.onChangeText?.(applyMask(mask, text)) : input.onChangeText;
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        placeholderTextColor={colors.faint}
        style={[styles.input, input.multiline && styles.inputMultiline, !!error && styles.inputError]}
        accessibilityLabel={label}
        {...(masked ? { keyboardType: masked.keyboardType, maxLength: masked.maxLength, placeholder: masked.placeholder } : {})}
        {...input}
        onChangeText={onChangeText}
      />
      {error ? <Text style={styles.fieldError}>{error}</Text> : hint ? <Text style={styles.hint}>{hint}</Text> : null}
    </View>
  );
}

export function Choice<T extends string | number>({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: { value: T; label: string }[];
  value: T | null;
  onChange: (value: T) => void;
}) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <View style={styles.chips}>
        {options.map((option) => {
          const active = option.value === value;
          return (
            <Pressable
              key={String(option.value)}
              onPress={() => onChange(option.value)}
              accessibilityRole="radio"
              accessibilityState={{ selected: active }}
              style={[styles.chip, active && styles.chipActive]}
            >
              <Text style={[styles.chipText, active && styles.chipTextActive]}>{option.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

export function Loading() {
  return (
    <View style={styles.center}>
      <ActivityIndicator color={colors.brand} size="large" />
    </View>
  );
}

export function Empty({ text }: { text: string }) {
  return (
    <Card style={styles.emptyCard}>
      <Body muted>{text}</Body>
    </Card>
  );
}

export function ErrorBox({ error, onRetry }: { error: ApiError; onRetry?: () => void }) {
  if (error.isFeatureDisabled) {
    return (
      <Card style={styles.emptyCard}>
        <Text style={styles.cardTitle}>Função desligada</Text>
        <Body muted>O seu condomínio ainda não liberou esta função no app. Fale com a administração se precisar.</Body>
      </Card>
    );
  }
  return (
    <Card style={{ ...styles.emptyCard, backgroundColor: colors.dangerSoft, borderColor: colors.dangerSoft }}>
      <Body style={{ color: colors.danger }}>{error.message}</Body>
      {onRetry ? (
        <View style={{ marginTop: space[3] }}>
          <Button title="Tentar de novo" variant="secondary" onPress={onRetry} />
        </View>
      ) : null}
    </Card>
  );
}

export function CardTitle({ children }: { children: ReactNode }) {
  return <Text style={styles.cardTitle}>{children}</Text>;
}

export const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.canvas },
  screenContent: { padding: space[4], gap: space[3], paddingBottom: space[7] },
  largeHeader: { flexDirection: 'row', alignItems: 'flex-end', gap: space[3], marginBottom: space[2] },
  largeTitle: { fontFamily: fonts.bold, fontSize: 34, lineHeight: 41, letterSpacing: -0.6, color: colors.text },
  eyebrow: { fontFamily: fonts.semibold, fontSize: 13, letterSpacing: 0.6, textTransform: 'uppercase', color: colors.muted, marginBottom: 2 },
  subtitle: { fontFamily: fonts.medium, fontSize: 15, color: colors.muted, marginTop: 2 },
  compactBar: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    alignItems: 'center',
    justifyContent: 'center',
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: 'rgba(18,26,49,0.12)',
    overflow: 'hidden',
  },
  compactTint: { backgroundColor: 'rgba(241,242,241,0.78)' },
  compactTitle: { fontFamily: fonts.semibold, fontSize: 17, color: colors.text, paddingHorizontal: 64 },
  backButton: {
    width: 38,
    height: 38,
    borderRadius: 19,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surface,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    marginBottom: space[1],
  },
  title: { fontFamily: fonts.bold, fontSize: 28, lineHeight: 34, letterSpacing: -0.4, color: colors.text, marginBottom: space[1] },
  sectionTitle: { fontFamily: fonts.bold, fontSize: 20, letterSpacing: -0.3, color: colors.text, marginTop: space[4] },
  body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.text },
  muted: { color: colors.muted },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    padding: space[4],
    gap: space[2],
    shadowColor: '#0d2e27',
    shadowOpacity: 0.06,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 4 },
    elevation: 1,
  },
  cardTitle: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text, flexShrink: 1 },
  row: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: space[3] },
  pressed: { opacity: 0.75 },
  disabled: { opacity: 0.5 },
  badge: { borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 3, alignSelf: 'flex-start' },
  badgeText: { fontFamily: fonts.semibold, fontSize: 12 },
  button: {
    minHeight: controlHeight,
    borderRadius: radius.md,
    backgroundColor: colors.brand,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: space[4],
  },
  buttonSecondary: { backgroundColor: colors.brandSoft },
  buttonDanger: { backgroundColor: colors.dangerSoft },
  buttonText: { fontFamily: fonts.semibold, fontSize: 15, color: colors.onBrand },
  field: { gap: 6 },
  label: { fontFamily: fonts.medium, fontSize: 14, color: colors.text },
  input: {
    minHeight: controlHeight,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    paddingHorizontal: space[3],
    fontFamily: fonts.regular,
    fontSize: 15,
    color: colors.text,
  },
  inputMultiline: { minHeight: 110, paddingTop: space[3], textAlignVertical: 'top' },
  inputError: { borderColor: colors.danger },
  hint: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  fieldError: { fontFamily: fonts.regular, fontSize: 13, color: colors.danger },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: space[2] },
  chip: {
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    paddingHorizontal: space[3],
    paddingVertical: 8,
  },
  chipActive: { backgroundColor: colors.accentSoft, borderColor: '#c5a131' },
  chipText: { fontFamily: fonts.medium, fontSize: 14, color: colors.text },
  chipTextActive: { color: '#6b5510', fontFamily: fonts.semibold },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space[7], backgroundColor: colors.canvas },
  emptyCard: { alignItems: 'flex-start' },
});

/**
 * Linha de lista com ícone na frente (padrão das listas do app: comunicados, reservas, assembleias,
 * boletos, chamados). O ícone fica num quadrado de cor suave; à direita, um valor opcional ou a seta.
 */
export function IconRow({
  icon,
  tint = colors.brandStrong,
  bg = colors.brandSoft,
  title,
  subtitle,
  right,
  onPress,
  accessibilityLabel,
  children,
}: {
  icon: ComponentProps<typeof Ionicons>['name'];
  tint?: string;
  bg?: string;
  title: string;
  subtitle?: string;
  right?: ReactNode;
  onPress?: () => void;
  accessibilityLabel?: string;
  children?: ReactNode;
}) {
  return (
    <Card onPress={onPress} accessibilityLabel={accessibilityLabel ?? title}>
      <View style={iconRowStyles.row}>
        <View style={[iconRowStyles.icon, { backgroundColor: bg }]}>
          <Ionicons name={icon} size={20} color={tint} />
        </View>
        <View style={{ flex: 1, gap: 2 }}>
          <Text style={iconRowStyles.title} numberOfLines={2}>
            {title}
          </Text>
          {subtitle ? <Text style={iconRowStyles.subtitle}>{subtitle}</Text> : null}
        </View>
        {right ?? (onPress ? <Ionicons name="chevron-forward" size={18} color={colors.faint} /> : null)}
      </View>
      {children}
    </Card>
  );
}

const iconRowStyles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space[3] },
  icon: { width: 42, height: 42, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  title: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  subtitle: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
});
