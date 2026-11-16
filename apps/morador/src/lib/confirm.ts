import { dialog } from '../ui/Dialog';

/**
 * Pergunta antes de uma ação, na janela do app (nunca o Alert do sistema). Diz se dá para desfazer (ADR 0321).
 */
export function confirm(title: string, message: string, action: string, undo?: { undo: boolean; text?: string }): Promise<boolean> {
  return dialog.confirm({ title, message, confirm: action, cancel: 'Voltar', tone: 'danger', undo: undo?.undo, undoText: undo?.text });
}
