import type { EventTone } from '../lib/day';
import { colors } from '../theme';

/** Cores dos compromissos (ADR 0665), as mesmas em Hoje e na Semana: verde feito, vermelho atrasado, uma por tipo. */
export const EVENT_TONE: Record<EventTone, { bar: string; icon: string; bg: string }> = {
  done: { bar: colors.success, icon: colors.success, bg: colors.successSoft },
  late: { bar: colors.danger, icon: colors.danger, bg: colors.dangerSoft },
  meeting: { bar: colors.info, icon: colors.info, bg: colors.infoSoft },
  task: { bar: colors.brand, icon: colors.brandStrong, bg: colors.brandSoft },
  deadline: { bar: colors.warning, icon: colors.warning, bg: colors.warningSoft },
  support: { bar: colors.accent, icon: colors.warning, bg: colors.accentSoft },
};
