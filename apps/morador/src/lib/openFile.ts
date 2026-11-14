import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { Platform } from 'react-native';
import { ApiError } from '../api/client';

/**
 * Baixa um arquivo protegido (boleto em PDF, documento do condomínio) com o token no
 * cabeçalho e abre o visualizador do sistema. O arquivo fica só no cache do app.
 */
export async function openProtectedFile(url: string, headers: Record<string, string>, fileName: string, mimeType = 'application/pdf'): Promise<void> {
  if (Platform.OS === 'web') {
    throw new ApiError(0, 'unsupported', 'Abra este arquivo pelo celular.');
  }
  const safeName = fileName.replace(/[^A-Za-z0-9._-]+/g, '-').slice(0, 80) || 'arquivo.pdf';
  let file: File;
  try {
    file = await File.downloadFileAsync(url, new File(Paths.cache, safeName), { headers, idempotent: true });
  } catch {
    throw new ApiError(0, 'download_failed', 'Não foi possível baixar o arquivo. Tente de novo.');
  }
  if (!(await Sharing.isAvailableAsync())) {
    throw new ApiError(0, 'unsupported', 'Este aparelho não consegue abrir o arquivo.');
  }
  await Sharing.shareAsync(file.uri, { mimeType, dialogTitle: fileName, UTI: mimeType === 'application/pdf' ? 'com.adobe.pdf' : undefined });
}
