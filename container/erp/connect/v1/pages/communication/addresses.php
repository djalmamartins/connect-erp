<?php
/**
 * Endereços de comunicação (AP-139; ADR 0059 e 0075) com dados reais: por unidade ativa, as pessoas
 * ativas com o e-mail mascarado e se recebem comunicado e cobrança por e-mail. ?sem=comunicado|cobranca
 * mostra só as unidades sem ninguém para aquele envio. A correção é no cadastro de pessoas.
 * @var array $currentPage @var ?int $commContext @var array $addresses @var ?string $addressFilter
 * @var list<array> $addressUnits unidades já filtradas pelo controlador @var array<string, string> $addressRelationships
 */

$this->layout('default');
$route = erp_url($currentPage['route']);
$totals = $addresses['totals'];
$units = $addressUnits;
$relationships = $addressRelationships;
$peopleRoute = '/cadastros/proprietarios-e-inquilinos';
$filters = ['' => 'Todas as unidades', 'comunicado' => 'Sem ninguém para comunicado', 'cobranca' => 'Sem ninguém para cobrança'];

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Quem recebe os comunicados e os avisos de cobrança em cada unidade. Para corrigir um e-mail ou a escolha da pessoa, abra o cadastro dela.',
]);
?>
<div class="erp-stack">
    <section class="erp-card" aria-labelledby="addresses-totals-title">
        <div class="erp-card__head"><div><h2 id="addresses-totals-title">Resumo</h2><p><?= $commContext === null ? 'Todos os condomínios do seu escopo.' : 'Unidades ativas do condomínio.' ?></p></div></div>
        <dl class="erp-import-facts">
            <div><dt>Unidades ativas</dt><dd><?= (int)$totals['units'] ?></dd></div>
            <div><dt>Sem ninguém para comunicado</dt><dd<?= $totals['without_notices'] > 0 ? ' class="erp-panel-negative"' : '' ?>><?= (int)$totals['without_notices'] ?></dd></div>
            <div><dt>Sem ninguém para cobrança</dt><dd<?= $totals['without_billing'] > 0 ? ' class="erp-panel-negative"' : '' ?>><?= (int)$totals['without_billing'] ?></dd></div>
        </dl>
        <nav class="erp-comm-filter" aria-label="Filtrar unidades"><?php foreach ($filters as $key => $label): $active = ($key === '' ? null : $key) === $addressFilter; ?>
            <a class="erp-btn erp-btn--sm<?= $active ? ' erp-btn--primary' : '' ?>" href="<?= $this->e($route . ($key === '' ? '' : '?sem=' . $key)) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= $this->e($label) ?></a>
        <?php endforeach; ?></nav>
    </section>

    <section class="erp-card" aria-labelledby="addresses-title">
        <div class="erp-card__head"><div><h2 id="addresses-title">Unidades</h2><p><?= count($units) ?> unidade(s)<?= $addressFilter !== null ? ' no filtro' : '' ?>.</p></div></div>
        <?php if ($units === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('check') ?></span><strong><?= $addressFilter !== null ? 'Todas as unidades têm alguém para receber' : 'Nenhuma unidade ativa' ?></strong><p><?= $addressFilter !== null ? 'Nenhuma unidade ficou de fora deste envio.' : 'Cadastre as unidades em Cadastros › Unidades.' ?></p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="addresses-title" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Unidade</th><th scope="col">Pessoa</th><th scope="col">E-mail</th><th scope="col">Comunicado</th><th scope="col">Cobrança</th></tr></thead>
                <tbody><?php foreach ($units as $u): $people = $u['people']; $span = max(1, count($people)); $unitCell = '<th scope="row" rowspan="' . $span . '">' . $this->e($u['unit_label']) . ($commContext === null ? '<br><span class="erp-muted">' . $this->e($u['condominium_name']) . '</span>' : '')
                    . ($u['notices'] === 0 ? '<br><span class="erp-badge erp-badge--warning">Sem comunicado</span>' : '') . ($u['billing'] === 0 ? '<br><span class="erp-badge erp-badge--warning">Sem cobrança</span>' : '') . '</th>'; ?>
                    <?php if ($people === []): ?>
                        <tr><?= $unitCell ?><td colspan="4" class="erp-muted">Nenhuma pessoa ativa nesta unidade. <a href="<?= $this->e(erp_url($peopleRoute . '/novo')) ?>">Cadastrar pessoa</a></td></tr>
                    <?php else: foreach ($people as $i => $p): ?>
                        <tr><?= $i === 0 ? $unitCell : '' ?>
                            <td><a href="<?= $this->e(erp_url($peopleRoute . '/' . (int)$p['resident_id'])) ?>"><?= $this->e($p['name']) ?></a><br><span class="erp-muted"><?= $this->e($relationships[$p['relationship']] ?? $p['relationship']) ?></span></td>
                            <td><?= $p['valid_email'] ? $this->e((string)$p['email']) : '<span class="erp-panel-negative">Sem e-mail válido</span>' ?></td>
                            <td><?= $p['notices'] ? '<span class="erp-badge erp-badge--success">Recebe</span>' : '<span class="erp-badge erp-badge--plain">' . ($p['valid_email'] ? 'Recusou' : 'Não recebe') . '</span>' ?></td>
                            <td><?= $p['billing'] ? '<span class="erp-badge erp-badge--success">Recebe</span>' : '<span class="erp-badge erp-badge--plain">' . ($p['valid_email'] ? 'Não autorizou' : 'Não recebe') . '</span>' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</div>
