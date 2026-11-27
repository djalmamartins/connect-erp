/** Regras puras dos anexos do app do SAC (testadas em __tests__/sac-api.test.ts). */

/** Até 5 fotos por resposta ou abertura, como o servidor (TicketDesk::MAX_FILES, ADR 0664). */
export const MAX_PHOTOS = 5;
/** Lado maior da foto enviada; o servidor guarda até 1600 px. */
export const PHOTO_SIDE = 1600;

/** Nome da foto enviada: "foto-1.jpg", "foto-2.jpg"... */
export function photoName(index: number): string {
  return `foto-${index + 1}.jpg`;
}

/** Tipo do anexo pelo nome, para o celular saber com que app abrir. */
export function mimeFor(name: string): string {
  const ext = (/\.([a-z0-9]+)$/i.exec(name)?.[1] ?? '').toLowerCase();
  return (
    ({ jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif', pdf: 'application/pdf', txt: 'text/plain', csv: 'text/csv' } as Record<string, string>)[ext] ??
    'application/octet-stream'
  );
}

export function safeFileName(name: string): string {
  return name.replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^[-.]+|-+$/g, '').slice(0, 80) || 'anexo';
}
