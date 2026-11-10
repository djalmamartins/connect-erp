<?php
/**
 * Saldo inicial por centro de custo (AP-162; ADR 0144, sobre o ADR 0085) com dados reais do condomínio da
 * barra superior. Gravar usa POST /api/v1/cost-center-openings (erp.js, data-erp-settings); um centro de
 * custo tem um saldo inicial por condomínio, e gravar de novo substitui. Sem condomínio, a tela pede um.
 * @var array $currentPage @var ?int $settingsContext @var bool $settingsNeedsCondo @var ?array $openings @var list<array> $costCenters @var bool $canManageSettings
 */
use Source\Controllers\Erp\Connect\ReportScreens;

$this->layout('default');
$condo = \Source\Support\Erp\CondominiumContext::current();
$condoName = (string)($condo['name'] ?? 'o condomínio');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Saldo de partida de cada centro de custo numa data, para o relatório de centros de custo começar do valor certo. Valor negativo = saldo devedor.',
    'pageActions' => '',
]);
?>
<?php if ($settingsNeedsCondo): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Escolha um condomínio</strong><p>O saldo inicial é de cada condomínio. Escolha o condomínio na barra superior.</p></div>
<?php else: ?>
<div class="erp-stack" data-erp-settings data-openings-api="<?= $this->e(url('/api/v1/cost-center-openings')) ?>" data-condominium="<?= (int)$settingsContext ?>">
    <?= csrf_input() ?>
    <?php if ($canManageSettings): ?>
        <section class="erp-card" aria-labelledby="op-form-title">
            <div class="erp-card__head"><div><h2 id="op-form-title">Lançar saldo inicial</h2><p><?= $this->e($condoName) ?> · gravar de novo o mesmo centro de custo substitui o saldo anterior.</p></div></div>
            <?php if ($costCenters === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('clipboard') ?></span><strong>Nenhum centro de custo ativo</strong><p>Cadastre os centros de custo em <a href="<?= $this->e(erp_url('/financeiro/centros-de-custo')) ?>">Centros de custo</a>.</p></div>
            <?php else: ?>
                <form class="erp-form" data-opening-form aria-labelledby="op-form-title">
                    <div data-form-error></div>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-4"><label for="op-center">Centro de custo</label><select class="erp-select" id="op-center" name="cost_center_id" required><option value="">Escolha</option><?php foreach ($costCenters as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $this->e($c['label']) ?></option><?php endforeach; ?></select></div>
                        <div class="erp-field erp-col-3"><label for="op-date">Data do saldo</label><input class="erp-input" type="date" id="op-date" name="opened_on" max="<?= date('Y-m-d') ?>" value="<?= date('Y-01-01') ?>" required></div>
                        <div class="erp-field erp-col-3"><label for="op-amount">Valor (R$)</label><input class="erp-input" type="text" id="op-amount" name="amount_cents" inputmode="decimal" placeholder="0,00" pattern="^-?\d{1,3}(\.?\d{3})*(,\d{1,2})?$" required><small class="erp-muted">Use o sinal de menos para saldo devedor</small></div>
                        <div class="erp-field erp-col-12"><label for="op-notes">Observação</label><input class="erp-input" type="text" id="op-notes" name="notes" maxlength="255"></div>
                    </div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar saldo inicial</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="op-title">
        <div class="erp-card__head"><div><h2 id="op-title">Saldos iniciais</h2><p><?= $this->e($condoName) ?> · <?= count($openings['rows']) ?> centro(s) de custo · total <?= $this->e(ReportScreens::brl((int)$openings['total_cents'])) ?></p></div></div>
        <?php if ($openings['rows'] === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('wallet') ?></span><strong>Nenhum saldo inicial</strong><p>Sem saldo inicial, os centros de custo começam do zero.</p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="op-title" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Código</th><th scope="col">Centro de custo</th><th scope="col">Data</th><th scope="col" class="is-num">Valor</th><th scope="col">Observação</th><th scope="col">Atualizado em</th></tr></thead>
                <tbody><?php foreach ($openings['rows'] as $r): ?><tr>
                    <td><?= $this->e($r['code']) ?></td><td><?= $this->e($r['name']) ?></td><td class="erp-nowrap"><?= $this->e(ReportScreens::brDate($r['opened_on'])) ?></td>
                    <td class="is-num erp-nowrap"><?= $this->e(ReportScreens::brl((int)$r['amount_cents'])) ?></td><td><?= $r['notes'] !== null ? $this->e((string)$r['notes']) : '<span class="erp-muted">—</span>' ?></td>
                    <td class="erp-nowrap"><?= $this->e(ReportScreens::brDate($r['updated_at'], true)) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
