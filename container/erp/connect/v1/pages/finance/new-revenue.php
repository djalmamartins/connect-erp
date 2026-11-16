<?php
/**
 * Nova Receita (AP-025, revisão de 15/11 item 16, ADR 0313), com as opções do modelo 8 no layout do Connect ERP:
 * conta, centro de custo, documento, vencimento, valor, rateio (sem rateio, todas as unidades ou escolhidas), cálculo
 * (fixo, dividido, fração ou o valor de cada unidade), fundo de reserva, competência ("incluir na fatura de"),
 * cobrar de e repetição. Grava por POST /api/v1/revenues (erp.js, data-revenue-form), que confere tudo de novo.
 * @var array $currentPage @var list<array> $revenueAccounts @var list<array> $revenueCostCenters
 * @var list<array{id:int, label:string, exempt:bool}> $revenueUnits @var bool $revenueCanApportion @var bool $shortcutCanManage @var ?int $shortcutContext
 */
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\RevenueLaunch;

$this->layout('default');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => 'Nova receita',
    'pageLead' => 'Receita do condomínio ou cobrada das unidades, com rateio, fundo de reserva e repetição. A taxa condominial do mês continua saindo pelas Faturas.',
    'pageActions' => '<a class="erp-btn" href="' . $this->e(erp_url('/financeiro/contas-a-receber')) . '">Ir para a listagem</a>',
]);
$canCreate = $shortcutCanManage && $shortcutContext !== null && $revenueAccounts !== [];
$exempt = count(array_filter($revenueUnits, static fn (array $u): bool => $u['exempt']));
$apportion = $revenueCanApportion && $revenueUnits !== [] ? RevenueLaunch::APPORTION : ['none' => RevenueLaunch::APPORTION['none']];
?>
<div class="erp-stack" data-erp-shortcuts data-kind="revenue" data-route="<?= $this->e(erp_url('/financeiro/contas-a-receber')) ?>">
    <?= csrf_input() ?>
    <?php if (!$shortcutCanManage): ?>
        <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Você tem acesso somente para consulta.</strong>Para lançar receitas, peça a permissão de alterar lançamentos.</div></div>
    <?php elseif ($shortcutContext === null): ?>
        <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Escolha um condomínio na barra superior para lançar.</strong>As contas, os centros de custo e as unidades são os dele.</div></div>
    <?php elseif (!$canCreate): ?>
        <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><strong>Falta uma conta de receita.</strong>Cadastre uma conta analítica de receita no plano de contas.</div></div>
    <?php else: ?>
        <form class="erp-card erp-form" data-revenue-form data-api="<?= $this->e(url('/api/v1/revenues')) ?>" data-condominium="<?= (int)$shortcutContext ?>" aria-labelledby="revenue-title" novalidate>
            <div class="erp-card__head"><div><h2 id="revenue-title">Dados da receita</h2><p>Campos com * são obrigatórios. A conta nasce em aberto, no vencimento escolhido.</p></div></div>
            <div class="erp-form__grid">
                <div class="erp-field erp-col-12"><label for="rv-description">Descrição do lançamento *</label><input class="erp-input" id="rv-description" name="description" minlength="3" maxlength="120" required placeholder="Ex.: Controle do portão, aluguel do salão"></div>
                <div class="erp-field erp-col-4"><label for="rv-account">Conta da receita *</label><select class="erp-select" id="rv-account" name="chart_account_id" required><option value="">Selecione</option><?php foreach ($revenueAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select></div>
                <div class="erp-field erp-col-4"><label for="rv-cost">Centro de custo</label><select class="erp-select" id="rv-cost" name="cost_center_id"><option value="">Nenhum</option><?php foreach ($revenueCostCenters as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $this->e($c['label']) ?></option><?php endforeach; ?></select></div>
                <div class="erp-field erp-col-4"><label for="rv-document">Documento</label><input class="erp-input" id="rv-document" name="document_number" maxlength="40" placeholder="Nota, recibo ou contrato"></div>
                <div class="erp-field erp-col-4"><label for="rv-due">Vencimento *</label><input class="erp-input" type="date" id="rv-due" name="due_on" value="<?= date('Y-m-d') ?>" required></div>
                <div class="erp-field erp-col-4" data-revenue-amount><label for="rv-amount" data-revenue-amount-label>Valor (R$) *</label><input data-erp-money class="erp-input" id="rv-amount" name="amount_cents" inputmode="decimal" placeholder="0,00" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$" required></div>
                <div class="erp-field erp-col-4"><label for="rv-competence">Incluir na fatura de *</label><input class="erp-input" type="month" id="rv-competence" name="competence" value="<?= date('Y-m') ?>" required></div>

                <div class="erp-field erp-col-6"><label for="rv-apportion">Rateio</label><select class="erp-select" id="rv-apportion" name="apportion" data-revenue-apportion aria-describedby="rv-apportion-hint"><?php foreach ($apportion as $key => $label): ?><option value="<?= $key ?>"><?= $this->e($label) ?></option><?php endforeach; ?></select>
                    <small class="erp-field__hint" id="rv-apportion-hint"><?= $exempt > 0 ? $exempt . ' unidade(s) isenta(s) de rateio não recebem em "todas as unidades".' : 'Unidades isentas de rateio não recebem em "todas as unidades".' ?><?= $revenueCanApportion ? '' : ' Ratear exige permissão de lançar cobranças.' ?></small></div>
                <div class="erp-field erp-col-6"><label for="rv-payer">Cobrar de</label><select class="erp-select" id="rv-payer" name="payer" data-revenue-split-only><?php foreach (RevenueLaunch::PAYERS as $key => $label): ?><option value="<?= $key ?>"><?= $this->e($label) ?></option><?php endforeach; ?></select></div>

                <fieldset class="erp-fieldset erp-col-12" data-revenue-split hidden disabled>
                    <legend>Rateio entre as unidades</legend>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-6"><label for="rv-method">Cálculo</label><select class="erp-select" id="rv-method" name="method" data-revenue-method><?php foreach (EntryShortcuts::METHODS as $key => $label): ?><option value="<?= $key ?>"><?= $this->e($label) ?></option><?php endforeach; ?></select></div>
                        <div class="erp-col-12">
                            <div class="erp-table-wrap" role="region" aria-label="Unidades da receita" tabindex="0"><table class="erp-table erp-revenue-units">
                                <thead><tr><th scope="col"><label class="erp-check"><input type="checkbox" data-revenue-all checked> Unidade</label></th><th scope="col" class="is-num" data-revenue-value-col hidden>Valor</th></tr></thead>
                                <tbody><?php foreach ($revenueUnits as $u): ?><tr data-revenue-unit data-exempt="<?= $u['exempt'] ? '1' : '0' ?>">
                                    <td><label class="erp-check"><input type="checkbox" name="unit_ids" value="<?= (int)$u['id'] ?>" checked> <?= $this->e($u['label']) ?><?= $u['exempt'] ? ' <span class="erp-badge erp-badge--plain">isenta</span>' : '' ?></label></td>
                                    <td class="is-num" data-revenue-value-col hidden><input data-erp-money class="erp-input" name="unit_amount_<?= (int)$u['id'] ?>" data-unit-amount="<?= (int)$u['id'] ?>" inputmode="decimal" placeholder="0,00" aria-label="Valor da unidade <?= $this->e($u['label']) ?>"></td>
                                </tr><?php endforeach; ?></tbody>
                            </table></div>
                            <p class="erp-muted" data-revenue-count></p>
                        </div>
                        <div class="erp-field erp-col-4"><label class="erp-check"><input type="checkbox" data-revenue-reserve> Incluir taxa de fundo de reserva</label></div>
                        <div class="erp-field erp-col-4" data-revenue-reserve-field hidden><label for="rv-reserve">Fundo de reserva (%)</label><input class="erp-input" id="rv-reserve" name="reserve_percent" inputmode="decimal" placeholder="Ex.: 10" disabled required></div>
                        <div class="erp-field erp-col-4" data-revenue-reserve-field hidden><label for="rv-reserve-account">Conta do fundo de reserva</label><select class="erp-select" id="rv-reserve-account" name="reserve_account_id" disabled required><option value="">Selecione</option><?php foreach ($revenueAccounts as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $this->e($a['label']) ?></option><?php endforeach; ?></select></div>
                    </div>
                </fieldset>

                <div class="erp-field erp-col-4"><label class="erp-check"><input type="checkbox" data-revenue-repeat> Repetir lançamento</label></div>
                <div class="erp-field erp-col-4" data-revenue-repeat-field hidden><label for="rv-repeat">Quantas vezes?</label><select class="erp-select" id="rv-repeat" name="repeat" disabled><?php for ($n = 2; $n <= RevenueLaunch::MAX_REPEAT; $n++): ?><option value="<?= $n ?>"><?= $n ?> meses</option><?php endfor; ?></select></div>
                <div class="erp-field erp-col-4" data-revenue-repeat-field hidden><label class="erp-check"><input type="checkbox" name="repeat_label" value="1" checked disabled> Mostrar "(1/N)" na descrição</label></div>
                <p class="erp-field__hint erp-col-12">Anexos: depois de salvar, anexe a nota ou o contrato na ficha de cada lançamento (Lançamentos › abrir).</p>
            </div>
            <div data-form-error aria-live="assertive"></div>
            <div class="erp-form__actions">
                <a class="erp-btn" href="<?= $this->e(erp_url('/financeiro/contas-a-receber')) ?>">Ir para a listagem</a>
                <button type="submit" class="erp-btn erp-btn--primary"><?= $icon('check', 'erp-icon--sm') ?>Salvar</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
