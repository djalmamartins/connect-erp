import { File, Paths } from 'expo-file-system';
import * as IntentLauncher from 'expo-intent-launcher';
import { router } from 'expo-router';
import * as Sharing from 'expo-sharing';
import { Platform } from 'react-native';
import { ApiError } from '../api/client';

/** Intent.FLAG_GRANT_READ_URI_PERMISSION: o leitor de PDF do Android pode ler o arquivo do cache do app. */
const GRANT_READ = 1;

/**
 * Baixa um arquivo protegido (boleto em PDF, documento do condomínio, certidão) com o token no cabeçalho e
 * ABRE PARA VER (pedido do Djalma, 22/11/2026: tocar abre, sem "baixar"). No iPhone abre a tela Visualizar
 * dentro do app; no Android abre o leitor de PDF do aparelho. Salvar ou enviar fica dentro do visualizador.
 * O arquivo fica só no cache do app.
 */
export async function openProtectedFile(url: string, headers: Record<string, string>, fileName: string, mimeType = 'application/pdf'): Promise<void> {
  if (Platform.OS === 'web') {
    throw new ApiError(0, 'unsupported', 'Abra este arquivo pelo celular.');
  }
  const file = await download(url, headers, fileName, mimeType);
  if (Platform.OS === 'ios') {
    router.push({ pathname: '/visualizar', params: { uri: file.uri, title: fileName, mime: mimeType } });
    return;
  }
  try {
    await IntentLauncher.startActivityAsync('android.intent.action.VIEW', { data: file.contentUri, type: mimeType, flags: GRANT_READ });
  } catch {
    // Sem leitor para esse tipo de arquivo: oferece abrir em outro app.
    await shareFile(file.uri, fileName, mimeType);
  }
}

/** Salvar em Arquivos, mandar por WhatsApp, imprimir: a folha de compartilhar do sistema. */
export async function shareFile(uri: string, fileName: string, mimeType = 'application/pdf'): Promise<void> {
  if (!(await Sharing.isAvailableAsync())) {
    throw new ApiError(0, 'unsupported', 'Este aparelho não consegue abrir o arquivo.');
  }
  await Sharing.shareAsync(uri, { mimeType, dialogTitle: fileName, UTI: mimeType === 'application/pdf' ? 'com.adobe.pdf' : undefined });
}

export function safeFileName(fileName: string, mimeType = 'application/pdf'): string {
  const base = fileName.replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80) || 'arquivo';
  return mimeType === 'application/pdf' && !/\.pdf$/i.test(base) ? `${base}.pdf` : base;
}

async function download(url: string, headers: Record<string, string>, fileName: string, mimeType: string): Promise<File> {
  try {
    return await File.downloadFileAsync(url, new File(Paths.cache, safeFileName(fileName, mimeType)), { headers, idempotent: true });
  } catch {
    throw new ApiError(0, 'download_failed', 'Não foi possível abrir o arquivo. Tente de novo.');
  }
}
