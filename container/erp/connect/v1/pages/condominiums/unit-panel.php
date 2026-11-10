<?php
/**
 * Painel da unidade (AP-003, ADR 0054, 0071 e 0155) com dados reais: pessoas (o nome abre a ficha somente leitura), débitos com encargos, a vencer,
 * acordos, documentos emitidos e rotina da unidade ($unitPanel de Panel::unitPanelData, mesmo serviço
 * de GET /api/v1/panels/unit/{id}). A unidade é escolhida por ?unidade=, entre as do condomínio da
 * barra superior.
 * @var array $currentPage @var ?array $unitPanel @var list<array{id:int,condominium_id:int,label:string}> $unitPanelUnits
 * @var ?int $unitPanelSelected @var ?string $unitPanelError
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$day = static fn (?string $date): string => $date ? date('d/m/Y', (int)strtotime($date)) : '—';
$relationships = ['owner' => 'Proprietário', 'tenant' => 'Inquilino', 'resident' => 'Morador', 'financial' => 'Responsável financeiro'];
$agreementStatus = ['active' => ['Ativo', 'success'], 'breached' => ['Quebrado', 'danger']];
$panel = $unitPanel ?? null;
$context = \Source\Support\Erp\CondominiumContext::current();

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $panel !== null ? 'Unidade ' . $panel['unit']['label'] : $currentPage['name'],
    'pageLead' => $panel !== null ? $panel['unit']['condominium_name'] . ' · situação em ' . $day($panel['as_of']) . '.' : 'Pessoas, débitos, acordos, documentos e rotina de uma unidade em uma tela.',
    'pageActions' => '',
]);
?>
<form class="erp-card erp-unit-pick" method="get" action="<?= $this->e($route) ?>" role="search" aria-label="Escolher unidade" data-erp-loading>
    <label for="unit-panel-pick">Unidade<?= $context !== null ? ' do ' . $this->e((string)$context['name']) : '' ?></label>
    <select class="erp-select" id="unit-panel-pick" name="unidade" data-erp-autosubmit<?= $unitPanelUnits === [] ? ' disabled' : '' ?>>
        <option value="">Selecione</option>
        <?php foreach ($unitPanelUnits as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === $unitPanelSelected ? ' selected' : '' ?>><?= $this->e($u['label']) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="erp-btn"<?= $unitPanelUnits === [] ? ' disabled' : '' ?>>Ver painel</button>
</form>

<?php if (($unitPanelError ?? null) !== null): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><?= $this->e($unitPanelError) ?></div></div>
<?php endif; ?>

<?php if ($panel === null): ?>
    <?php if ($unitPanelUnits === []): ?>
        <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('building') ?></span><strong>Nenhuma unidade cadastrada</strong><p><?= $context === null ? 'Cadastre as unidades em Condomínios › Unidades.' : 'Este condomínio ainda não tem unidades ativas.' ?> <a href="<?= erp_url('/condominios/unidades') ?>">Ir para Unidades</a></p></div></section>
    <?php elseif (($unitPanelError ?? null) === null): ?>
        <section class="erp-card"><div class="erp-empty"><span class="erp-empty__icon"><?= $icon('search') ?></span><strong>Escolha uma unidade</strong><p><?= count($unitPanelUnits) ?> unidade(s)<?= $context === null ? ' em todos os condomínios do seu escopo' : '' ?>.</p></div></section>
    <?php endif; ?>
<?php else:
    $fin = $panel['finance'];
    $ops = $panel['operations'];
    $upcomingTotal = array_sum(array_column($fin['upcoming'], 'amount_cents'));
    $kpis = [
        ['label' => 'Em atraso', 'value' => $brl((int)$fin['overdue_cents'] + (int)$fin['charges_cents']), 'meta' => count($fin['overdue']) > 0 ? count($fin['overdue']) . ' lançamento(s) · ' . $fin['max_days_late'] . ' dias no mais antigo' : 'Sem débitos vencidos',
            'icon' => 'alert', 'tone' => (int)$fin['overdue_cents'] > 0 ? 'danger' : 'hero', 'trend' => null],
        ['label' => 'A vencer', 'value' => $brl((int)$upcomingTotal), 'meta' => count($fin['upcoming']) . ' lançamento(s) em aberto', 'icon' => 'calendar', 'tone' => '', 'trend' => null],
        ['label' => 'Pessoas', 'value' => (string)count($panel['people']), 'meta' => $ops['vehicles'] . ' veículo(s) · ' . $ops['pets'] . ' animal(is)', 'icon' => 'users', 'tone' => '', 'trend' => null],
        ['label' => 'Rotina', 'value' => (string)((int)$ops['deliveries_waiting'] + (int)$ops['occurrences_open']), 'meta' => $ops['deliveries_waiting'] . ' entrega(s) · ' . $ops['occurrences_open'] . ' ocorrência(s) · ' . $ops['reservations_upcoming'] . ' reserva(s)', 'icon' => 'clipboard', 'tone' => '', 'trend' => null],
    ];
?>
    <section class="erp-grid erp-grid--kpi" aria-label="Indicadores da unidade" data-erp-unit-panel>
        <?php foreach ($kpis as $i => $kpi) { $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 60]); } ?>
    </section>

    <div class="erp-grid erp-grid--2">
        <section class="erp-card" aria-labelledby="unit-debts-title">
            <div class="erp-card__head"><div><h2 id="unit-debts-title">Débitos em atraso</h2><p><?= (int)$fin['charges_cents'] > 0 ? 'Encargos (multa, juros e correção) de ' . $this->e($brl((int)$fin['charges_cents'])) : 'Valores originais' ?></p></div><?php if ($fin['overdue'] !== []): ?><a class="erp-btn erp-btn--sm" href="<?= erp_url('/cobranca/acordos') ?>">Fazer acordo</a><?php endif; ?></div>
            <?php if ($fin['overdue'] === []): ?>
                <p class="erp-field__hint">Nenhum débito vencido.</p>
            <?php else: ?>
                <ul class="erp-list">
                    <?php foreach ($fin['overdue'] as $e): ?>
                        <li><div class="erp-list__body"><strong><?= $this->e((string)$e['description']) ?></strong><small>Venceu em <?= $this->e($day($e['due_on'])) ?> · <?= (int)$e['days_late'] ?> dia(s)</small></div><span class="erp-list__meta erp-nowrap"><?= $this->e($brl((int)$e['amount_cents'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($fin['agreements'] !== []): ?>
                <h3 class="erp-panel-subtitle">Acordos</h3>
                <ul class="erp-list">
                    <?php foreach ($fin['agreements'] as $a): [$aLabel, $aTone] = $agreementStatus[$a['status']] ?? [$a['status'], 'plain']; ?>
                        <li><div class="erp-list__body"><strong>Acordo nº <?= (int)$a['id'] ?></strong><small><?= (int)$a['installments'] ?> parcela(s) desde <?= $this->e($day($a['first_due_on'])) ?></small></div><span class="erp-badge erp-badge--<?= $aTone ?>"><?= $this->e($aLabel) ?></span><span class="erp-list__meta erp-nowrap"><?= $this->e($brl((int)$a['total_cents'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="erp-card" aria-labelledby="unit-upcoming-title">
            <div class="erp-card__head"><div><h2 id="unit-upcoming-title">A vencer</h2><p>Próximos lançamentos da unidade</p></div></div>
            <?php if ($fin['upcoming'] === []): ?>
                <p class="erp-field__hint">Nenhum lançamento a vencer.</p>
            <?php else: ?>
                <ul class="erp-list">
                    <?php foreach ($fin['upcoming'] as $e): ?>
                        <li><div class="erp-list__body"><strong><?= $this->e($e['description']) ?></strong><small>Vence em <?= $this->e($day($e['due_on'])) ?></small></div><span class="erp-list__meta erp-nowrap"><?= $this->e($brl((int)$e['amount_cents'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="erp-grid erp-grid--2">
        <section class="erp-card" aria-labelledby="unit-people-title">
            <div class="erp-card__head"><div><h2 id="unit-people-title">Pessoas</h2><p>Proprietários, inquilinos e moradores ativos</p></div><a class="erp-btn erp-btn--sm" href="<?= erp_url('/cadastros/proprietarios-e-inquilinos') ?>">Cadastro</a></div>
            <?php if ($panel['people'] === []): ?>
                <p class="erp-field__hint">Nenhuma pessoa vinculada.</p>
            <?php else: ?>
                <ul class="erp-list">
                    <?php foreach ($panel['people'] as $p): ?>
                        <li><span class="erp-list__icon"><?= $icon('user', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><button type="button" class="erp-person-name" data-erp-modal-open="person-card-<?= (int)$p['id'] ?>" aria-haspopup="dialog" title="Ver a ficha de <?= $this->e((string)$p['name']) ?>"><?= $this->e((string)$p['name']) ?></button></strong><small><?= $this->e(implode(' · ', array_filter([($p['phone'] ?? null) !== null ? \Source\Support\Erp\RegistryFormat::phone((string)$p['phone']) : '', (string)($p['email'] ?? '')]))) ?></small></div><span class="erp-badge erp-badge--plain"><?= $this->e($relationships[$p['relationship']] ?? (string)$p['relationship']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <p class="erp-field__hint">Clique no nome para ver a ficha completa da pessoa.</p>
                <?php foreach ($panel['people'] as $p) { $this->insert('components/modals/person-card', ['person' => $p, 'unit' => $panel['unit'], 'relationships' => $relationships]); } ?>
            <?php endif; ?>
        </section>

        <section class="erp-card" aria-labelledby="unit-docs-title">
            <div class="erp-card__head"><div><h2 id="unit-docs-title">Documentos emitidos</h2><p>Certidões, declarações e cartas da unidade</p></div></div>
            <?php if ($panel['documents'] === []): ?>
                <p class="erp-field__hint">Nenhum documento emitido para esta unidade.</p>
            <?php else: ?>
                <ul class="erp-list">
                    <?php foreach ($panel['documents'] as $d): ?>
                        <li><span class="erp-list__icon"><?= $icon('file', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><?= $this->e((string)$d['kind_label']) ?></strong><small>Código <?= $this->e((string)$d['verification_display']) ?> · <?= $this->e($day($d['issued_at'] ?? null)) ?></small></div><?php if ($d['status'] === 'cancelled'): ?><span class="erp-badge erp-badge--danger">Cancelado</span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
