<?php
/**
 * Dashboard da administradora (AP-001 reinterpretada, ADR-002).
 * Nesta fase os números vêm de database/fixtures/erp-demo/dashboard.php e são marcados
 * como demonstração. Sem o modo demonstração, a página mostra o estado vazio honesto.
 * @var array $dashboard
 */
$this->layout('default');
$hasData = $dashboard !== [];
$firstName = trim((string)($user->first_name ?? ''));
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
ob_start(); ?>
    <label class="erp-sr-only" for="dash-period">Período</label>
    <select id="dash-period" class="erp-select" style="width:auto" <?= $hasData ? '' : 'disabled' ?>>
        <option>Novembro de 2026</option><option>Outubro de 2026</option><option>Setembro de 2026</option>
    </select>
    <button type="button" class="erp-btn" data-erp-pending="Exportação pendente: o relatório gerencial será gerado quando o módulo financeiro estiver integrado."><?= $icon('download', 'erp-icon--sm') ?>Exportar</button>
    <button type="button" class="erp-btn erp-btn--primary" data-erp-modal-open="dash-quick-notice"><?= $icon('plus', 'erp-icon--sm') ?>Comunicado rápido</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $hasData ? 'Visão geral · ' . $dashboard['period'] : 'Visão geral',
    'pageTitle' => $greeting . ($firstName !== '' ? ', ' . $firstName : '') . '!',
    'pageLead' => 'Acompanhe a situação dos condomínios administrados pela Connect.',
    'pageActions' => $actions,
]);
?>

<?php if ($demo): ?>
    <div class="erp-alert erp-alert--warning" role="note"><?= $icon('info') ?><div><strong>Dados de demonstração</strong>Os valores desta tela são fictícios e servem para avaliar o layout. Nenhum dado é gravado. Para desligar, defina <code>ERP_DEMO_DATA=false</code>.</div></div>
<?php endif; ?>

<?php if (!$hasData): ?>
    <section class="erp-card">
        <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'chart', 'emptyTitle' => 'Indicadores ainda indisponíveis', 'emptyText' => 'O dashboard mostrará arrecadação, inadimplência e contas a pagar assim que os módulos financeiros forem integrados ao banco.']); ?>
    </section>
<?php else: ?>
    <section class="erp-grid erp-grid--kpi" aria-label="Indicadores principais">
        <?php foreach ($dashboard['kpis'] as $i => $kpi) { $this->insert('components/cards/kpi', ['kpi' => $kpi, 'delay' => $i * 60]); } ?>
    </section>

    <div class="erp-grid erp-grid--main">
        <section class="erp-card" aria-labelledby="dash-cash-title">
            <div class="erp-card__head">
                <div><h2 id="dash-cash-title">Receitas e despesas</h2><p>Consolidado dos condomínios, últimos 6 meses</p></div>
                <span class="erp-demo-flag">Demonstração</span>
            </div>
            <?php $this->insert('components/charts/bars', ['chart' => $dashboard['cashflow'], 'chartCaption' => 'Receitas e despesas por mês']); ?>
        </section>
        <section class="erp-card" aria-labelledby="dash-delinquency-title">
            <div class="erp-card__head">
                <div><h2 id="dash-delinquency-title">Inadimplência por atraso</h2><p>Participação no valor em aberto</p></div>
            </div>
            <?php $this->insert('components/charts/donut', ['slices' => $dashboard['delinquencyByAge'], 'donutLabel' => 'Inadimplência por faixa de atraso']); ?>
            <p style="margin-top:16px"><a href="<?= url('/erp/relatorios/inadimplentes-geral') ?>">Ver relatório de inadimplentes →</a></p>
        </section>
    </div>

    <div class="erp-grid erp-grid--main">
        <section class="erp-card erp-card--flush" aria-labelledby="dash-condos-title">
            <div class="erp-card__head">
                <div><h2 id="dash-condos-title">Condomínios</h2><p>Arrecadação e inadimplência do mês</p></div>
                <div class="erp-search" style="display:block;width:220px">
                    <?= $icon('search', 'erp-icon--sm') ?>
                    <label class="erp-sr-only" for="dash-condo-filter">Filtrar condomínios</label>
                    <input id="dash-condo-filter" type="search" placeholder="Filtrar" data-erp-table-filter>
                </div>
            </div>
            <div class="erp-table-wrap" data-erp-table data-per-page="5" role="region" aria-labelledby="dash-condos-title" tabindex="0">
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
                        <?php foreach ($dashboard['condominiums'] as $condo):
                            $tone = ['Em dia' => 'success', 'Atenção' => 'warning', 'Crítico' => 'danger'][$condo['status']] ?? ''; ?>
                            <tr>
                                <td><strong><?= $this->e($condo['name']) ?></strong><small><?= $this->e($condo['city']) ?></small></td>
                                <td class="is-num" data-sort="<?= (int)$condo['units'] ?>"><?= (int)$condo['units'] ?></td>
                                <td class="is-num" data-sort="<?= $condo['received'] ?>"><?= $this->e(number_format($condo['received'], 1, ',', '.')) ?>%<div class="erp-progress" aria-hidden="true"><span style="width: <?= $condo['received'] ?>%"></span></div></td>
                                <td class="is-num" data-sort="<?= $condo['delinquency'] ?>"><?= $this->e(number_format($condo['delinquency'], 1, ',', '.')) ?>%</td>
                                <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $this->e($condo['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="erp-pagination" data-erp-pager>
                <span data-erp-pager-info></span>
                <div class="erp-pagination__pages" data-erp-pager-pages></div>
            </div>
        </section>

        <section class="erp-card" aria-labelledby="dash-alerts-title">
            <div class="erp-card__head"><div><h2 id="dash-alerts-title">Alertas</h2><p>O que precisa de atenção</p></div></div>
            <ul class="erp-list">
                <?php foreach ($dashboard['alerts'] as $alert): ?>
                    <li>
                        <span class="erp-list__icon<?= $alert['tone'] === 'info' ? '' : ' erp-list__icon--' . $this->e($alert['tone']) ?>"><?= $icon($alert['tone'] === 'info' ? 'calendar' : 'alert', 'erp-icon--sm') ?></span>
                        <div class="erp-list__body"><a href="<?= url($alert['href']) ?>"><strong><?= $this->e($alert['title']) ?></strong></a><small><?= $this->e($alert['text']) ?></small></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>

    <div class="erp-grid erp-grid--2">
        <section class="erp-card" aria-labelledby="dash-activity-title">
            <div class="erp-card__head"><div><h2 id="dash-activity-title">Atividades recentes</h2><p>Últimas ações da equipe</p></div></div>
            <ul class="erp-list">
                <?php foreach ($dashboard['activities'] as $activity): ?>
                    <li>
                        <span class="erp-list__icon"><?= $icon($activity['icon'], 'erp-icon--sm') ?></span>
                        <div class="erp-list__body"><strong><?= $this->e($activity['title']) ?></strong><small><?= $this->e($activity['text']) ?></small></div>
                        <span class="erp-list__meta"><?= $this->e($activity['when']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <section class="erp-card" aria-labelledby="dash-agenda-title">
            <div class="erp-card__head"><div><h2 id="dash-agenda-title">Agenda</h2><p>Próximos compromissos</p></div><a class="erp-btn erp-btn--sm" href="<?= url('/erp/operacional') ?>">Ver tudo</a></div>
            <ul class="erp-list">
                <?php foreach ($dashboard['agenda'] as $event): ?>
                    <li>
                        <span class="erp-list__icon" style="flex-direction:column;width:44px;height:44px;line-height:1"><strong style="font-size:15px"><?= $this->e($event['day']) ?></strong><small style="font-size:10px;text-transform:uppercase"><?= $this->e($event['month']) ?></small></span>
                        <div class="erp-list__body"><strong><?= $this->e($event['title']) ?></strong><small><?= $this->e($event['text']) ?></small></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
<?php endif; ?>

<dialog class="erp-modal" id="dash-quick-notice" aria-labelledby="dash-quick-notice-title">
    <form method="dialog" data-erp-validate data-erp-pending="Envio pendente: o módulo de comunicados ainda não grava nem envia mensagens.">
        <div class="erp-modal__head">
            <h2 id="dash-quick-notice-title">Comunicado rápido</h2>
            <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
        </div>
        <div class="erp-modal__body erp-form">
            <div class="erp-alert"><?= $icon('info') ?><div>Pré-visualização do formulário. O envio ficará disponível com o módulo Comunicação.</div></div>
            <div class="erp-field">
                <label for="notice-condo">Condomínio</label>
                <select id="notice-condo" class="erp-select" required>
                    <option value="">Selecione</option>
                    <?php foreach (($dashboard['condominiums'] ?? []) as $condo): ?><option><?= $this->e($condo['name']) ?></option><?php endforeach; ?>
                </select>
                <span class="erp-field__error" aria-live="polite"></span>
            </div>
            <div class="erp-field">
                <label for="notice-title">Título</label>
                <input id="notice-title" class="erp-input" required maxlength="120" placeholder="Ex.: Manutenção da caixa d'água">
                <span class="erp-field__error" aria-live="polite"></span>
            </div>
            <div class="erp-field">
                <label for="notice-body">Mensagem</label>
                <textarea id="notice-body" class="erp-textarea" required minlength="10" maxlength="2000"></textarea>
                <span class="erp-field__hint">Mínimo de 10 caracteres.</span>
                <span class="erp-field__error" aria-live="polite"></span>
            </div>
        </div>
        <div class="erp-modal__foot">
            <button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button>
            <button type="submit" class="erp-btn erp-btn--primary">Enviar comunicado</button>
        </div>
    </form>
</dialog>
