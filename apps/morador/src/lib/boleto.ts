import * as Clipboard from 'expo-clipboard';
import { ApiError, type ApiClient } from '../api/client';
import type { ResidentApi, StatementLine } from '../api/resident';
import { openProtectedFile } from './openFile';
import { dialog } from '../ui/Dialog';

/** Contas em aberto ou vencidas, da mais antiga para a mais nova. */
export function pendingLines(lines: StatementLine[]): StatementLine[] {
  return lines.filter((l) => l.situation === 'open' || l.situation === 'overdue').sort((a, b) => a.due_on.localeCompare(b.due_on));
}

/** A próxima conta com boleto emitido: é a "segunda via de cara" do Início. */
export function nextBoleto(lines: StatementLine[]): StatementLine | null {
  return pendingLines(lines).find((l) => l.charge && (l.charge.pdf_available || l.charge.digitable_line)) ?? null;
}

export async function copyText(text: string, what: string): Promise<void> {
  await Clipboard.setStringAsync(text);
  dialog.toast(`${what}: copiado! Cole no app do banco.`);
}

export async function openBoleto(api: ResidentApi, client: ApiClient, line: StatementLine): Promise<void> {
  if (!line.charge) return;
  try {
    await openProtectedFile(api.chargePdfUrl(line.charge.id), client.authHeaders(), `boleto-${line.due_on}.pdf`);
  } catch (e) {
    void dialog.notice({ title: 'Não deu para abrir o boleto', message: e instanceof ApiError ? e.message : 'Tente de novo em instantes.', tone: 'error' });
  }
}
