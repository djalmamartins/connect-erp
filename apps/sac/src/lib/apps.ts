import { Linking } from 'react-native';

/**
 * Apps da equipe que trabalham juntos (ADR 0660): o SAC abre o Meu Dia e o Meu Dia abre o SAC pelo endereço
 * próprio de cada app. Sem o outro app instalado, cai na versão web do ERP.
 */
export const MEU_DIA_SCHEME = 'connectmeudia://';
export const SAC_SCHEME = 'connectsac://';

export async function openMeuDia(webUrl: string): Promise<void> {
  try {
    if (await Linking.canOpenURL(MEU_DIA_SCHEME)) {
      await Linking.openURL(MEU_DIA_SCHEME);
      return;
    }
  } catch {
    // Sem permissão de consulta (Android): tenta a web.
  }
  await Linking.openURL(webUrl);
}

/** Endereço do Meu Dia na web, a partir do endereço da API (erp.<domínio>/meu-dia). */
export function meuDiaWebUrl(apiBase: string): string {
  return `${apiBase.replace(/\/+$/, '')}/meu-dia`;
}
