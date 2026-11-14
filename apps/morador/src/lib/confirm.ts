import { dialog } from '../ui/Dialog';

/** Pergunta antes de uma ação que o morador não desfaz sozinho (cancelar reserva ou visita), na janela do app. */
export function confirm(title: string, message: string, action: string): Promise<boolean> {
  return dialog.confirm({ title, message, confirm: action, cancel: 'Voltar', tone: 'danger' });
}
