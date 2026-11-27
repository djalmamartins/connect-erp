import Ionicons from '@expo/vector-icons/Ionicons';
import { StyleSheet, Text, View } from 'react-native';
import { savedLabel } from '../lib/offline';
import { colors, fonts, radius, space } from '../theme';

/** Aviso amarelo de "sem conexão" com a hora da cópia mostrada (ADR 0669). */
export function OfflineBanner({ savedAt }: { savedAt: string }) {
  return (
    <View style={styles.box} accessibilityRole="alert">
      <Ionicons name="cloud-offline-outline" size={18} color={colors.warning} />
      <Text style={styles.text}>Sem conexão. Mostrando os dados {savedLabel(savedAt, new Date())}. Puxe para atualizar.</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { flexDirection: 'row', alignItems: 'center', gap: space[2], backgroundColor: colors.warningSoft, borderRadius: radius.md, padding: space[3] },
  text: { flex: 1, fontFamily: fonts.medium, fontSize: 13, color: colors.warning },
});
