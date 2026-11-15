import { useAuth } from '../../src/auth/AuthContext';
import { Screen } from '../../src/ui/kit';
import { MenuGroup, visibleItems, type MenuItem } from '../../src/ui/Menu';

/** Resolva fácil: tudo o que o morador resolve sozinho, num lugar só (revisão de 15/11). */
const ITEMS: MenuItem[] = [
  { label: 'Boletos', hint: '2ª via, código de barras e Pix', icon: 'barcode', color: '#07884c', href: '/financeiro', feature: 'boletos' },
  { label: 'Reservas', hint: 'Espaços do condomínio', icon: 'calendar', color: '#2f7bd8', href: '/reservas', feature: 'reservations' },
  { label: 'Chamado', hint: 'Peça ajuda à administração', icon: 'chatbubbles', color: '#c2611f', feature: 'occurrences' },
  { label: 'Ocorrência', hint: 'Registre um problema', icon: 'alert-circle', color: '#d9534f', href: '/ocorrencias', feature: 'occurrences' },
  { label: 'Documentos', hint: 'Convenção, regimento e atas', icon: 'document-text', color: '#5b6bd6', href: '/documentos', feature: 'documents' },
  { label: 'Prestação de contas', hint: 'Para onde foi o dinheiro, mês a mês', icon: 'pie-chart', color: '#0f7a6a', href: '/prestacao-de-contas', feature: 'accountability' },
  { label: 'Assembleia digital', hint: 'Datas, pauta e resultados', icon: 'podium', color: '#07884c', href: '/assembleias', feature: 'assemblies' },
  { label: 'Comunicados', hint: 'Avisos da administração', icon: 'megaphone', color: '#e8833a', href: '/comunicados', feature: 'announcements' },
  { label: 'CND', hint: 'Certidão negativa de débitos', icon: 'ribbon', color: '#8a6d1f', feature: 'boletos' },
];

export default function Resolva() {
  const { me } = useAuth();
  return (
    <Screen title="Resolva fácil" subtitle="Tudo o que você resolve pelo app" tab>
      <MenuGroup items={visibleItems(ITEMS, me)} />
    </Screen>
  );
}
