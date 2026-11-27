/**
 * Tokens do app do SAC (ADR 0661): cinza-claro com dourado, as mesmas cores do balão do SAC no site
 * e do ícone aprovado (opção A, 27/11/2026). Botão principal verde-escuro do ERP com texto dourado-claro;
 * verde só no WhatsApp e no "online". Fonte Inter.
 *
 * Os nomes (brand, accent...) são os do app do morador para o kit de componentes compartilhado:
 * aqui brand é o verde-escuro dos botões e accent o dourado dos destaques.
 */
export const colors = {
  sidebar: '#0d2e27',
  sidebarInk: '#e9d48a',
  brand: '#0d2e27',
  brandStrong: '#a9871f',
  brandSoft: '#f6efd9',
  accent: '#a9871f',
  accentSoft: '#f6efd9',
  accentLine: '#e9dcb2',
  canvas: '#f1f2f1',
  surface: '#ffffff',
  surfaceMuted: '#eceeed',
  border: '#e3e5e3',
  text: '#1c2421',
  muted: '#6b7571',
  faint: '#8a938f',
  success: '#128c4a',
  successSoft: '#e3f6ea',
  warning: '#8a6d12',
  warningSoft: '#fff8e1',
  danger: '#d92d36',
  dangerSoft: '#fdecee',
  info: '#4b5551',
  infoSoft: '#eceeed',
  onBrand: '#ffffff',
  onDark: '#e9d48a',
} as const;

export const fonts = {
  regular: 'Inter_400Regular',
  medium: 'Inter_500Medium',
  semibold: 'Inter_600SemiBold',
  bold: 'Inter_700Bold',
} as const;

export const space = { 1: 4, 2: 8, 3: 12, 4: 16, 5: 20, 6: 24, 7: 32 } as const;
export const radius = { sm: 8, md: 11, lg: 15, xl: 20, pill: 999 } as const;
export const controlHeight = 44;
