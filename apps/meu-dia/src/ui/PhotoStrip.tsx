import Ionicons from '@expo/vector-icons/Ionicons';
import { useState } from 'react';
import { Image, type ImageURISource, Modal, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, radius, space } from '../theme';

/**
 * Miniaturas das fotos do item da vistoria (ADR 0674). Tocar abre a foto na tela inteira; o X fecha.
 * As imagens vêm do GET /inspections/{id}/photos/{photo} com o token do app no cabeçalho.
 */
export function PhotoStrip({ sources, label }: { sources: ImageURISource[]; label: string }) {
  const [open, setOpen] = useState<number | null>(null);
  const insets = useSafeAreaInsets();
  if (sources.length === 0) return null;
  const shown = open !== null ? sources[open] : null;
  return (
    <>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.row}>
        {sources.map((source, i) => (
          <Pressable
            key={source.uri ?? i}
            onPress={() => setOpen(i)}
            accessibilityRole="imagebutton"
            accessibilityLabel={`Ver a foto ${i + 1} de ${sources.length} de ${label}`}
          >
            <Image source={source} style={styles.thumb} resizeMode="cover" />
          </Pressable>
        ))}
      </ScrollView>
      <Modal visible={shown !== null} animationType="fade" transparent={false} onRequestClose={() => setOpen(null)} supportedOrientations={['portrait', 'landscape']}>
        <View style={styles.viewer}>
          {shown ? <Image source={shown} style={styles.full} resizeMode="contain" accessibilityLabel={`Foto de ${label}`} /> : null}
          <Pressable
            onPress={() => setOpen(null)}
            style={[styles.close, { top: insets.top + space[2] }]}
            accessibilityRole="button"
            accessibilityLabel="Fechar a foto"
            hitSlop={12}
          >
            <Ionicons name="close" size={26} color="#fff" />
          </Pressable>
        </View>
      </Modal>
    </>
  );
}

const styles = StyleSheet.create({
  row: { gap: space[2], paddingTop: space[2] },
  thumb: { width: 72, height: 72, borderRadius: radius.md, backgroundColor: colors.brandSoft },
  viewer: { flex: 1, backgroundColor: '#000', alignItems: 'center', justifyContent: 'center' },
  full: { width: '100%', height: '100%' },
  close: { position: 'absolute', right: space[4], width: 44, height: 44, borderRadius: 22, backgroundColor: 'rgba(255,255,255,0.18)', alignItems: 'center', justifyContent: 'center' },
});
