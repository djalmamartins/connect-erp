/**
 * Textos da tela de entrada do app, no estilo da tela de login do ERP (ADR 0657): foto do prédio
 * com título e dicas de segurança, e o cartão com o formulário. Cada etapa (entrar, esqueci a
 * senha, link enviado, verificação em duas etapas) troca só os textos; a moldura é a mesma.
 */
export type LoginStep = 'login' | 'forgot' | 'sent' | 'two_factor';

export type LoginTip = { icon: 'shield-checkmark-outline' | 'mail-outline' | 'key-outline' | 'time-outline'; title: string; text: string };

export type LoginCopy = {
  /** Título da foto, em duas partes: a segunda vai em dourado. */
  hero: [string, string];
  lead: string;
  cardTitle: string;
  cardLead: string;
  tips: LoginTip[];
};

export const LOGIN_EYEBROW = 'CONNECT MORADOR';
export const LOGIN_QUOTE = 'A Connect nunca pede a sua senha por telefone, e-mail ou WhatsApp.';

const COPY: Record<LoginStep, LoginCopy> = {
  login: {
    hero: ['O seu condomínio', 'na palma da mão'],
    lead: 'Boletos, reservas, encomendas, visitantes e avisos do seu prédio.',
    cardTitle: 'Área do morador',
    cardLead: 'Entre com o e-mail ou CPF e a sua senha',
    tips: [
      { icon: 'shield-checkmark-outline', title: 'Conta protegida', text: 'Use uma senha só sua e ative a verificação em duas etapas no Perfil.' },
      { icon: 'mail-outline', title: 'Desconfie de links', text: 'Entre sempre pelo aplicativo, nunca por links de mensagens estranhas.' },
      { icon: 'key-outline', title: 'Primeiro acesso', text: 'A conta nasce pelo convite que a administração envia por e-mail.' },
    ],
  },
  forgot: {
    hero: ['Esqueceu a senha?', 'A gente ajuda'],
    lead: 'Se o e-mail ou CPF estiver cadastrado, você recebe um link para criar uma senha nova.',
    cardTitle: 'Recuperar senha',
    cardLead: 'Enviaremos um link para criar uma senha nova',
    tips: [
      { icon: 'mail-outline', title: 'Confira o e-mail', text: 'O link chega em poucos minutos. Veja também a caixa de spam e promoções.' },
      { icon: 'time-outline', title: 'Link com prazo', text: 'Por segurança, o link vale por pouco tempo e só pode ser usado uma vez.' },
      { icon: 'shield-checkmark-outline', title: 'Não pediu a troca?', text: 'Ignore o e-mail. Sua senha atual continua valendo.' },
    ],
  },
  sent: {
    hero: ['Esqueceu a senha?', 'A gente ajuda'],
    lead: 'Abra o link no celular ou no computador para criar a senha nova.',
    cardTitle: 'Confira o seu e-mail',
    cardLead: 'Se a conta existir, o link já está a caminho',
    tips: [
      { icon: 'mail-outline', title: 'Não chegou?', text: 'Veja a caixa de spam e promoções antes de pedir de novo.' },
      { icon: 'time-outline', title: 'Link com prazo', text: 'Por segurança, o link vale por pouco tempo e só pode ser usado uma vez.' },
      { icon: 'key-outline', title: 'Senha nova', text: 'Use pelo menos 8 caracteres, misturando letras, números e símbolos.' },
    ],
  },
  two_factor: {
    hero: ['Sua conta protegida', 'em duas etapas'],
    lead: 'Além da senha, pedimos um código que só o seu celular gera.',
    cardTitle: 'Verificação em duas etapas',
    cardLead: 'Digite o código de 6 números do seu aplicativo autenticador',
    tips: [
      { icon: 'shield-checkmark-outline', title: 'Nunca compartilhe o código', text: 'A Connect nunca pede este código por telefone, e-mail ou WhatsApp.' },
      { icon: 'time-outline', title: 'Código novo a cada 30 segundos', text: 'Se o código mudar enquanto você digita, use o novo que aparecer.' },
      { icon: 'key-outline', title: 'Sem o celular?', text: 'Use um dos códigos de recuperação que você guardou ao ativar a verificação.' },
    ],
  },
};

export function loginStep(forgot: boolean, sent: boolean, challenge: boolean): LoginStep {
  if (forgot) {
    return sent ? 'sent' : 'forgot';
  }
  return challenge ? 'two_factor' : 'login';
}

export function loginCopy(step: LoginStep): LoginCopy {
  return COPY[step];
}
