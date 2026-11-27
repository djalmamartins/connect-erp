import type { VisitPanel } from '../src/api/team';
import { arrivalKey, readArrival, sinceLabel, toggleStep, visitCounts, visitHeadline, writeArrival, mapsUrl } from '../src/lib/visit';

const ticket = (id: number, priority = 'medium') =>
  ({ id, protocol: `SAC${id}`, subject: 'Vazamento', priority, priority_label: '', tag: '', status: 'open', status_label: 'Aberto', category: '', due_at: '', due_on: '', overdue: false, due_today: false }) as VisitPanel['tickets'][number];

const panel: VisitPanel = {
  condominium: { id: 7, name: 'Lafayette', address: 'Rua A, 10' },
  tickets: [ticket(1, 'urgent'), ticket(2)],
  urgent: 1,
  documents: [
    { id: 1, title: 'AVCB', date: '2026-11-01', days: -26, label: 'Venceu há 26 dias' },
    { id: 2, title: 'Seguro', date: '2026-12-20', days: 23, label: 'Vence em 23 dias' },
  ],
  pending_from_last_visit: [ticket(3)],
  last_visit: { id: 9, template: 'Mensal', completed_at: '2026-11-15 10:00:00', issues: 2 },
};

describe('painel da visita', () => {
  it('conta o que acompanhar e diz o que é urgente', () => {
    const c = visitCounts(panel);
    expect(c).toEqual({ tickets: 2, urgent: 1, documents: 2, expired: 1, pending: 1 });
    expect(visitHeadline(c)).toBe('Atenção: 1 chamado urgente, 1 documento vencido, 1 pendência da última vistoria.');
    expect(visitHeadline({ tickets: 3, urgent: 0, documents: 1, expired: 0, pending: 0 })).toBe('Nada urgente neste prédio.');
  });

  it('guarda o checklist de chegada só para o prédio e o dia', () => {
    const key = arrivalKey(7, '2026-11-27');
    const done = toggleStep(toggleStep([], 'portaria'), 'fotos');
    const raw = writeArrival(key, done);
    expect(readArrival(raw, key)).toEqual(['portaria', 'fotos']);
    expect(readArrival(raw, arrivalKey(7, '2026-11-28'))).toEqual([]);
    expect(readArrival(raw, arrivalKey(8, '2026-11-27'))).toEqual([]);
    expect(toggleStep(done, 'portaria')).toEqual(['fotos']);
    expect(readArrival('{quebrado', key)).toEqual([]);
    expect(readArrival(JSON.stringify({ key, done: ['portaria', 'inventado'] }), key)).toEqual(['portaria']);
  });

  it('diz há quanto tempo foi a última vistoria', () => {
    expect(sinceLabel('2026-11-15 10:00:00', '2026-11-27')).toBe('Há 12 dias');
    expect(sinceLabel('2026-11-26', '2026-11-27')).toBe('Ontem');
    expect(sinceLabel('2026-11-27 08:00:00', '2026-11-27')).toBe('Hoje');
  });
});

describe('como chegar', () => {
  it('monta o endereço para cada app de mapas', () => {
    expect(mapsUrl('native', 'Ed. Aurora', 'Rua A, 10 - Centro', 'ios')).toBe('https://maps.apple.com/?q=Ed.%20Aurora%2C%20Rua%20A%2C%2010%20-%20Centro');
    expect(mapsUrl('native', 'Ed. Aurora', 'Rua A, 10', 'android')).toBe('geo:0,0?q=Ed.%20Aurora%2C%20Rua%20A%2C%2010');
    expect(mapsUrl('google', 'Ed. Aurora', 'Rua A, 10', 'ios')).toBe('https://www.google.com/maps/search/?api=1&query=Ed.%20Aurora%2C%20Rua%20A%2C%2010');
    expect(mapsUrl('waze', '', 'Rua A, 10', 'android')).toBe('https://waze.com/ul?q=Rua%20A%2C%2010&navigate=yes');
  });
});
