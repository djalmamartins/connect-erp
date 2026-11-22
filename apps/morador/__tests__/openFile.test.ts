jest.mock('expo-file-system', () => ({ File: class {}, Paths: { cache: { uri: 'file:///cache/' } } }));
jest.mock('expo-intent-launcher', () => ({ startActivityAsync: jest.fn() }));
jest.mock('expo-router', () => ({ router: { push: jest.fn() } }));
jest.mock('expo-sharing', () => ({ isAvailableAsync: jest.fn(), shareAsync: jest.fn() }));

import { safeFileName } from '../src/lib/openFile';

describe('safeFileName', () => {
  it('troca acentos e espaços e garante .pdf no PDF', () => {
    expect(safeFileName('Regulamento interno')).toBe('Regulamento-interno.pdf');
    expect(safeFileName('boleto-2026-11-10.pdf')).toBe('boleto-2026-11-10.pdf');
    expect(safeFileName('Ata de assembleia (março)')).toBe('Ata-de-assembleia-mar-o.pdf');
  });

  it('não põe .pdf em outro tipo e nunca fica vazio', () => {
    expect(safeFileName('foto.png', 'image/png')).toBe('foto.png');
    expect(safeFileName('###', 'image/png')).toBe('arquivo');
  });
});
