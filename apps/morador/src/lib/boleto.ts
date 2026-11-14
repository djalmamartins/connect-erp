import * as Clipboard from 'expo-clipboard';
import { Alert } from 'react-native';
import { ApiError, type ApiClient } from '../api/client';
import type { ResidentApi, StatementLine } from '../api/resident';
import { openProtectedFile } from './openFile';

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
  Alert.alert('Copiado', `${what} copiado. Cole no app do seu banco.`);
}

export async function openBoleto(api: ResidentApi, client: ApiClient, line: StatementLine): Promise<void> {
  if (!line.charge) return;
  try {
    await openProtectedFile(api.chargePdfUrl(line.charge.id), client.authHeaders(), `boleto-${line.due_on}.pdf`);
  } catch (e) {
    Alert.alert('Boleto', e instanceof ApiError ? e.message : 'Não foi possível abrir o boleto.');
  }
}
