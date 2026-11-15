import Ionicons from '@expo/vector-icons/Ionicons';
import { LinearGradient } from 'expo-linear-gradient';
import { router, useFocusEffect, type Href } from 'expo-router';
import { useCallback, useEffect, useState, type ComponentProps } from 'react';
import { Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { isFeatureOn, type Feature, type Reservation } from '../../src/api/resident';
import { useAuth } from '../../src/auth/AuthContext';
import { tokenStorage } from '../../src/auth/storage';
import { copyText, nextBoleto, openBoleto } from '../../src/lib/boleto';
import { date, greeting, initials, longToday, money } from '../../src/lib/format';
import { useResource } from '../../src/lib/useResource';
import { colors, fonts, radius, space } from '../../src/theme';
import { Body, Card, CardTitle, ErrorBox, Loading, Screen, SectionTitle } from '../../src/ui/kit';
import { trackApprovals } from '../../src/lib/approvals';
import { stamp, unseenCount } from '../../src/lib/notifications';
import { Bell, NotificationRow } from '../../src/ui/Notifications';

type IconName = ComponentProps<typeof Ionicons>['name'];
type Service = { label: string; hint: string; icon: IconName; color: string; href: Href; feature: Feature };

/** Botões grandes do Início: os serviços mais usados, dois por linha, no máximo seis (revisão de 15/11). */
const SERVICES: Service[] = [
  { label: 'Boletos', hint: '2ª via e Pix', icon: 'barcode', color: '#07884c', href: '/financeiro', feature: 'boletos' },
  { label: 'Reservas', hint: 'Salão, churrasqueira', icon: 'calendar', color: '#2f7bd8', href: '/reservas', feature: 'reservations' },
  { label: 'Chamados', hint: 'Abra e acompanhe', icon: 'chatbubbles', color: '#c2611f', href: '/ocorrencias', feature: 'occurrences' },
  { label: 'Autorizar entrada', hint: 'Avise a portaria', icon: 'person-add', color: '#5b6bd6', href: '/visitantes', feature: 'visitors' },
  { label: 'Documentos', hint: 'Atas e regimento', icon: 'document-text', color: '#8a6d1f', href: '/documentos', feature: 'documents' },
  { label: 'Prestação de contas', hint: 'Para onde foi o dinheiro', icon: 'pie-chart', color: '#0f7a6a', href: '/prestacao-de-contas', feature: 'accountability' },
];

export default function Home() {
  const { api, client, me, residentId } = useAuth();
  const summary = useResource(() => api.summary(), [residentId]);
  const billing = isFeatureOn(me, 'boletos');
  // Segunda via de cara: a próxima conta com boleto já fica com os botões no cartão verde.
  const charges = useResource(() => (billing ? api.charges() : Promise.resolve(null)), [residentId, billing]);
  const boleto = nextBoleto(charges.data?.lines ?? []);
  // Aviso de reserva aprovada: compara com as que estavam aguardando o síndico na última visita.
  const booking = isFeatureOn(me, 'reservations');
  const bookings = useResource(() => (booking ? api.reservations() : Promise.resolve(null)), [residentId, booking]);
  const [approved, setApproved] = useState<Reservation[]>([]);
  const [opening, setOpening] = useState(false);
  const [hidden, setHidden] = useState(false);
  const many = (me?.links.length ?? 0) > 1;
  const feed = useResource(() => api.notifications(), [residentId]);
  const [seenAt, setSeenAt] = useState<string | null>(null);
  // Ao voltar do sininho, relê a hora vista para o número sumir.
  useFocusEffect(
    useCallback(() => {
      if (residentId !== null) void tokenStorage.getNotificationsSeen(residentId).then(setSeenAt);
    }, [residentId]),
  );
  const unseen = unseenCount(feed.data ?? [], seenAt, stamp());

  useEffect(() => {
    void tokenStorage.getHideValues().then(setHidden);
  }, []);

  useEffect(() => {
    const list = bookings.data?.reservations;
    if (!list || residentId === null) return;
    let alive = true;
    void tokenStorage.getApprovals(residentId).then(async (before) => {
      const next = trackApprovals(list, before.pending, before.unseen);
      await tokenStorage.setApprovals(residentId, next);
      if (alive) setApproved(list.filter((r) => next.unseen.includes(r.id)));
    });
    return () => {
      alive = false;
    };
  }, [bookings.data, residentId]);

  async function dismissApprovals() {
    setApproved([]);
    if (residentId === null) return;
    const before = await tokenStorage.getApprovals(residentId);
    await tokenStorage.setApprovals(residentId, { pending: before.pending, unseen: [] });
  }

  function toggleHidden() {
    setHidden((v) => {
      void tokenStorage.setHideValues(!v);
      return !v;
    });
  }

  async function secondCopy() {
    if (!boleto || opening) return;
    setOpening(true);
    try {
      await openBoleto(api, client, boleto);
    } finally {
      setOpening(false);
    }
  }

  async function refresh() {
    await Promise.all([summary.reload(), charges.reload(), bookings.reload(), feed.reload()]);
  }
  const current = me?.current ?? null;
  const s = summary.data;
  const name = current?.name ?? me?.user.name ?? '';
  const first = name.split(' ')[0] || 'morador';
  const services = SERVICES.filter((c) => isFeatureOn(me, c.feature));

  const avatar = (
    <View style={{ flexDirection: 'row', alignItems: 'flex-end' }}>
      <Bell count={unseen} />
      <Pressable onPress={() => router.push('/perfil')} accessibilityRole="button" accessibilityLabel="Seu perfil" style={styles.avatar}>
        <Text style={styles.avatarText}>{initials(name)}</Text>
      </Pressable>
    </View>
  );

  return (
    <Screen tab eyebrow={longToday()} title={`${greeting()}, ${first}`} right={avatar} refreshing={summary.refreshing} onRefresh={refresh}>
      <Pressable
        onPress={() => (s?.charges ? router.push('/financeiro') : undefined)}
        accessibilityRole={s?.charges ? 'button' : undefined}
        accessibilityLabel={s?.charges ? (hidden ? 'Ver o financeiro' : `Em aberto: ${money(s.charges.open_cents)}`) : undefined}
        style={({ pressed }) => [styles.heroWrap, pressed && s?.charges ? { transform: [{ scale: 0.985 }] } : null]}
      >
        <LinearGradient colors={[colors.sidebar, '#124c3c', colors.brandStrong]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
          <Image source={require('../../assets/intro-symbol.png')} style={styles.heroMark} resizeMode="contain" accessible={false} />
          <View style={styles.heroTop}>
            <View style={{ flex: 1 }}>
              <Pressable
                onPress={many ? () => router.push('/trocar-unidade') : undefined}
                disabled={!many}
                accessibilityRole={many ? 'button' : undefined}
                accessibilityLabel={many ? 'Trocar de unidade' : undefined}
                style={styles.condoRow}
              >
                <Text style={styles.condo} numberOfLines={2}>
                  {current?.condominium_name ?? 'Seu condomínio'}
                </Text>
                {many ? <Ionicons name="chevron-down" size={18} color="rgba(255,255,255,0.85)" /> : null}
              </Pressable>
              {current ? (
                <View style={styles.unitChip}>
                  <Ionicons name="home" size={12} color={colors.accent} />
                  <Text style={styles.unitText}>
                    {current.unit_label}
                    {current.relationship_label ? ` · ${current.relationship_label}` : ''}
                  </Text>
                </View>
              ) : null}
            </View>
          </View>
          {s?.charges ? (
            <View style={styles.heroMoney}>
              <View style={styles.labelRow}>
                <Text style={styles.heroLabel}>Em aberto</Text>
                <Pressable onPress={toggleHidden} hitSlop={12} accessibilityRole="button" accessibilityLabel={hidden ? 'Mostrar valores' : 'Esconder valores'}>
                  <Ionicons name={hidden ? 'eye-off-outline' : 'eye-outline'} size={18} color="rgba(255,255,255,0.8)" />
                </Pressable>
              </View>
              <Text style={styles.heroValue}>{hidden ? 'R$ •••••' : money(s.charges.open_cents)}</Text>
              <View style={styles.heroFoot}>
                <Text style={styles.heroHint}>Próximo vencimento {date(s.charges.next_due_on)}</Text>
                {s.charges.overdue_cents > 0 ? (
                  <View style={styles.overdue}>
                    <Text style={styles.overdueText}>{hidden ? 'Há valor vencido' : `${money(s.charges.overdue_cents)} vencido`}</Text>
                  </View>
                ) : (
                  <Ionicons name="arrow-forward-circle" size={26} color="rgba(255,255,255,0.85)" />
                )}
              </View>
              {boleto?.charge ? (
                <View style={styles.heroActions}>
                  {boleto.charge.pdf_available ? (
                    <Pressable
                      onPress={secondCopy}
                      disabled={opening}
                      accessibilityRole="button"
                      accessibilityLabel={`Segunda via do boleto que vence em ${date(boleto.due_on)}`}
                      style={({ pressed }) => [styles.heroButton, styles.heroButtonMain, (pressed || opening) && { opacity: 0.75 }]}
                    >
                      <Ionicons name="document-text" size={16} color={colors.sidebar} />
                      <Text style={[styles.heroButtonText, { color: colors.sidebar }]}>{opening ? 'Abrindo…' : '2ª via do boleto'}</Text>
                    </Pressable>
                  ) : null}
                  {boleto.charge.digitable_line ? (
                    <Pressable
                      onPress={() => copyText(boleto.charge?.digitable_line as string, 'Código do boleto')}
                      accessibilityRole="button"
                      accessibilityLabel="Copiar o código do boleto"
                      style={({ pressed }) => [styles.heroButton, pressed && { opacity: 0.75 }]}
                    >
                      <Ionicons name="copy-outline" size={16} color="#ffffff" />
                      <Text style={styles.heroButtonText}>Copiar código</Text>
                    </Pressable>
                  ) : null}
                </View>
              ) : null}
            </View>
          ) : null}
        </LinearGradient>
      </Pressable>

      {approved.length > 0 ? (
        <View style={styles.approved} accessibilityRole="alert">
          <View style={styles.approvedIcon}>
            <Ionicons name="checkmark-circle" size={22} color={colors.success} />
          </View>
          <Pressable style={{ flex: 1, gap: 2 }} onPress={() => router.push('/reservas')} accessibilityRole="button">
            <Text style={styles.approvedTitle}>{approved.length > 1 ? 'Reservas aprovadas' : 'Reserva aprovada'}</Text>
            {approved.map((r) => (
              <Text key={r.id} style={styles.approvedText}>
                {r.area} · {date(r.reserved_on)}
                {r.starts_time ? ` às ${r.starts_time}` : ''}
              </Text>
            ))}
          </Pressable>
          <Pressable onPress={dismissApprovals} hitSlop={10} accessibilityRole="button" accessibilityLabel="Fechar aviso">
            <Ionicons name="close" size={20} color={colors.muted} />
          </Pressable>
        </View>
      ) : null}
      {services.length > 0 ? (
        <View style={styles.services}>
          {services.map((c) => (
            <Pressable
              key={c.label}
              onPress={() => router.push(c.href)}
              accessibilityRole="button"
              accessibilityLabel={c.label}
              style={({ pressed }) => [styles.service, pressed && { transform: [{ scale: 0.97 }], opacity: 0.9 }]}
            >
              <View style={[styles.serviceIcon, { backgroundColor: c.color }]}>
                <Ionicons name={c.icon} size={24} color="#ffffff" />
              </View>
              <Text style={styles.serviceLabel} numberOfLines={2}>
                {c.label}
              </Text>
              <Text style={styles.serviceHint} numberOfLines={1}>
                {c.hint}
              </Text>
            </Pressable>
          ))}
        </View>
      ) : null}

      {summary.loading ? <Loading /> : null}
      {summary.error ? <ErrorBox error={summary.error} onRetry={summary.reload} /> : null}
      {s && s.features.length === 0 ? (
        <Card>
          <CardTitle>Tudo pronto</CardTitle>
          <Body muted>O seu condomínio ainda não ligou nenhuma função do app. Assim que liberar, ela aparece aqui.</Body>
        </Card>
      ) : null}

      {feed.data && feed.data.length > 0 ? (
        <>
          <View style={styles.sectionHead}>
            <SectionTitle>Notificações</SectionTitle>
            <Pressable onPress={() => router.push('/avisos')} accessibilityRole="button" hitSlop={8}>
              <Text style={styles.seeAll}>Ver todas</Text>
            </Pressable>
          </View>
          {feed.data.slice(0, 4).map((n, i) => (
            <NotificationRow key={`${n.kind}${i}${n.at}`} item={n} />
          ))}
        </>
      ) : null}
    </Screen>
  );
}

const styles = StyleSheet.create({
  avatar: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.sidebar,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: colors.accent,
    marginBottom: 2,
  },
  avatarText: { fontFamily: fonts.bold, fontSize: 15, color: colors.accent },
  heroWrap: { borderRadius: 24, overflow: 'hidden' },
  services: { flexDirection: 'row', flexWrap: 'wrap', gap: space[3] },
  service: {
    width: '47.8%',
    flexGrow: 1,
    backgroundColor: colors.surface,
    borderRadius: 22,
    padding: space[4],
    gap: 4,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    shadowColor: '#0d2e27',
    shadowOpacity: 0.06,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 },
    elevation: 2,
  },
  serviceIcon: { width: 46, height: 46, borderRadius: 14, alignItems: 'center', justifyContent: 'center', marginBottom: space[2] },
  serviceLabel: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text },
  serviceHint: { fontFamily: fonts.regular, fontSize: 12, color: colors.muted },
  sectionHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  seeAll: { fontFamily: fonts.semibold, fontSize: 14, color: colors.brand },
  approved: { flexDirection: 'row', alignItems: 'center', gap: space[3], backgroundColor: '#e6f6ee', borderRadius: radius.xl, padding: space[4], borderWidth: 1, borderColor: '#bfe6d0' },
  approvedIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center' },
  approvedTitle: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  approvedText: { fontFamily: fonts.regular, fontSize: 13, color: colors.muted },
  hero: { borderRadius: 24, padding: space[5], gap: space[4], overflow: 'hidden' },
  // Ícone da Connect como marca d'água no canto do cartão (pedido do Djalma, 14/11).
  heroMark: {
    position: 'absolute',
    width: 180,
    height: 233,
    right: -34,
    top: -28,
    opacity: 0.16,
    transform: [{ rotate: '-12deg' }],
  },
  heroActions: { flexDirection: 'row', flexWrap: 'wrap', gap: space[2], marginTop: space[3] },
  heroButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: 14,
    height: 38,
    borderRadius: 19,
    backgroundColor: 'rgba(255,255,255,0.16)',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: 'rgba(255,255,255,0.35)',
  },
  heroButtonMain: { backgroundColor: colors.accent, borderColor: colors.accent },
  heroButtonText: { fontFamily: fonts.semibold, fontSize: 14, color: '#ffffff' },
  condoRow: { flexDirection: 'row', alignItems: 'center', gap: 6, alignSelf: 'flex-start' },
  labelRow: { flexDirection: 'row', alignItems: 'center', gap: space[2] },
  heroTop: { flexDirection: 'row', alignItems: 'flex-start' },
  condo: { fontFamily: fonts.semibold, fontSize: 17, color: '#ffffff', flexShrink: 1 },
  unitChip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    alignSelf: 'flex-start',
    marginTop: space[2],
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(197,161,49,0.16)',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: 'rgba(197,161,49,0.5)',
  },
  unitText: { fontFamily: fonts.semibold, fontSize: 12.5, color: colors.accent },
  heroMoney: { gap: 2 },
  heroLabel: { fontFamily: fonts.medium, fontSize: 13, color: 'rgba(255,255,255,0.7)' },
  heroValue: { fontFamily: fonts.bold, fontSize: 32, letterSpacing: -0.8, color: '#ffffff' },
  heroFoot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: space[1], gap: space[2] },
  heroHint: { fontFamily: fonts.medium, fontSize: 13, color: 'rgba(255,255,255,0.8)', flexShrink: 1 },
  overdue: { backgroundColor: '#fde8e6', borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 4 },
  overdueText: { fontFamily: fonts.semibold, fontSize: 12, color: colors.danger },
});
