<?php
/**
 * Vistorias: o que está devido nos condomínios do usuário e as vistorias recentes.
 * @var list<array> $board InspectionService::board()
 * @var list<array> $recent InspectionService::recent()
 * @var array{condominium_id: int, status: string} $filters
 * @var list<array> $condominiums
 * @var bool $canPerform @var bool $canManage @var string $base
 */
use Source\Services\Inspections\InspectionService;
use Source\Services\Inspections\Schedule;

$this->layout('default');
$this->insert('pages/inspections/partials/assets', ['flash' => $flash]);
$dueLabels = ['overdue' => ['Atrasada', 'danger'], 'due_soon' => ['Vence em breve', 'warning'], 'in_progress' => ['Em andamento', 'info'], 'ok' => ['Em dia', 'success']];
$statusLabels = ['in_progress' => ['Em andamento', 'info'], 'completed' => ['Concluída', 'success'], 'cancelled' => ['Cancelada', 'plain']];
ob_start(); ?>
    <?php if ($canManage): ?><a class="erp-btn" href="<?= erp_url($base . '/configurar') ?>"><?= $icon('settings', 'erp-icon--sm') ?>Configurar</a><?php endif; ?>
    <?php if ($canPerform): ?><a class="erp-btn erp-btn--primary" href="<?= erp_url($base . '/nova') ?>"><?= $icon('plus', 'erp-icon--sm') ?>Nova vistoria</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Operacional',
    'pageTitle' => 'Vistorias',
    'pageLead' => 'Visitas presenciais aos condomínios com check-in por GPS, checklist, fotos e relatório.',
    'pageActions' => $actions,
]);
?>
<section class="erp-card" aria-labelledby="insp-due-title">
    <div class="erp-card__head">
        <div><h2 id="insp-due-title">Programação</h2><p>Planos ativos dos seus condomínios, das mais urgentes para as em dia.</p></div>
    </div>
    <?php if ($board === []): ?>
        <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'calendar', 'emptyTitle' => 'Nenhum plano de vistoria',
            'emptyText' => $canManage ? 'Em Configurar, escolha os modelos de checklist que cada condomínio segue e a periodicidade.' : 'Peça a quem configura as vistorias para cadastrar os planos dos seus condomínios.']); ?>
    <?php else: ?>
        <div class="insp-board">
            <?php foreach ($board as $due):
                [$label, $tone] = $dueLabels[$due['status']]; ?>
                <article class="insp-due insp-due--<?= $this->e($due['status']) ?>">
                    <span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span>
                    <h3><?= $this->e($due['condominium']) ?></h3>
                    <div><?= $this->e($due['template']) ?> · <?= $this->e(Schedule::label($due['frequency'])) ?></div>
                    <small>Próxima: <?= date('d/m/Y', strtotime($due['next_due_on'])) ?><?= $due['last_completed_at'] ? ' · Última: ' . date('d/m/Y', strtotime($due['last_completed_at'])) : ' · Nenhuma feita ainda' ?></small>
                    <?php if ($due['open_inspection_id']): ?>
                        <a class="erp-btn erp-btn--sm" href="<?= erp_url($base . '/' . $due['open_inspection_id']) ?>">Continuar vistoria</a>
                    <?php elseif ($canPerform): ?>
                        <a class="erp-btn erp-btn--sm erp-btn--primary" href="<?= erp_url($base . '/nova?condominio=' . $due['condominium_id'] . '&modelo=' . $due['template_id']) ?>">Iniciar</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="erp-card erp-card--flush" aria-labelledby="insp-recent-title">
    <div class="erp-card__head">
        <div><h2 id="insp-recent-title">Vistorias recentes</h2><p>As 50 mais recentes com os filtros escolhidos.</p></div>
        <form class="erp-filters" method="get" action="<?= erp_url($base) ?>">
            <label class="erp-sr-only" for="insp-f-condo">Condomínio</label>
            <select class="erp-select" id="insp-f-condo" name="condominio">
                <option value="">Todos os condomínios</option>
                <?php foreach ($condominiums as $condominium): ?>
                    <option value="<?= (int)$condominium['id'] ?>"<?= $filters['condominium_id'] === (int)$condominium['id'] ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="erp-sr-only" for="insp-f-status">Situação</label>
            <select class="erp-select" id="insp-f-status" name="situacao">
                <option value="">Todas as situações</option>
                <?php foreach ($statusLabels as $value => [$label]): ?>
                    <option value="<?= $value ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <button class="erp-btn" type="submit"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button>
        </form>
    </div>
    <?php if ($recent === []): ?>
        <div style="padding:0 var(--erp-space-5) var(--erp-space-5)">
            <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'clipboard', 'emptyTitle' => 'Nenhuma vistoria encontrada', 'emptyText' => 'As vistorias aparecem aqui assim que alguém fizer o check-in.']); ?>
        </div>
    <?php else: ?>
        <div class="erp-table-wrap">
            <table class="erp-table">
                <caption class="erp-sr-only">Vistorias recentes (<?= count($recent) ?>)</caption>
                <thead><tr><th scope="col">Data</th><th scope="col">Condomínio</th><th scope="col">Checklist</th><th scope="col">Responsável</th><th scope="col">Problemas</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $row):
                    [$label, $tone] = $statusLabels[$row['status']]; ?>
                    <tr>
                        <td><a href="<?= erp_url($base . '/' . $row['id']) ?>"><?= date('d/m/Y H:i', strtotime($row['checkin_at'])) ?></a></td>
                        <td><?= $this->e($row['condominium']) ?><?php if ($row['checkin_status'] !== 'inside'): ?> <span class="erp-badge erp-badge--warning" title="<?= $this->e(InspectionService::CHECKIN[$row['checkin_status']]) ?>">GPS</span><?php endif; ?></td>
                        <td><?= $this->e($row['template']) ?></td>
                        <td><?= $this->e($row['inspector'] ?: '—') ?></td>
                        <td><?= $row['issues'] > 0 ? '<span class="erp-badge erp-badge--danger">' . (int)$row['issues'] . '</span>' : '0' ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span><?= $row['status'] === 'in_progress' ? ' <small class="insp-muted">' . ($row['items'] - $row['pending']) . '/' . $row['items'] . '</small>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
