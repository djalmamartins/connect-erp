import { router } from 'expo-router';
import { ApiError } from '../api/client';
import type { DayEvent, EventStatus, TeamApi } from '../api/team';
import { dialog } from '../ui/Dialog';
import { copyParams, postponeOptions, shortDay } from './day';

/**
 * O menu de um compromisso, igual em Hoje e na Semana (ADR 0670): concluir, cancelar, reabrir e adiar
 * (ADR 0668: atalhos rápidos ou outro dia e hora) e repetir em outro dia (ADR 0679). Depois de mudar, chama reload para a tela atualizar.
 */
export async function eventActions(api: TeamApi, event: DayEvent, today: string, reload: () => Promise<void>): Promise<void> {
  const done = event.status === 'completed';
  const options: { label: string; status: EventStatus; danger?: boolean }[] =
    event.status === 'scheduled'
      ? [
          { label: 'Concluir', status: 'completed' },
          { label: 'Cancelar', status: 'cancelled', danger: true },
        ]
      : [{ label: done ? 'Reabrir' : 'Marcar de novo', status: 'scheduled' }];
  const canMove = event.status === 'scheduled';
  const choice = await dialog.choose({
    title: event.title,
    message: [shortDay(event.starts_at.slice(0, 10)) + ' · ' + event.start + (event.end ? `–${event.end}` : ''), event.condominium, event.protocol ? `Chamado ${event.protocol}` : null, event.description || null]
      .filter(Boolean)
      .join('\n'),
    options: [
      ...options.map((o) => ({ label: o.label, danger: o.danger, icon: o.status === 'completed' ? ('checkmark-circle-outline' as const) : o.status === 'cancelled' ? ('close-circle-outline' as const) : ('refresh' as const) })),
      ...(canMove ? [{ label: 'Adiar ou mudar o horário', icon: 'time-outline' as const }] : []),
      { label: 'Repetir em outro dia', icon: 'copy-outline' as const },
    ],
  });
  if (choice === null) return;
  if (canMove && choice === options.length) {
    await postpone(api, event, today, reload);
    return;
  }
  if (choice === options.length + (canMove ? 1 : 0)) {
    router.navigate({ pathname: '/nova', params: copyParams(event, today) });
    return;
  }
  try {
    await api.setStatus(event.id, options[choice]!.status);
    dialog.toast(options[choice]!.status === 'completed' ? 'Concluída' : options[choice]!.status === 'cancelled' ? 'Cancelada' : 'Reaberta');
    await reload();
  } catch (e) {
    await dialog.notice({ title: 'Não deu certo', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
  }
}

async function postpone(api: TeamApi, event: DayEvent, today: string, reload: () => Promise<void>): Promise<void> {
  const shortcuts = postponeOptions(event, today);
  const choice = await dialog.choose({
    title: 'Adiar para quando?',
    message: event.title,
    options: [...shortcuts.map((o) => ({ label: o.label, icon: 'arrow-forward-circle-outline' as const })), { label: 'Outro dia e hora', icon: 'calendar-outline' as const }],
  });
  if (choice === null) return;
  const pick = shortcuts[choice];
  if (!pick) {
    const day = event.starts_at.slice(0, 10);
    router.push({ pathname: '/adiar/[id]', params: { id: String(event.id), title: event.title, date: day < today ? today : day, start: event.start } });
    return;
  }
  try {
    await api.moveEvent(event.id, pick.date, pick.start);
    dialog.toast(`Adiado para ${shortDay(pick.date)} às ${pick.start}`);
    await reload();
  } catch (e) {
    await dialog.notice({ title: 'Não deu para adiar', message: e instanceof ApiError ? e.message : 'Tente de novo.', tone: 'error' });
  }
}
