<?php
/** Fechamento mensal: seleção, checklist de pendências e resumo. @var array $currentPage */
$this->layout('default');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Confira as pendências do condomínio antes de fechar a competência.',
]);
$this->insert('components/notifications/proposal');
$checks = [
    ['Contas a pagar da competência baixadas', true], ['Recebimentos conciliados com o extrato', true], ['Retornos bancários processados', false],
    ['Lançamentos fixos gerados', true], ['Rateio das despesas calculado', false], ['Documentos anexados aos pagamentos', true],
];
$done = count(array_filter($checks, static fn (array $c): bool => $c[1]));
?>
<form class="erp-filters" onsubmit="return false" aria-label="Competência">
    <div class="erp-field erp-field--grow"><label for="close-condo">Condomínio</label><select class="erp-select" id="close-condo"><?php foreach ($vocabulary['condos'] ?? [] as $condo): ?><option><?= $this->e($condo) ?></option><?php endforeach; ?></select></div>
    <div class="erp-field"><label for="close-month">Competência</label><input class="erp-input" type="month" id="close-month" value="2026-10"></div>
</form>
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
            <button type="button" class="erp-btn erp-btn--primary" data-erp-pending="Fechamento pendente: será executado pelo servidor quando o módulo financeiro estiver integrado. Nada foi fechado."<?= $done === count($checks) ? '' : ' aria-disabled="true"' ?>>Fechar competência</button>
        </div>
    </aside>
</div>
