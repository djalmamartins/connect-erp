import Ionicons from '@expo/vector-icons/Ionicons';
import { Pressable, StyleSheet, Text } from 'react-native';
import { colors, fonts, space } from '../theme';

/** Caixa de marcar acessível (leitor de tela diz "caixa de seleção, marcada"). */
export function CheckRow({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) {
  return (
    <Pressable
      onPress={() => onChange(!checked)}
      accessibilityRole="checkbox"
      accessibilityState={{ checked }}
      accessibilityLabel={label}
      style={({ pressed }) => [styles.row, pressed && { opacity: 0.7 }]}
    >
      <Ionicons name={checked ? 'checkbox' : 'square-outline'} size={24} color={checked ? colors.brand : colors.muted} />
      <Text style={styles.text}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'flex-start', gap: space[2], paddingVertical: space[2], minHeight: 44 },
  text: { flex: 1, fontFamily: fonts.regular, fontSize: 15, lineHeight: 21, color: colors.text },
});
