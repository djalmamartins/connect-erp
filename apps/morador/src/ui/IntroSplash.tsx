import { useEffect, useRef } from 'react';
import { AccessibilityInfo, Animated, Easing, StyleSheet, View } from 'react-native';
import { colors, fonts } from '../theme';

const SYMBOL = require('../../assets/intro-symbol.png');
const WORDMARK = require('../../assets/intro-wordmark.png');

// Proporções das imagens (intro-symbol 360x467, intro-wordmark 900x218), recortadas da logo da Connect.
const SYMBOL_H = 76;
const SYMBOL_W = Math.round((SYMBOL_H * 360) / 467);
const WORD_H = 52;
const WORD_W = Math.round((WORD_H * 900) / 218);
const GAP = 12;
// No começo o símbolo fica sozinho no centro; depois anda para a esquerda e o nome entra ao lado.
const SHIFT = (GAP + WORD_W) / 2;

/**
 * Abertura do app: o símbolo dourado aparece com um brilho, anda para o lado, o nome
 * CONNECT entra e a tela some revelando o app. Dura cerca de 2 s; com "Reduzir movimento"
 * ligado no aparelho vira só um esmaecer curto.
 */
export function IntroSplash({ onReady, onDone }: { onReady?: () => void; onDone: () => void }) {
  const symbol = useRef(new Animated.Value(0)).current;
  const glow = useRef(new Animated.Value(0)).current;
  const slide = useRef(new Animated.Value(0)).current;
  const word = useRef(new Animated.Value(0)).current;
  const tagline = useRef(new Animated.Value(0)).current;
  const exit = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    let alive = true;
    AccessibilityInfo.isReduceMotionEnabled()
      .catch(() => false)
      .then((reduce) => {
        if (!alive) {
          return;
        }
        const timing = (value: Animated.Value, duration: number, easing = Easing.out(Easing.cubic)) =>
          Animated.timing(value, { toValue: 1, duration, easing, useNativeDriver: true });
        const sequence = reduce
          ? Animated.sequence([
              Animated.parallel([timing(symbol, 1), timing(slide, 1), timing(word, 1), timing(tagline, 1)]),
              Animated.delay(500),
              timing(exit, 250),
            ])
          : Animated.sequence([
              Animated.parallel([
                Animated.spring(symbol, { toValue: 1, friction: 6, tension: 70, useNativeDriver: true }),
                timing(glow, 900, Easing.out(Easing.quad)),
              ]),
              Animated.parallel([timing(slide, 520, Easing.inOut(Easing.cubic)), Animated.sequence([Animated.delay(140), timing(word, 480)])]),
              timing(tagline, 320),
              Animated.delay(450),
              timing(exit, 380, Easing.in(Easing.cubic)),
            ]);
        sequence.start(({ finished }) => {
          if (finished && alive) {
            onDone();
          }
        });
      });
    return () => {
      alive = false;
    };
  }, [exit, glow, onDone, slide, symbol, tagline, word]);

  const symbolStyle = {
    opacity: symbol.interpolate({ inputRange: [0, 0.4, 1], outputRange: [0, 1, 1] }),
    transform: [
      { translateX: slide.interpolate({ inputRange: [0, 1], outputRange: [SHIFT, 0] }) },
      { scale: Animated.multiply(symbol.interpolate({ inputRange: [0, 1], outputRange: [0.55, 1] }), slide.interpolate({ inputRange: [0, 1], outputRange: [1.3, 1] })) },
      { rotate: symbol.interpolate({ inputRange: [0, 1], outputRange: ['-14deg', '0deg'] }) },
    ],
  };
  const glowStyle = {
    opacity: glow.interpolate({ inputRange: [0, 0.25, 1], outputRange: [0, 0.35, 0] }),
    transform: [
      { translateX: slide.interpolate({ inputRange: [0, 1], outputRange: [SHIFT, 0] }) },
      { scale: glow.interpolate({ inputRange: [0, 1], outputRange: [0.5, 2.6] }) },
    ],
  };
  const wordStyle = {
    opacity: word,
    transform: [{ translateX: word.interpolate({ inputRange: [0, 1], outputRange: [28, 0] }) }],
  };
  const taglineStyle = {
    opacity: tagline,
    transform: [{ translateY: tagline.interpolate({ inputRange: [0, 1], outputRange: [8, 0] }) }],
  };
  const overlayStyle = {
    opacity: exit.interpolate({ inputRange: [0, 1], outputRange: [1, 0] }),
    transform: [{ scale: exit.interpolate({ inputRange: [0, 1], outputRange: [1, 1.06] }) }],
  };

  return (
    <Animated.View
      style={[StyleSheet.absoluteFill, styles.overlay, overlayStyle]}
      onLayout={onReady}
      accessible
      accessibilityLabel="Connect Condomínios"
      pointerEvents="none"
    >
      <View style={styles.row}>
        <View style={styles.symbolBox}>
          <Animated.View style={[styles.glow, glowStyle]} />
          <Animated.Image source={SYMBOL} style={[styles.symbol, symbolStyle]} resizeMode="contain" />
        </View>
        <Animated.Image source={WORDMARK} style={[styles.word, wordStyle]} resizeMode="contain" />
      </View>
      <Animated.Text style={[styles.tagline, taglineStyle]}>Área do morador</Animated.Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  overlay: { backgroundColor: colors.sidebar, alignItems: 'center', justifyContent: 'center', zIndex: 100 },
  row: { flexDirection: 'row', alignItems: 'center', gap: GAP },
  symbolBox: { width: SYMBOL_W, height: SYMBOL_H, alignItems: 'center', justifyContent: 'center' },
  glow: {
    position: 'absolute',
    width: SYMBOL_H,
    height: SYMBOL_H,
    borderRadius: SYMBOL_H / 2,
    backgroundColor: colors.accent,
  },
  symbol: { width: SYMBOL_W, height: SYMBOL_H },
  word: { width: WORD_W, height: WORD_H },
  tagline: { marginTop: 22, fontFamily: fonts.medium, fontSize: 13, letterSpacing: 2.4, textTransform: 'uppercase', color: 'rgba(197,161,49,0.85)' },
});
