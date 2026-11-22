import Ionicons from '@expo/vector-icons/Ionicons';
import { Paths } from 'expo-file-system';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { WebView } from 'react-native-webview';
import { ApiError } from '../src/api/client';
import { shareFile } from '../src/lib/openFile';
import { colors, fonts, space } from '../src/theme';
import { dialog } from '../src/ui/Dialog';

/**
 * Visualizador de PDF do iPhone (pedido do Djalma, 22/11/2026): o documento abre para ler, sem baixar.
 * O botão de compartilhar, no canto, salva em Arquivos, imprime ou envia. O arquivo vem do cache do app
 * (openProtectedFile); a WebView só lê dentro desse cache.
 */
export default function Visualizar() {
  const { uri, title, mime } = useLocalSearchParams<{ uri: string; title?: string; mime?: string }>();
  const insets = useSafeAreaInsets();
  const [loading, setLoading] = useState(true);
  const name = title || 'Documento';
  const fileUri = typeof uri === 'string' && uri.startsWith(Paths.cache.uri) ? uri : null;

  async function share() {
    if (!fileUri) return;
    try {
      await shareFile(fileUri, name, mime || 'application/pdf');
    } catch (e) {
      void dialog.notice({ title: 'Não deu para compartilhar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
    }
  }

  return (
    <View style={[styles.screen, { paddingTop: insets.top }]}>
      <View style={styles.bar}>
        <Pressable
          onPress={() => (router.canGoBack() ? router.back() : router.replace('/'))}
          accessibilityRole="button"
          accessibilityLabel="Fechar"
          hitSlop={10}
          style={({ pressed }) => [styles.icon, pressed && styles.pressed]}
        >
          <Ionicons name="chevron-back" size={24} color={colors.text} />
        </Pressable>
        <Text style={styles.title} numberOfLines={1}>
          {name}
        </Text>
        <Pressable
          onPress={share}
          disabled={!fileUri}
          accessibilityRole="button"
          accessibilityLabel="Salvar ou enviar"
          hitSlop={10}
          style={({ pressed }) => [styles.icon, pressed && styles.pressed]}
        >
          <Ionicons name="share-outline" size={22} color={colors.brand} />
        </Pressable>
      </View>
      {fileUri ? (
        <WebView
          source={{ uri: fileUri }}
          originWhitelist={['file://*']}
          allowingReadAccessToURL={Paths.cache.uri}
          allowFileAccess
          javaScriptEnabled={false}
          onLoadEnd={() => setLoading(false)}
          style={styles.web}
        />
      ) : (
        <Text style={styles.empty}>Não foi possível abrir o documento.</Text>
      )}
      {loading && fileUri ? <ActivityIndicator style={styles.loading} color={colors.brand} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.surface },
  bar: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space[2],
    paddingHorizontal: space[3],
    paddingVertical: space[2],
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
  },
  icon: { width: 36, height: 36, alignItems: 'center', justifyContent: 'center' },
  pressed: { opacity: 0.5 },
  title: { flex: 1, textAlign: 'center', fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  web: { flex: 1, backgroundColor: colors.surface },
  loading: { position: 'absolute', top: '50%', alignSelf: 'center' },
  empty: { padding: space[5], textAlign: 'center', fontFamily: fonts.regular, color: colors.muted },
});
