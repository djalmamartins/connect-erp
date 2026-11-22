import type { KeyboardTypeOptions } from 'react-native';

/**
 * Máscaras automáticas dos campos (pedido do Djalma, 22/11/2026): a pessoa digita só os números e o app põe
 * barras, pontos, traço e parênteses. A mesma regra existe no app web (container/app/connect/v1/assets/js/masks.js).
 * O separador só entra quando chega o número seguinte, então apagar com o teclado funciona normalmente.
 */
export type MaskName = 'date' | 'time' | 'cpf' | 'cpfCnpj' | 'phone' | 'cep' | 'money' | 'plate';

const digits = (text: string, max: number) => text.replace(/\D+/g, '').slice(0, max);

/** Encaixa os números no molde: cada "0" recebe um número; o resto é separador. */
function pattern(value: string, mold: string): string {
  let out = '';
  let i = 0;
  for (const ch of mold) {
    if (i >= value.length) break;
    if (ch === '0') {
      out += value[i++];
    } else {
      out += ch;
    }
  }
  return out;
}

export function applyMask(mask: MaskName, text: string): string {
  switch (mask) {
    case 'date':
      return pattern(digits(text, 8), '00/00/0000');
    case 'time':
      return pattern(digits(text, 4), '00:00');
    case 'cpf':
      return pattern(digits(text, 11), '000.000.000-00');
    case 'cpfCnpj': {
      const d = digits(text, 14);
      return d.length <= 11 ? pattern(d, '000.000.000-00') : pattern(d, '00.000.000/0000-00');
    }
    case 'phone': {
      const d = digits(text, 11);
      return d.length <= 10 ? pattern(d, '(00) 0000-0000') : pattern(d, '(00) 00000-0000');
    }
    case 'cep':
      return pattern(digits(text, 8), '00000-000');
    case 'money': {
      const d = digits(text, 13).replace(/^0+(?=\d)/, '');
      if (d === '') return '';
      const cents = d.padStart(3, '0');
      const whole = cents.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
      return `${whole},${cents.slice(-2)}`;
    }
    case 'plate':
      return text.toUpperCase().replace(/[^A-Z0-9]+/g, '').slice(0, 7);
  }
}

/** Teclado e tamanho máximo de cada máscara. */
export const MASK_INPUT: Record<MaskName, { keyboardType: KeyboardTypeOptions; maxLength: number; placeholder?: string }> = {
  date: { keyboardType: 'number-pad', maxLength: 10, placeholder: 'DD/MM/AAAA' },
  time: { keyboardType: 'number-pad', maxLength: 5, placeholder: 'HH:MM' },
  cpf: { keyboardType: 'number-pad', maxLength: 14, placeholder: '000.000.000-00' },
  cpfCnpj: { keyboardType: 'number-pad', maxLength: 18 },
  phone: { keyboardType: 'phone-pad', maxLength: 15, placeholder: '(00) 00000-0000' },
  cep: { keyboardType: 'number-pad', maxLength: 9, placeholder: '00000-000' },
  money: { keyboardType: 'number-pad', maxLength: 17, placeholder: '0,00' },
  plate: { keyboardType: 'default', maxLength: 7, placeholder: 'ABC1D23' },
};
