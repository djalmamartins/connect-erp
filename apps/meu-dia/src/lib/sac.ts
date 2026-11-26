import * as Linking from 'expo-linking';
import { dialog } from '../ui/Dialog';

/** Endereço do chamado no app do SAC (ADR 0660). */
export function sacTicketUrl(id: number): string {
  return `connectsac://chamados/${id}`;
}

/** Abre o chamado no app do SAC; sem o app instalado, avisa. */
export async function openInSac(id: number): Promise<void> {
  try {
    await Linking.openURL(sacTicketUrl(id));
  } catch {
    await dialog.notice({ title: 'App do SAC não encontrado', message: 'Instale o app do SAC neste celular para responder o chamado, ou abra o chamado no ERP pelo computador.' });
  }
}
