import type Ionicons from '@expo/vector-icons/Ionicons';
import type { ComponentProps } from 'react';

type IconName = ComponentProps<typeof Ionicons>['name'];

/** Ícone pelo nome do espaço; o cadastro não tem foto, então o ícone dá a cara de cada um. */
export function areaIcon(name: string): IconName {
  const n = name.toLocaleLowerCase('pt-BR');
  if (/churras|grill|gourmet/.test(n)) return 'flame';
  if (/sal[aã]o|festa/.test(n)) return 'wine';
  if (/piscina/.test(n)) return 'water';
  if (/academia|fitness/.test(n)) return 'barbell';
  if (/quadra|esporte|campo/.test(n)) return 'basketball';
  if (/brinquedo|kids|infantil|playground/.test(n)) return 'happy';
  if (/cinema|jogos|game/.test(n)) return 'film';
  if (/mudan/.test(n)) return 'cube';
  return 'grid';
}
