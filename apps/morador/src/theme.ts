/**
 * Tokens do app do morador. Mesmas cores do design system do ERP
 * (container/erp/connect/v1/assets/css/erp.css): verde-escuro da barra lateral,
 * verde das ações, dourado Connect só em detalhes, fonte Inter.
 */
export const colors = {
  sidebar: '#0d2e27',
  sidebarInk: '#d5e4dc',
  brand: '#07884c',
  brandStrong: '#066f3e',
  brandSoft: '#eaf7ef',
  accent: '#c5a131',
  accentSoft: '#fbf4dd',
  canvas: '#eef8f2',
  surface: '#ffffff',
  surfaceMuted: '#f6faf8',
  border: '#dfe8e3',
  text: '#121a31',
  muted: '#65746d',
  faint: '#8a9a92',
  success: '#12805c',
  successSoft: '#e3f4ec',
  warning: '#9a6400',
  warningSoft: '#fff8e8',
  danger: '#d92d36',
  dangerSoft: '#fdecee',
  info: '#18587d',
  infoSoft: '#eaf3fc',
  onBrand: '#ffffff',
} as const;

export const fonts = {
  regular: 'Inter_400Regular',
  medium: 'Inter_500Medium',
  semibold: 'Inter_600SemiBold',
  bold: 'Inter_700Bold',
} as const;

export const space = { 1: 4, 2: 8, 3: 12, 4: 16, 5: 20, 6: 24, 7: 32 } as const;
export const radius = { sm: 8, md: 11, lg: 15, pill: 999 } as const;
export const controlHeight = 44;
