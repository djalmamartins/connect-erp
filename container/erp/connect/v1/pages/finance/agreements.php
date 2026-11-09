<?php
/**
 * Inadimplência e acordos (AP-033, ADR 0039) com dados reais, no contexto da barra superior:
 * contas a receber vencidas por unidade ($delinquency, com faixas de atraso) e os acordos
 * ($agreements). As ações usam a API /api/v1/agreements (erp.js, data-erp-agreements): fazer um
 * acordo com as contas vencidas da unidade, ver as parcelas e cancelar com motivo.
 * @var array $currentPage @var array $delinquency @var array $agreements @var bool $canManageAgreements
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$filters = ['situacao' => is_string($_GET['situacao'] ?? null) ? $_GET['situacao'] : ''];
$query = static function (array $changes) use ($filters): string {
    $params = array_filter(array_merge($filters, $changes), static fn ($v): bool => $v !== null && $v !== '');
    return $params === [] ? '' : '?' . http_build_query($params);
};
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$context = \Source\Support\Erp\CondominiumContext::id();
$canManageAgreements ??= false;
$units = $delinquency['units'] ?? [];
$totals = $delinquency['totals'] ?? ['units' => 0, 'entries' => 0, 'amount_cents' => 0, 'buckets' => []];
$bucket = static fn (string $key): int => (int)($totals['buckets'][$key] ?? 0);
$bucketOf = static fn (int $days): string => $days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'));
$bucketTone = ['1-30' => 'info', '31-60' => 'warning', '61-90' => 'warning', '90+' => 'danger'];
$rows = $agreements['data'] ?? [];
$parcelsRoute = erp_url($catalog['pages']['AP-034']['route'] ?? '/cobranca/parcelas-de-acordo');

ob_start(); ?>
    <a class="erp-btn" href="<?= $this->e($parcelsRoute) ?>"><?= $icon('calendar', 'erp-icon--sm') ?>Parcelas</a>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Contas a receber vencidas por unidade e os acordos feitos com elas. No acordo, as contas vencidas são trocadas por parcelas mensais.',
    'pageActions' => $actions,
]);
?>
<div class="erp-stack" data-erp-agreements data-api="<?= $this->e(url('/api/v1/agreements')) ?>" data-chart-api="<?= $this->e(url('/api/v1/chart-accounts')) ?>">
    <?= csrf_input() ?>
    <section class="erp-grid erp-grid--kpi" aria-label="Resumo da inadimplência em <?= $this->e($date($delinquency['as_of'] ?? null)) ?>">
        <?php foreach ([
            ['label' => 'Total vencido', 'value' => $brl((int)$totals['amount_cents']), 'meta' => (isset($totals['total_cents']) ? 'Atualizado: ' . $brl((int)$totals['total_cents']) . ' · ' : '') . (int)$totals['units'] . ' unidade(s)', 'icon' => 'alert', 'tone' => (int)$totals['amount_cents'] > 0 ? 'danger' : '', 'trend' => null],
            ['label' => 'Até 30 dias', 'value' => $brl($bucket('1-30')), 'meta' => 'Atraso recente', 'icon' => 'clock', 'tone' => 'info', 'trend' => null],
            ['label' => '31 a 90 dias', 'value' => $brl($bucket('31-60') + $bucket('61-90')), 'meta' => '31–60: ' . $brl($bucket('31-60')) . ' · 61–90: ' . $brl($bucket('61-90')), 'icon' => 'clock', 'tone' => 'warning', 'trend' => null],
            ['label' => 'Mais de 90 dias', 'value' => $brl($bucket('90+')), 'meta' => 'Candidatas à cobrança judicial', 'icon' => 'scale', 'tone' => $bucket('90+') > 0 ? 'danger' : '', 'trend' => null],
        ] as $i => $kpi): $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 40]); endforeach; ?>
    </section>

    <?php if (!empty($totals['missing_index'])): ?>
        <div class="erp-alert erp-alert--warning" role="status"><?= $icon('alert') ?><div><strong>Faltam meses do IPCA</strong>Sem o índice de <?= $this->e(implode(', ', array_map(static fn (string $m): string => substr($m, 5, 2) . '/' . substr($m, 0, 4), $totals['missing_index']))) ?>, a correção desses meses entrou como zero. <?php if (isset($catalog['pages']['AP-165'])): ?><a href="<?= $this->e(erp_url($catalog['pages']['AP-165']['route'])) ?>">Lançar os índices</a>.<?php endif; ?></div></div>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="dlq-list">
        <div class="erp-card__head"><div><h2 id="dlq-list">Inadimplência por unidade</h2><p>Contas a receber vencidas e em aberto em <?= $this->e($date($delinquency['as_of'] ?? null)) ?><?= $context === null ? ', em todos os condomínios do seu escopo' : '' ?></p></div></div>
        <?php if ($units === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('check') ?></span><strong>Nenhuma conta vencida</strong><p>Todas as contas a receber estão em dia.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="dlq-list" tabindex="0"><table class="erp-table">
                <thead><tr><th>Unidade</th><th>Mais antiga</th><th>Atraso</th><th class="is-num">Valor</th><th class="is-num">Atualizado</th><th class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($units as $i => $unit): $band = $bucketOf((int)$unit['max_days_late']); ?>
                    <tr>
                        <td><strong><?= $this->e((string)$unit['unit_label']) ?></strong><?php if ($context === null): ?><small class="erp-muted"><?= $this->e((string)$unit['condominium_name']) ?></small><?php endif; ?></td>
                        <td><?= $this->e($date((string)$unit['oldest_due_on'])) ?><small class="erp-muted"><?= count($unit['entries']) ?> conta(s)</small></td>
                        <td><span class="erp-badge erp-badge--<?= $bucketTone[$band] ?>"><?= (int)$unit['max_days_late'] ?> dias</span></td>
                        <td class="is-num"><?= $brl((int)$unit['amount_cents']) ?></td>
                        <td class="is-num"><?php if (isset($unit['total_cents'])): ?><strong><?= $brl((int)$unit['total_cents']) ?></strong><small class="erp-muted">+ <?= $brl((int)$unit['charges_cents']) ?> de encargos</small><?php else: ?>—<?php endif; ?></td>
                        <td class="is-num erp-invoice-actions"><?php if ($canManageAgreements && $unit['unit_id'] !== null): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--primary" data-agreement-unit="<?= $this->e((string)json_encode(['unit_id' => (int)$unit['unit_id'], 'label' => $unit['unit_label'] . ($context === null ? ' · ' . $unit['condominium_name'] : ''), 'condominium_id' => (int)$unit['condominium_id'], 'entries' => $unit['entries']], JSON_UNESCAPED_UNICODE)) ?>">Fazer acordo</button><?php elseif ($unit['unit_id'] === null): ?><span class="erp-muted">Sem unidade</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtrar acordos">
        <div class="erp-field erp-field--compact"><label for="agr-status">Acordos</label><select class="erp-select" id="agr-status" name="situacao"><option value="">Todos</option><option value="active"<?= $filters['situacao'] === 'active' ? ' selected' : '' ?>>Ativos</option><option value="breached"<?= $filters['situacao'] === 'breached' ? ' selected' : '' ?>>Quebrados</option><option value="cancelled"<?= $filters['situacao'] === 'cancelled' ? ' selected' : '' ?>>Cancelados</option></select></div>
        <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button></div>
    </form>

    <section class="erp-card" aria-labelledby="agr-list">
        <div class="erp-card__head"><div><h2 id="agr-list">Acordos</h2><p><?= (int)($agreements['total'] ?? 0) ?> acordo(s)</p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong>Nenhum acordo</strong><p><?= $canManageAgreements ? 'Use "Fazer acordo" numa unidade inadimplente.' : 'Ainda não há acordos com este filtro.' ?></p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="agr-list" tabindex="0"><table class="erp-table">
                <thead><tr><th>Acordo</th><th>Unidade</th><th>Parcelas</th><th class="is-num">Valor</th><th>Situação</th><th class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $agreement):
                    $active = $agreement['status'] === 'active';
                    [$label, $tone] = $agreement['status'] === 'breached' ? ['Quebrado', 'danger'] : (!$active ? ['Cancelado', 'plain'] : (!empty($agreement['settled']) ? ['Quitado', 'success'] : ['Ativo', 'info']));
                    $breachDue = $active && !empty($agreement['breach']['due']); ?>
                    <tr>
                        <td><button type="button" class="erp-invoice-link" data-agreement-open="<?= (int)$agreement['id'] ?>" title="Ver as contas e as parcelas">Nº <?= (int)$agreement['id'] ?></button><small class="erp-muted"><?= $this->e($date((string)$agreement['created_at'])) ?></small></td>
                        <td><?= $this->e((string)$agreement['unit_label']) ?><?php if ($context === null): ?><small class="erp-muted"><?= $this->e((string)$agreement['condominium_name']) ?></small><?php endif; ?></td>
                        <td><?= (int)$agreement['paid_installments'] ?> de <?= (int)$agreement['installments'] ?> pagas<small class="erp-muted">1ª em <?= $this->e($date((string)$agreement['first_due_on'])) ?></small></td>
                        <td class="is-num"><?= $brl((int)$agreement['total_cents']) ?><?php if ((int)$agreement['fees_cents'] > 0 || (int)$agreement['discount_cents'] > 0): ?><small class="erp-muted">dívida <?= $brl((int)$agreement['original_cents']) ?></small><?php endif; ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span><?php if (!$active && !empty($agreement['cancel_reason'])): ?><small class="erp-muted"><?= $this->e((string)$agreement['cancel_reason']) ?></small><?php endif; ?><?php if (!empty($agreement['breach_reason'])): ?><small class="erp-muted"><?= $this->e((string)$agreement['breach_reason']) ?></small><?php endif; ?><?php if ($breachDue): ?><small class="erp-agreement-breach"><?= $icon('alert', 'erp-icon--sm') ?><?= $this->e(implode('; ', (array)($agreement['breach']['reasons'] ?? [])) ?: 'Atende à regra de quebra') ?></small><?php endif; ?></td>
                        <td class="is-num erp-invoice-actions"><div class="erp-table__actions"><?php if ($canManageAgreements && $breachDue): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--danger" data-agreement-breach="<?= (int)$agreement['id'] ?>" data-label="Nº <?= (int)$agreement['id'] ?> · <?= $this->e((string)$agreement['unit_label']) ?>">Marcar como quebrado</button><?php endif; ?><?php if ($canManageAgreements && $active && (int)$agreement['paid_installments'] === 0): ?><button type="button" class="erp-btn erp-btn--sm" data-agreement-cancel="<?= (int)$agreement['id'] ?>" data-label="Nº <?= (int)$agreement['id'] ?> · <?= $this->e((string)$agreement['unit_label']) ?>">Cancelar</button><?php endif; ?></div></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if (($agreements['pages'] ?? 0) > 1): ?>
                <nav class="erp-pagination" aria-label="Paginação">
                    <span>Página <?= (int)$agreements['page'] ?> de <?= (int)$agreements['pages'] ?></span>
                    <div class="erp-pagination__pages">
                        <?php if ($agreements['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $agreements['page'] - 1])) ?>" rel="prev">Anterior</a><?php endif; ?>
                        <?php if ($agreements['page'] < $agreements['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $agreements['page'] + 1])) ?>" rel="next">Próxima</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <dialog class="erp-modal erp-modal--wide" id="agreement-detail" aria-labelledby="agreement-detail-title">
        <div class="erp-modal__head"><h2 id="agreement-detail-title">Acordo</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
        <div class="erp-modal__body" data-agreement-detail aria-live="polite"><p class="erp-muted">Carregando…</p></div>
        <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Fechar</button></div>
    </dialog>

    <?php if ($canManageAgreements): ?>
    <dialog class="erp-modal erp-modal--wide" id="agreement-create" aria-labelledby="agreement-create-title">
        <form data-agreement-form>
            <div class="erp-modal__head"><h2 id="agreement-create-title">Fazer acordo</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p><strong data-agreement-unit-label></strong></p>
                <fieldset class="erp-agreement-entries"><legend>Contas vencidas que entram no acordo</legend><div data-agreement-entries></div></fieldset>
                <div class="erp-form__grid">
                    <div class="erp-field erp-col-6"><label for="agr-chart">Conta do plano das parcelas</label><select class="erp-select" id="agr-chart" name="chart_account_id" required data-agreement-chart><option value="">Carregando…</option></select></div>
                    <div class="erp-field erp-col-3"><label for="agr-installments">Parcelas</label><input class="erp-input" type="number" id="agr-installments" name="installments" min="1" max="60" value="1" required></div>
                    <div class="erp-field erp-col-3"><label for="agr-first">1º vencimento</label><input class="erp-input" type="date" id="agr-first" name="first_due_on" required></div>
                    <div class="erp-field erp-col-4"><label for="agr-fees">Encargos (R$)</label><input class="erp-input" id="agr-fees" name="fees_cents" inputmode="decimal" placeholder="0,00" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$"><small class="erp-muted" data-agreement-fees-hint>Correção, multa e juros calculados pela regra do condomínio; pode ajustar</small></div>
                    <div class="erp-field erp-col-4"><label for="agr-discount">Desconto (R$)</label><input class="erp-input" id="agr-discount" name="discount_cents" inputmode="decimal" placeholder="0,00" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$"></div>
                    <div class="erp-field erp-col-4"><label for="agr-notes">Observações</label><input class="erp-input" id="agr-notes" name="notes" maxlength="255"></div>
                </div>
                <div class="erp-agreement-summary" role="status" aria-live="polite" data-agreement-summary></div>
                <p class="erp-muted">As contas escolhidas ficam canceladas e ligadas ao acordo; cada parcela vira uma conta a receber nova. Se o acordo for cancelado sem parcela paga, as contas voltam a valer.</p>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Fazer acordo</button></div>
        </form>
    </dialog>

    <dialog class="erp-modal" id="agreement-breach" aria-labelledby="agreement-breach-title">
        <form data-agreement-breach-form>
            <div class="erp-modal__head"><h2 id="agreement-breach-title">Marcar acordo como quebrado</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p data-agreement-breach-label></p>
                <p class="erp-muted">O acordo atende à regra de quebra do condomínio. Marcar registra a quebra com o motivo; as parcelas ficam como estão e o acordo não pode mais ser cancelado.</p>
                <div class="erp-field"><label for="agr-breach-reason">Motivo</label><textarea class="erp-textarea" id="agr-breach-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: duas parcelas vencidas sem pagamento"></textarea></div>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--danger">Marcar como quebrado</button></div>
        </form>
    </dialog>

    <dialog class="erp-modal" id="agreement-cancel" aria-labelledby="agreement-cancel-title">
        <form data-agreement-cancel-form>
            <div class="erp-modal__head"><h2 id="agreement-cancel-title">Cancelar acordo</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p data-agreement-cancel-label></p>
                <p class="erp-muted">As parcelas são canceladas e as contas originais voltam a valer. Não é possível com parcela paga, boleto ativo ou competência fechada.</p>
                <div class="erp-field"><label for="agr-cancel-reason">Motivo do cancelamento</label><textarea class="erp-textarea" id="agr-cancel-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: condômino desistiu do acordo"></textarea></div>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--danger">Cancelar acordo</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
