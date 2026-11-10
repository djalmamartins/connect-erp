<?php
/**
 * Registros de Entregas (AP-136; ADR 0146, sobre o ADR 0166) com dados reais: o histórico das encomendas da
 * portaria com filtros, resumo e a baixa da retirada pela API POST /api/v1/delivery-pickups (erp.js,
 * data-erp-deliveries). A chegada continua no cadastro de Entregas (AP-135).
 * @var array $currentPage @var ?int $deliveryContext @var array $deliveryFilters @var ?string $deliveryError @var ?array $deliveryLog
 * @var list<array> $deliveryUnits @var bool $canPickUp
 */
use Source\Controllers\Erp\Connect\DeliveryScreens as Screens;
use Source\Controllers\Erp\Connect\ReportScreens;

$this->layout('default');
$route = erp_url($currentPage['route']);
$arrivalRoute = erp_url($catalog['pages']['AP-135']['route'] ?? '/operacional/entregas');
$f = $deliveryFilters;
$log = $deliveryLog;
$rows = $log['rows'] ?? [];
$s = $log['summary'] ?? null;
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Encomendas recebidas na portaria, quem retirou e quando. A chegada é registrada em Entregas; aqui se dá a baixa da retirada.',
    'pageActions' => '<a class="erp-btn" href="' . $this->e($arrivalRoute) . '">' . $icon('plus', 'erp-icon--sm') . 'Registrar chegada</a>',
]);
?>
<div class="erp-stack" data-erp-deliveries data-api="<?= $this->e(url('/api/v1/delivery-pickups')) ?>">
    <?= csrf_input() ?>
    <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtros das entregas">
        <div class="erp-field erp-field--compact"><label for="dl-status">Situação</label><select class="erp-select" id="dl-status" name="situacao"><option value="">Todas</option><?php foreach (Screens::STATUS as $value => [$label]): ?><option value="<?= $value ?>"<?= $f['situacao'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <div class="erp-field erp-field--compact"><label for="dl-from">Chegada de</label><input class="erp-input" type="date" id="dl-from" name="de" value="<?= $this->e((string)$f['de']) ?>"></div>
        <div class="erp-field erp-field--compact"><label for="dl-to">Chegada até</label><input class="erp-input" type="date" id="dl-to" name="ate" value="<?= $this->e((string)$f['ate']) ?>"></div>
        <?php if ($deliveryUnits !== []): ?><div class="erp-field erp-field--compact"><label for="dl-unit">Unidade</label><select class="erp-select" id="dl-unit" name="unidade"><option value="">Todas</option><?php foreach ($deliveryUnits as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $f['unidade'] === (int)$u['id'] ? ' selected' : '' ?>><?= $this->e($u['label']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button></div>
    </form>

    <?php if ($deliveryError !== null): ?>
        <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong>Não foi possível listar as entregas</strong><?= $this->e($deliveryError) ?></div></div>
    <?php else: ?>
        <section class="erp-grid erp-grid--kpi" aria-label="Resumo das entregas">
            <?php foreach ([
                ['Aguardando retirada', (string)$s['waiting'], 'warning'], ['Retiradas', (string)$s['picked_up'], ''], ['Canceladas', (string)$s['cancelled'], ''],
                ['Média até a retirada', $s['average_pickup_days'] === null ? '—' : number_format((float)$s['average_pickup_days'], 1, ',', '.') . ' dia(s)', ''],
                ['Espera mais antiga', (int)$s['oldest_waiting_days'] . ' dia(s)', (int)$s['oldest_waiting_days'] > 7 ? 'danger' : ''],
            ] as $i => [$label, $value, $tone]): $this->insert('components/cards/kpi', ['kpi' => ['label' => $label, 'value' => $value, 'meta' => $deliveryContext === null ? 'Todos os condomínios do seu escopo' : 'Neste filtro', 'icon' => 'clipboard', 'tone' => $tone, 'trend' => null], 'delay' => $i * 40]); endforeach; ?>
        </section>
        <?php if (!empty($log['truncated'])): ?>
            <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong>Lista cortada</strong>Mostrando as <?= count($rows) ?> entregas mais recentes. Use o período para ver as outras.</div></div>
        <?php endif; ?>
        <section class="erp-card" aria-labelledby="dl-title">
            <div class="erp-card__head"><div><h2 id="dl-title">Entregas</h2><p><?= count($rows) ?> registro(s)<?= $deliveryContext === null ? ', em todos os condomínios' : '' ?>.</p></div></div>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('clipboard') ?></span><strong>Nenhuma entrega neste filtro</strong><p>A chegada das encomendas é registrada em <a href="<?= $this->e($arrivalRoute) ?>">Entregas</a>.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="dl-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Chegada</th><?php if ($deliveryContext === null): ?><th scope="col">Condomínio</th><?php endif; ?><th scope="col">Unidade</th><th scope="col">Destinatário</th><th scope="col">Transportadora</th><th scope="col">Rastreio</th><th scope="col">Situação</th><th scope="col">Retirada</th><?php if ($canPickUp): ?><th scope="col" class="is-num">Ações</th><?php endif; ?></tr></thead>
                    <tbody><?php foreach ($rows as $r): [$label, $tone] = Screens::STATUS[$r['status']] ?? [$r['status'], 'plain']; ?><tr<?= $r['status'] === 'inactive' ? ' class="erp-muted"' : '' ?>>
                        <td class="erp-nowrap"><?= $this->e(ReportScreens::brDate($r['received_on'])) ?><?= $r['received_time'] ? ' ' . $this->e(substr((string)$r['received_time'], 0, 5)) : '' ?></td>
                        <?php if ($deliveryContext === null): ?><td><?= $this->e($r['condominium_name']) ?></td><?php endif; ?>
                        <td><?= $this->e($r['unit_label']) ?></td>
                        <td><?= $this->e($r['recipient']) ?></td>
                        <td><?= $this->e(Screens::CARRIERS[$r['carrier']] ?? $r['carrier']) ?></td>
                        <td><?= $r['tracking_code'] ? $this->e((string)$r['tracking_code']) : '<span class="erp-muted">—</span>' ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span><?php if ($r['waiting_days'] !== null): ?><br><span class="erp-muted"><?= (int)$r['waiting_days'] ?> dia(s) esperando</span><?php endif; ?></td>
                        <td><?= $r['picked_up_on'] ? $this->e(ReportScreens::brDate((string)$r['picked_up_on'])) . '<br><span class="erp-muted">' . $this->e((string)$r['picked_up_by']) . '</span>' : '<span class="erp-muted">—</span>' ?></td>
                        <?php if ($canPickUp): ?><td class="is-num"><?php if ($r['status'] === 'active'): ?><button type="button" class="erp-btn erp-btn--sm" data-pickup-open data-id="<?= (int)$r['id'] ?>" data-received="<?= $this->e($r['received_on']) ?>"
                            data-label="<?= $this->e($r['unit_label'] . ' · ' . $r['recipient'] . ' · ' . (Screens::CARRIERS[$r['carrier']] ?? $r['carrier'])) ?>" aria-label="Dar baixa na entrega de <?= $this->e($r['recipient']) ?>">Dar baixa</button><?php else: ?><span class="erp-muted">—</span><?php endif; ?></td><?php endif; ?>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canPickUp): ?>
        <dialog class="erp-modal" id="delivery-pickup" aria-labelledby="delivery-pickup-title">
            <form data-pickup-form novalidate>
                <div class="erp-modal__head"><h2 id="delivery-pickup-title">Baixa da retirada</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
                <div class="erp-modal__body">
                    <p><strong data-pickup-label></strong></p>
                    <div class="erp-field"><label for="pickup-by">Quem retirou</label><input class="erp-input" id="pickup-by" name="picked_up_by" minlength="2" maxlength="160" required autocomplete="off"></div>
                    <div class="erp-field"><label for="pickup-on">Data da retirada</label><input class="erp-input" type="date" id="pickup-on" name="picked_up_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
                    <p class="erp-field__hint">Só o nome fica guardado; foto e documento de quem retira não são guardados.</p>
                    <div data-form-error aria-live="assertive"></div>
                </div>
                <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--primary">Dar baixa</button></div>
            </form>
        </dialog>
    <?php endif; ?>
</div>
