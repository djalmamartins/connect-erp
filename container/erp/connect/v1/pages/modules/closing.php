<?php
/**
 * Fechamento mensal (AP-030, ADR 0031/0032). Com dados reais ($closing, de Panel::closingData):
 * resumo da competência no condomínio da barra superior ou em todos do escopo, e Fechar/Reabrir
 * (POST com CSRF para MonthClosings, só com um condomínio escolhido). Sem dados (banco
 * indisponível ou sem erp.closing.view) fica a demonstração.
 * @var array $currentPage @var ?array $closing @var string $closingCompetence @var bool $canManageClosing
 */
$this->layout('default');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Confira o resumo da competência, feche para travar os lançamentos e reabra com motivo quando precisar corrigir.',
]);
$closing ??= null;
$closingCompetence ??= date('Y-m');
$canManageClosing ??= false;
$monthLabel = substr($closingCompetence, 5, 2) . '/' . substr($closingCompetence, 0, 4);
$brl = static fn (int $cents): string => ($cents < 0 ? '-' : '') . 'R$ ' . number_format(abs($cents) / 100, 2, ',', '.');
$dateTime = static fn (?string $value): string => $value ? date('d/m/Y H:i', (int)strtotime($value)) : '';
$badges = ['open' => ['Aberta', 'warning'], 'closed' => ['Fechada', 'success'], 'reopened' => ['Reaberta', 'info']];
$route = erp_url('/financeiro/fechamento-mensal');
?>
<form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Competência do fechamento">
    <div class="erp-field erp-field--compact"><label for="close-month">Competência</label><input class="erp-input" type="month" id="close-month" name="competencia" value="<?= $this->e($closingCompetence) ?>" required></div>
    <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('calendar', 'erp-icon--sm') ?>Ver competência</button></div>
</form>

<?php if ($closing === null): ?>
<?php
$this->insert('components/notifications/proposal');
$checks = [
    ['Contas a pagar da competência baixadas', true], ['Recebimentos conciliados com o extrato', true], ['Retornos bancários processados', false],
    ['Lançamentos fixos gerados', true], ['Rateio das despesas calculado', false], ['Documentos anexados aos pagamentos', true],
];
$done = count(array_filter($checks, static fn (array $c): bool => $c[1]));
?>
<div class="erp-grid erp-grid--main">
    <section class="erp-card" aria-labelledby="close-checks">
        <div class="erp-card__head"><div><h2 id="close-checks">Checklist do fechamento</h2><p><?= $done ?> de <?= count($checks) ?> itens concluídos · demonstração</p></div><span class="erp-badge erp-badge--<?= $done === count($checks) ? 'success' : 'warning' ?>"><?= $done === count($checks) ? 'Pronto para fechar' : 'Com pendências' ?></span></div>
        <div class="erp-progress" aria-hidden="true" style="margin-bottom:12px"><span style="width: <?= round($done / count($checks) * 100) ?>%"></span></div>
        <ul class="erp-list">
            <?php foreach ($checks as [$label, $ok]): ?>
                <li><span class="erp-list__icon<?= $ok ? '' : ' erp-list__icon--warning' ?>"><?= $icon($ok ? 'check' : 'alert', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><?= $this->e($label) ?></strong><small><?= $ok ? 'Concluído' : 'Pendente' ?></small></div></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <aside class="erp-card" aria-labelledby="close-summary">
        <div class="erp-card__head"><div><h2 id="close-summary">Resumo da competência</h2><p>Demonstração</p></div></div>
        <dl class="erp-detail-grid erp-detail-grid--single">
            <div><dt>Saldo anterior</dt><dd>R$ 182.410,22</dd></div>
            <div><dt>Receitas</dt><dd>R$ 96.320,00</dd></div>
            <div><dt>Despesas</dt><dd>R$ 88.947,15</dd></div>
            <div><dt>Saldo final</dt><dd><strong>R$ 189.783,07</strong></dd></div>
        </dl>
        <div class="erp-form__actions">
            <button type="button" class="erp-btn erp-btn--primary" data-erp-pending="Fechamento pendente: o resumo real aparece quando o banco do financeiro estiver disponível. Nada foi fechado."<?= $done === count($checks) ? '' : ' aria-disabled="true"' ?>>Fechar competência</button>
        </div>
    </aside>
</div>
<?php else:
$rows = $closing['rows'] ?? [];
$totals = $closing['totals'] ?? [];
$single = ($closing['condominium_id'] ?? null) !== null && count($rows) === 1 ? $rows[0] : null;
$t = static fn (string $key): int => (int)($totals[$key] ?? 0);
$openCents = $t('open_revenue_cents') + $t('open_expense_cents');
?>
    <div class="erp-closing-status">
        <?php if ($single !== null): [$badgeText, $badgeTone] = $badges[$single['status']] ?? $badges['open']; ?>
            <span class="erp-badge erp-badge--<?= $badgeTone ?>"><?= $badgeText ?></span>
            <span><?= $this->e($single['condominium_name']) ?> · competência <strong><?= $this->e($monthLabel) ?></strong> · <?= (int)$single['entries'] ?> lançamento(s)</span>
            <?php if ($single['status'] === 'closed'): ?><span>Fechada em <?= $this->e($dateTime($single['closed_at'])) ?></span><?php endif; ?>
        <?php else: ?>
            <span class="erp-badge erp-badge--<?= $t('condominiums') > 0 && $t('closed') === $t('condominiums') ? 'success' : 'warning' ?>"><?= $t('closed') ?> de <?= $t('condominiums') ?> fechados</span>
            <span>Todos os condomínios do seu escopo · competência <strong><?= $this->e($monthLabel) ?></strong></span>
        <?php endif; ?>
    </div>
    <?php if ($single !== null && $single['status'] === 'reopened'): ?>
        <div class="erp-alert erp-alert--warning"><?= $icon('info') ?><div><strong>Reaberta em <?= $this->e($dateTime($single['reopened_at'])) ?></strong>Motivo: <?= $this->e((string)$single['reopen_reason']) ?></div></div>
    <?php endif; ?>

    <section class="erp-grid erp-grid--kpi" aria-label="Resumo da competência">
        <?php foreach ([
            ['label' => 'Receitas', 'value' => $brl($t('revenue_cents')), 'meta' => $brl($t('open_revenue_cents')) . ' a receber', 'icon' => 'arrow-down', 'tone' => '', 'trend' => null],
            ['label' => 'Despesas', 'value' => $brl($t('expense_cents')), 'meta' => $brl($t('open_expense_cents')) . ' a pagar', 'icon' => 'arrow-up', 'tone' => 'warning', 'trend' => null],
            ['label' => 'Resultado', 'value' => $brl($t('result_cents')), 'meta' => 'Receitas menos despesas', 'icon' => 'scale', 'tone' => $t('result_cents') < 0 ? 'danger' : 'info', 'trend' => null],
            ['label' => 'Em aberto', 'value' => $brl($openCents), 'meta' => 'Lançamentos sem baixa', 'icon' => 'clock', 'tone' => $openCents > 0 ? 'warning' : '', 'trend' => null],
        ] as $i => $kpi): $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 40]); endforeach; ?>
    </section>

    <section class="erp-card" aria-labelledby="close-rows">
        <div class="erp-card__head"><div><h2 id="close-rows"><?= $single !== null ? 'Resumo do condomínio' : 'Situação por condomínio' ?></h2><p>Lançamentos válidos da competência <?= $this->e($monthLabel) ?></p></div></div>
        <?php if ($rows === []): ?>
            <p class="erp-muted">Nenhum condomínio no seu escopo.</p>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="close-rows" tabindex="0"><table class="erp-table">
                <thead><tr><th>Condomínio</th><th>Situação</th><th class="is-num">Receitas</th><th class="is-num">Despesas</th><th class="is-num">Resultado</th><th class="is-num">Em aberto</th><th class="is-num">Lançamentos</th></tr></thead>
                <tbody><?php foreach ($rows as $row): [$badgeText, $badgeTone] = $badges[$row['status']] ?? $badges['open']; ?>
                    <tr>
                        <td><strong><?= $this->e($row['condominium_name']) ?></strong></td>
                        <td><span class="erp-badge erp-badge--<?= $badgeTone ?>"><?= $badgeText ?></span></td>
                        <td class="is-num"><?= $brl((int)$row['revenue_cents']) ?></td>
                        <td class="is-num"><?= $brl((int)$row['expense_cents']) ?></td>
                        <td class="is-num"><?= $brl((int)$row['result_cents']) ?></td>
                        <td class="is-num"><?= $brl((int)$row['open_revenue_cents'] + (int)$row['open_expense_cents']) ?></td>
                        <td class="is-num"><?= (int)$row['entries'] ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php if ($canManageClosing && $single !== null): ?>
        <section class="erp-card" aria-labelledby="close-actions">
            <?php if ($single['status'] === 'closed'): ?>
                <div class="erp-card__head"><div><h2 id="close-actions">Reabrir competência</h2><p>Os lançamentos de <?= $this->e($monthLabel) ?> voltam a aceitar alterações. O motivo fica registrado na auditoria.</p></div></div>
                <form class="erp-form" method="post" action="<?= $this->e($route . '/reabrir') ?>" data-erp-confirm="Reabrir a competência <?= $this->e($monthLabel) ?>? Os lançamentos voltam a aceitar alterações.">
                    <?= csrf_input() ?>
                    <input type="hidden" name="competence" value="<?= $this->e($closingCompetence) ?>">
                    <div class="erp-field"><label for="close-reason">Motivo da reabertura</label><textarea class="erp-textarea" id="close-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: lançamento de energia com valor errado"></textarea></div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn"><?= $icon('edit', 'erp-icon--sm') ?>Reabrir competência</button></div>
                </form>
            <?php else: ?>
                <div class="erp-card__head"><div><h2 id="close-actions">Fechar competência</h2><p>Grava o resumo acima e trava os lançamentos de <?= $this->e($monthLabel) ?>.<?= ((int)$single['open_revenue_cents'] + (int)$single['open_expense_cents']) > 0 ? ' Ainda há lançamentos em aberto.' : '' ?></p></div></div>
                <form class="erp-form" method="post" action="<?= $this->e($route . '/fechar') ?>" data-erp-confirm="Fechar a competência <?= $this->e($monthLabel) ?>? O resumo é gravado e os lançamentos do mês ficam travados.">
                    <?= csrf_input() ?>
                    <input type="hidden" name="competence" value="<?= $this->e($closingCompetence) ?>">
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('lock', 'erp-icon--sm') ?>Fechar competência</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php elseif ($single === null && $rows !== []): ?>
        <p class="erp-muted">Para fechar ou reabrir, escolha um condomínio na barra superior.</p>
    <?php endif; ?>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
