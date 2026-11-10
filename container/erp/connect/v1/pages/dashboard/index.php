<?php
/**
 * Dashboard da administradora (AP-001 reinterpretada, ADR-002). Desde o ADR 0157 tudo vem do MariaDB,
 * sem números de demonstração, no condomínio da barra superior ("Todos" = escopo do usuário, ADR 0030):
 *  1. indicadores de cobrança e operação (a receber vencido, a pagar em 7 dias, manutenções, ocorrências);
 *  2. receitas e despesas como no balancete (mês escolhido e 6 meses) ao lado da inadimplência por atraso;
 *  3. quadro por condomínio (ou a agenda, com um condomínio escolhido) e, na lateral, alertas e cadastros;
 *  4. atividades recentes da equipe e agenda dos próximos 30 dias.
 * Cada bloco é null sem permissão ou sem banco e mostra o próprio aviso, sem inventar valores.
 * @var list<array{label:string,count:int,href:string,icon:string}> $summary
 * @var int $condoTotal
 * @var ?array{as_of:string, cards:array<string,int>, alerts:list<array>} $operational GET /api/v1/dashboard
 * @var ?array $finance GET /api/v1/dashboard/finance
 * @var ?list<array> $agenda GET /api/v1/dashboard/agenda
 * @var ?list<array> $activities GET /api/v1/dashboard/activities
 * @var string $competence AAAA-MM
 * @var ?array{id:int,name:string,slug:string} $condoCurrent
 */
use Source\Services\Erp\FinancialDashboard;

$summary ??= [];
$condoTotal ??= 0;
$operational ??= null;
$finance ??= null;
$agenda ??= null;
$activities ??= null;
$competence ??= date('Y-m');
$realKpis = $operational !== null && $operational['cards'] !== [] ? \Source\Controllers\Erp\Connect\Panel::dashboardKpis($operational['cards']) : null;
$alertTone = ['danger' => 'danger', 'warning' => 'warning', 'info' => ''];
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$pct = static fn (?float $rate): string => $rate === null ? '—' : number_format($rate, 1, ',', '.') . '%';
$statusTone = ['Em dia' => 'success', 'Atenção' => 'warning', 'Crítico' => 'danger'];
$bucketColor = ['1-30' => '#07884c', '31-60' => '#c5a131', '61-90' => '#d97a2b', '90+' => '#d92d36'];
$monthShort = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
$isAll = $condoCurrent === null;
$nothing = $operational === null && $finance === null && $agenda === null && $activities === null;
$this->layout('default');
$firstName = trim((string)($user->first_name ?? ''));
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
$periods = FinancialDashboard::window(date('Y-m'), 12);
if (!in_array($competence, $periods, true)) {
    $periods[] = $competence;
    sort($periods);
}
ob_start(); ?>
    <form method="get" action="" class="erp-dash-period">
        <label class="erp-sr-only" for="dash-period">Competência</label>
        <select id="dash-period" name="competencia" class="erp-select" data-erp-autosubmit>
            <?php foreach (array_reverse($periods) as $period): ?><option value="<?= $this->e($period) ?>"<?= $period === $competence ? ' selected' : '' ?>><?= $this->e(ucfirst(FinancialDashboard::monthLabel($period, true))) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="erp-btn erp-sr-only-focusable">Ver</button>
    </form>
    <?php if ($finance !== null): ?><a class="erp-btn" href="<?= erp_url('/prestacao-de-contas/balancete-verificacao') ?>?de=<?= $this->e($competence) ?>&amp;ate=<?= $this->e($competence) ?>"><?= $icon('download', 'erp-icon--sm') ?>Balancete do mês</a><?php endif; ?>
    <?php if ($canSee('AP-111')): ?><a class="erp-btn erp-btn--primary" href="<?= erp_url('/comunicacao/comunicados-gerais') ?>"><?= $icon('plus', 'erp-icon--sm') ?>Comunicado</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Visão geral · ' . FinancialDashboard::monthLabel($competence, true),
    'pageTitle' => $greeting . ($firstName !== '' ? ', ' . $firstName : '') . '!',
    'pageLead' => $isAll ? 'Acompanhe a situação dos condomínios administrados pela Connect.' : 'Situação do ' . $condoCurrent['name'] . '. Para a visão geral, escolha "Todos os condomínios" na barra superior.',
    'pageActions' => $actions,
]);
?>

<?php if ($nothing): ?>
    <section class="erp-card">
        <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'chart', 'emptyTitle' => 'Indicadores ainda indisponíveis', 'emptyText' => 'O dashboard mostra cobrança, receitas e despesas, alertas e agenda a partir do banco de dados. Sem conexão com o banco ou sem permissão nos módulos, não há números para mostrar.']); ?>
    </section>
<?php endif; ?>

<?php if ($realKpis !== null): ?>
    <section class="erp-grid erp-grid--kpi" aria-label="Indicadores principais" data-erp-dashboard-real>
        <?php foreach ($realKpis as $i => $kpi): ?><a class="erp-kpi-link" href="<?= erp_url($kpi['href']) ?>"><?php $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 60]); ?></a><?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($finance !== null):
    $month = $finance['month'];
    $chart = ['labels' => array_column($finance['months'], 'label'), 'unit' => 'R$', 'series' => [
        ['name' => 'Receitas', 'values' => array_map(static fn (array $m): int => (int)round($m['revenue_cents'] / 100), $finance['months'])],
        ['name' => 'Despesas', 'values' => array_map(static fn (array $m): int => (int)round($m['expense_cents'] / 100), $finance['months'])],
    ]];
    $hasSeries = array_sum($chart['series'][0]['values']) + array_sum($chart['series'][1]['values']) > 0;
    $slices = [];
    foreach ($finance['delinquency']['buckets'] as $bucket) {
        if ($bucket['amount_cents'] > 0) {
            $slices[] = ['label' => $bucket['label'], 'value' => $bucket['amount_cents'], 'color' => $bucketColor[$bucket['key']] ?? '#6b7280'];
        }
    }
?>
    <div class="erp-grid erp-grid--main" data-erp-dashboard-finance>
        <section class="erp-card" aria-labelledby="dash-cash-title">
            <div class="erp-card__head">
                <div><h2 id="dash-cash-title">Receitas e despesas</h2><p><?= $isAll ? 'Consolidado dos condomínios' : $this->e($condoCurrent['name']) ?> · por competência, como no balancete</p></div>
                <a class="erp-btn erp-btn--sm" href="<?= erp_url('/prestacao-de-contas/balancete-verificacao') ?>?de=<?= $this->e($competence) ?>&amp;ate=<?= $this->e($competence) ?>">Abrir balancete</a>
            </div>
            <dl class="erp-dash-month">
                <div><dt>Receitas de <?= $this->e($month['label']) ?></dt><dd><?= $this->e($brl($month['revenue_received_cents'])) ?></dd><small>recebido de <?= $this->e($brl($month['revenue_cents'])) ?> lançado (<?= $this->e($pct($month['received_rate'])) ?>)</small></div>
                <div><dt>Despesas de <?= $this->e($month['label']) ?></dt><dd><?= $this->e($brl($month['expense_paid_cents'])) ?></dd><small>pago de <?= $this->e($brl($month['expense_cents'])) ?> lançado</small></div>
                <div><dt>Resultado do mês</dt><dd class="<?= $month['result_cents'] < 0 ? 'is-negative' : 'is-positive' ?>"><?= $this->e(($month['result_cents'] < 0 ? '− ' : '') . $brl(abs($month['result_cents']))) ?></dd><small>recebido menos pago · previsto <?= $this->e(($month['balance_cents'] < 0 ? '− ' : '') . $brl(abs($month['balance_cents']))) ?></small></div>
            </dl>
            <?php if ($hasSeries): ?>
                <?php $this->insert('components/charts/bars', ['chart' => $chart, 'chartCaption' => 'Receitas e despesas lançadas por competência']); ?>
            <?php else: ?>
                <p class="erp-field__hint">Nenhum lançamento nas competências de <?= $this->e(FinancialDashboard::monthLabel($finance['months'][0]['competence'], true)) ?> a <?= $this->e($month['label']) ?>.</p>
            <?php endif; ?>
        </section>
        <section class="erp-card" aria-labelledby="dash-delinquency-title">
            <div class="erp-card__head">
                <div><h2 id="dash-delinquency-title">Inadimplência por atraso</h2><p>Receitas vencidas e não pagas até hoje</p></div>
            </div>
            <p class="erp-dash-open"><strong><?= $this->e($brl($finance['delinquency']['open_cents'])) ?></strong> em aberto</p>
            <?php if ($slices !== []): ?>
                <?php $this->insert('components/charts/donut', ['slices' => $slices, 'donutLabel' => 'Inadimplência por faixa de atraso']); ?>
                <dl class="erp-dash-buckets">
                    <?php foreach ($finance['delinquency']['buckets'] as $bucket): ?><div><dt><?= $this->e($bucket['label']) ?></dt><dd><?= $this->e($brl($bucket['amount_cents'])) ?></dd></div><?php endforeach; ?>
                </dl>
            <?php else: ?>
                <p class="erp-field__hint">Nenhuma receita vencida em aberto.</p>
            <?php endif; ?>
            <p style="margin-top:16px"><a href="<?= erp_url('/relatorios/inadimplentes-geral') ?>">Ver relatório de inadimplentes →</a></p>
        </section>
    </div>
<?php endif; ?>

<?php
// Lateral: alertas e cadastros, perto um do outro (pedido de 10/11/2026).
ob_start(); ?>
    <?php if ($operational !== null): ?>
    <section class="erp-card" aria-labelledby="dash-real-alerts-title">
        <div class="erp-card__head"><div><h2 id="dash-real-alerts-title">Alertas</h2><p>O que precisa de atenção hoje</p></div></div>
        <?php if ($operational['alerts'] === []): ?>
            <p class="erp-field__hint">Nada pendente: manutenções, seguros, contratos, reservas, ocorrências e entregas em dia.</p>
        <?php else: ?>
            <ul class="erp-list erp-dash-alerts">
                <?php foreach ($operational['alerts'] as $alert): $tone = $alertTone[$alert['severity']] ?? ''; ?>
                    <li>
                        <span class="erp-list__icon<?= $tone !== '' ? ' erp-list__icon--' . $tone : '' ?>"><?= $icon($tone === '' ? 'info' : 'alert', 'erp-icon--sm') ?></span>
                        <div class="erp-list__body"><a href="<?= erp_url((string)$alert['route']) ?>"><strong><?= $this->e((string)$alert['message']) ?></strong></a></div>
                        <span class="erp-badge erp-badge--<?= $tone !== '' ? $tone : 'info' ?>"><?= (int)$alert['count'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="dash-summary-title">
        <div class="erp-card__head">
            <div><h2 id="dash-summary-title">Cadastros</h2>
                <p><?= $isAll ? ($condoTotal === 1 ? '1 condomínio' : $condoTotal . ' condomínios') . ' no seu escopo' : $this->e($condoCurrent['name']) ?></p></div>
            <?php if (!$isAll): ?><a class="erp-btn erp-btn--sm" href="<?= erp_url('/condominios/' . (int)$condoCurrent['id']) ?>"><?= $icon('building', 'erp-icon--sm') ?>Ficha</a><?php endif; ?>
        </div>
        <?php if ($summary === []): ?>
            <p class="erp-field__hint">Nenhum cadastro disponível para a sua conta.</p>
        <?php else: ?>
            <ul class="erp-summary erp-summary--side">
                <?php foreach ($summary as $item): ?>
                    <li><a href="<?= erp_url($item['href']) ?>"><span class="erp-summary__icon"><?= $icon($item['icon'], 'erp-icon--sm') ?></span><small><?= $this->e($item['label']) ?></small><strong><?= (int)$item['count'] ?></strong></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php $side = (string)ob_get_clean();

ob_start(); ?>
    <?php if ($agenda !== null): ?>
    <section class="erp-card" aria-labelledby="dash-agenda-title">
        <div class="erp-card__head"><div><h2 id="dash-agenda-title">Agenda</h2><p>Próximos <?= FinancialDashboard::AGENDA_DAYS ?> dias</p></div><a class="erp-btn erp-btn--sm" href="<?= erp_url('/operacional') ?>">Ver tudo</a></div>
        <?php if ($agenda === []): ?>
            <p class="erp-field__hint">Nenhuma assembleia, manutenção ou reserva nos próximos <?= FinancialDashboard::AGENDA_DAYS ?> dias.</p>
        <?php else: ?>
            <ul class="erp-list">
                <?php foreach ($agenda as $event): ?>
                    <li>
                        <span class="erp-list__icon erp-dash-date"><strong><?= $this->e(substr($event['date'], 8, 2)) ?></strong><small><?= $this->e($monthShort[(int)substr($event['date'], 5, 2) - 1]) ?></small></span>
                        <div class="erp-list__body"><a href="<?= erp_url($event['href']) ?>"><strong><?= $this->e($event['title']) ?></strong></a><small><?= $this->e($event['text']) ?><?= $event['time'] !== null && $event['time'] !== '' ? ' · ' . $this->e($event['time']) : '' ?></small></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
<?php $agendaCard = trim((string)ob_get_clean());

ob_start(); ?>
    <?php if ($activities !== null): ?>
    <section class="erp-card" aria-labelledby="dash-activity-title">
        <div class="erp-card__head"><div><h2 id="dash-activity-title">Atividades recentes</h2><p>Últimas ações da equipe</p></div></div>
        <?php if ($activities === []): ?>
            <p class="erp-field__hint">Nenhuma ação registrada ainda<?= $isAll ? '' : ' neste condomínio' ?>.</p>
        <?php else: ?>
            <ul class="erp-list">
                <?php foreach ($activities as $activity): ?>
                    <li>
                        <span class="erp-list__icon"><?= $icon($activity['icon'], 'erp-icon--sm') ?></span>
                        <div class="erp-list__body"><a href="<?= erp_url($activity['href']) ?>"><strong><?= $this->e($activity['title']) ?></strong></a><small><?= $this->e($activity['text']) ?></small></div>
                        <span class="erp-list__meta"><time datetime="<?= $this->e($activity['at']) ?>"><?= $this->e(FinancialDashboard::ago($activity['at'])) ?></time></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
<?php $activityCard = trim((string)ob_get_clean());
$isCondoTable = $isAll && $finance !== null;
$hasLeft = $isCondoTable || $agendaCard !== '' || $activityCard !== ''; ?>

<?php if (!$hasLeft): ?>
<div class="erp-grid erp-grid--2 erp-dash-lower"><?= $side ?></div>
<?php else: ?>
<div class="erp-grid erp-grid--main erp-dash-lower">
    <div class="erp-dash-col">
        <?php if ($isCondoTable): ?>
        <section class="erp-card erp-card--flush" aria-labelledby="dash-condos-title">
            <div class="erp-card__head">
                <div><h2 id="dash-condos-title">Condomínios</h2><p>Arrecadação e inadimplência de <?= $this->e($finance['month']['label']) ?></p></div>
                <div class="erp-search" style="display:block;width:220px">
                    <?= $icon('search', 'erp-icon--sm') ?>
                    <label class="erp-sr-only" for="dash-condo-filter">Filtrar condomínios</label>
                    <input id="dash-condo-filter" type="search" placeholder="Filtrar" data-erp-table-filter>
                </div>
            </div>
            <?php if ($finance['condominiums'] === []): ?>
                <p class="erp-field__hint" style="padding:0 20px 20px">Nenhum condomínio no seu escopo.</p>
            <?php else: ?>
            <div class="erp-table-wrap" data-erp-table data-per-page="8" role="region" aria-labelledby="dash-condos-title" tabindex="0">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th scope="col"><button type="button" class="erp-sort" data-erp-sort="0">Condomínio</button></th>
                            <th scope="col" class="is-num"><button type="button" class="erp-sort" data-erp-sort="1">Unidades</button></th>
                            <th scope="col" class="is-num"><button type="button" class="erp-sort" data-erp-sort="2">Arrecadado</button></th>
                            <th scope="col" class="is-num"><button type="button" class="erp-sort" data-erp-sort="3">Inadimplência</button></th>
                            <th scope="col">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($finance['condominiums'] as $condo): $tone = $statusTone[$condo['status']] ?? ''; ?>
                            <tr>
                                <td><a href="<?= erp_url('/condominios/' . (int)$condo['id']) ?>"><strong><?= $this->e($condo['name']) ?></strong></a><?php if ($condo['city'] !== ''): ?><small><?= $this->e($condo['city']) ?></small><?php endif; ?></td>
                                <td class="is-num" data-sort="<?= (int)$condo['units'] ?>"><?= (int)$condo['units'] ?></td>
                                <td class="is-num" data-sort="<?= $condo['received_rate'] ?? -1 ?>" title="<?= $this->e($brl($condo['received_cents']) . ' de ' . $brl($condo['revenue_cents'])) ?>"><?= $this->e($pct($condo['received_rate'])) ?><?php if ($condo['received_rate'] !== null): ?><div class="erp-progress" aria-hidden="true"><span style="width: <?= min(100, (float)$condo['received_rate']) ?>%"></span></div><?php endif; ?></td>
                                <td class="is-num" data-sort="<?= $condo['delinquency_rate'] ?? -1 ?>" title="<?= $this->e($brl($condo['overdue_cents']) . ' vencido em aberto') ?>"><?= $this->e($pct($condo['delinquency_rate'])) ?></td>
                                <td><span class="erp-badge<?= $tone !== '' ? ' erp-badge--' . $tone : '' ?>"><?= $this->e($condo['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="erp-pagination" data-erp-pager>
                <span data-erp-pager-info></span>
                <div class="erp-pagination__pages" data-erp-pager-pages></div>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if ($agendaCard !== '' || $activityCard !== ''): ?>
        <div class="erp-grid<?= $agendaCard !== '' && $activityCard !== '' ? ' erp-grid--2' : '' ?>"><?= $activityCard . $agendaCard ?></div>
        <?php endif; ?>
    </div>
    <aside class="erp-dash-col" aria-label="Alertas e cadastros"><?= $side ?></aside>
</div>
<?php endif; ?>
