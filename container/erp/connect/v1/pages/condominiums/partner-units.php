<?php
/**
 * Unidades da imobiliária (CN-012, ADR 0684): só leitura. Em cada condomínio ligado, as unidades que a imobiliária
 * administra, a situação de pagamento (em dia ou em atraso) e os moradores com contato mascarado. Nada de
 * lançamentos, boletos ou pagador. @var array $currentPage @var ?list<array> $partner @var ?string $partnerError
 * @var string $partnerAsOf
 */
$this->layout('default');
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$day = static fn (?string $v): string => $v ? date('d/m/Y', (int)strtotime($v)) : '—';
$relationships = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];
$units = array_merge(...array_map(static fn (array $c): array => $c['units'], $partner ?? [])) ?: [];
$late = array_filter($units, static fn (array $u): bool => $u['payment']['situation'] === 'em_atraso');

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'As unidades que a sua imobiliária administra e se o condomínio delas está em dia. Só consulta: boletos e acordos ficam com a Connect e com o morador.',
    'pageActions' => '',
]);
?>
<?php if ($partnerError !== null): ?>
    <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><?= $this->e($partnerError) ?></div></div>
<?php endif; ?>
<?php if ($partner === null): ?>
    <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Unidades indisponíveis</strong><p>Sem conexão com o banco de dados, as unidades não podem ser lidas.</p></div></section>
<?php else: ?>
<div class="erp-grid erp-grid--3">
    <?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Unidades administradas', 'value' => (string)count($units), 'meta' => count($partner) . ' condomínio(s)', 'icon' => 'building', 'tone' => '', 'trend' => null]]); ?>
    <?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Em atraso', 'value' => (string)count($late), 'meta' => 'Com conta vencida e não paga', 'icon' => 'alert', 'tone' => count($late) > 0 ? 'danger' : '', 'trend' => null]]); ?>
    <?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Valor em atraso', 'value' => $brl(array_sum(array_map(static fn (array $u): int => $u['payment']['overdue_cents'], $units))), 'meta' => 'Posição de ' . $day($partnerAsOf), 'icon' => 'wallet', 'tone' => '', 'trend' => null]]); ?>
</div>
<?php if ($partner === []): ?>
    <section class="erp-card" style="margin-top:16px"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Nenhum condomínio ligado</strong><p>A Connect libera os condomínios da sua imobiliária em Usuários de imobiliárias.</p></div></section>
<?php endif; ?>
<?php foreach ($partner as $c): $people = []; foreach ($c['residents'] as $p) { $people[(int)$p['unit_id']][] = $p; } ?>
    <section class="erp-card" style="margin-top:16px" aria-labelledby="pu-<?= (int)$c['id'] ?>">
        <div class="erp-card__head"><div><h2 id="pu-<?= (int)$c['id'] ?>"><?= $this->e($c['name']) ?></h2><p><?= count($c['units']) ?> unidade(s) da sua imobiliária.</p></div></div>
        <?php if ($c['units'] === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Nenhuma unidade ligada à sua imobiliária</strong><p>A Connect marca a imobiliária na ficha da unidade (Unidades › Imobiliária).</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="pu-<?= (int)$c['id'] ?>" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Unidade</th><th scope="col">Situação</th><th scope="col" class="is-num">Contas vencidas</th><th scope="col" class="is-num">Valor vencido</th><th scope="col">Vencida desde</th><th scope="col">Último pagamento</th><th scope="col">Moradores</th></tr></thead>
                <tbody><?php foreach ($c['units'] as $u): $pay = $u['payment']; $isLate = $pay['situation'] === 'em_atraso'; ?><tr>
                    <td><strong><?= $this->e($u['label']) ?></strong></td>
                    <td><span class="erp-badge erp-badge--<?= $isLate ? 'danger' : 'success' ?>"><?= $isLate ? 'Em atraso' : 'Em dia' ?></span></td>
                    <td class="is-num"><?= (int)$pay['overdue_count'] ?></td>
                    <td class="is-num erp-nowrap"><?= $isLate ? $this->e($brl((int)$pay['overdue_cents'])) : '—' ?></td>
                    <td class="erp-nowrap"><?= $this->e($day($pay['oldest_overdue_on'])) ?></td>
                    <td class="erp-nowrap"><?= $this->e($day($pay['last_paid_on'])) ?></td>
                    <td><?php if (empty($people[$u['id']])): ?><span class="erp-muted">—</span><?php else: foreach ($people[$u['id']] as $p): ?><div><?= $this->e($p['name']) ?> <span class="erp-muted">· <?= $this->e($relationships[$p['relationship']] ?? (string)$p['relationship']) ?><?= $p['phone'] ? ' · ' . $this->e((string)$p['phone']) : '' ?><?= $p['email'] ? ' · ' . $this->e((string)$p['email']) : '' ?></span></div><?php endforeach; endif; ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
<?php endif; ?>
