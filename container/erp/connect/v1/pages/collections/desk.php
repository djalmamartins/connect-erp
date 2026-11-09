<?php
/**
 * Atendimento de cobrança (AP-117; ADR 0080 e 0076) com dados reais: a fila das unidades em atraso
 * (promessa quebrada, próxima ação vencida, sem contato e maior atraso primeiro) e, em ?unidade=ID, o
 * histórico de contatos com o registro de um novo (POST /api/v1/collection-contacts). O histórico não
 * se edita nem se apaga. erp.js: data-erp-collections.
 * @var array $currentPage @var ?int $collContext @var ?string $collError @var bool $collCanManage
 * @var array $desk @var ?array $deskUnit @var ?list<array> $deskContacts
 */
use Source\Services\Erp\Collections;

$this->layout('default');
$route = erp_url($currentPage['route']);
$caseRoute = $catalog['pages']['AP-039']['route'];
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$stamp = static fn (?string $value): string => $value ? date('d/m/Y H:i', (int)strtotime($value)) : '—';
$rows = $desk['data'];
$totals = $desk['totals'];
$today = date('Y-m-d');
$maxPromise = date('Y-m-d', strtotime('+' . Collections::MAX_PROMISE_DAYS . ' days'));
$maxNext = date('Y-m-d', strtotime('+' . Collections::MAX_NEXT_ACTION_DAYS . ' days'));

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Fila das unidades em atraso para contato. Primeiro as promessas quebradas, depois as próximas ações vencidas, as sem contato e o maior atraso.',
]);
?>
<div class="erp-stack" data-erp-collections data-route="<?= $this->e($route) ?>">
    <?= csrf_input() ?>
    <?php if ($collError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($collError) ?></strong><a href="<?= $this->e($route) ?>">Voltar à fila</a></div></div>
    <?php endif; ?>
    <section class="erp-card" aria-labelledby="desk-totals-title">
        <div class="erp-card__head"><div><h2 id="desk-totals-title">Hoje</h2><p>Situação em <?= $this->e($date($desk['as_of'])) ?><?= $collContext === null ? ', em todos os condomínios' : '' ?>. Valores sem multa e juros.</p></div></div>
        <dl class="erp-import-facts">
            <div><dt>Unidades em atraso</dt><dd><?= (int)($totals['units'] ?? count($rows)) ?></dd></div>
            <div><dt>Em aberto</dt><dd><?= $this->e($brl((int)($totals['amount_cents'] ?? 0))) ?></dd></div>
            <div><dt>Promessas quebradas</dt><dd<?= ($totals['promise_broken'] ?? 0) > 0 ? ' class="erp-panel-negative"' : '' ?>><?= (int)($totals['promise_broken'] ?? 0) ?></dd></div>
            <div><dt>Próxima ação vencida</dt><dd><?= (int)($totals['action_due'] ?? 0) ?></dd></div>
            <div><dt>Sem contato</dt><dd><?= (int)($totals['without_contact'] ?? 0) ?></dd></div>
        </dl>
    </section>

    <div class="erp-grid<?= $deskUnit !== null ? ' erp-grid--main' : '' ?>">
        <section class="erp-card" aria-labelledby="desk-title">
            <div class="erp-card__head"><div><h2 id="desk-title">Fila</h2><p><?= count($rows) ?> unidade(s).</p></div></div>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('check') ?></span><strong>Nenhuma unidade em atraso</strong><p>As unidades com conta a receber vencida e em aberto aparecem aqui.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="desk-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Unidade</th><th scope="col" class="is-num">Em aberto</th><th scope="col">Último contato</th><th scope="col">Próxima ação</th><th scope="col" class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($rows as $r): $uid = $r['unit_id']; $selected = $deskUnit !== null && $deskUnit['unit_id'] === $uid; $last = $r['last_contact']; ?><tr<?= $selected ? ' class="is-selected"' : '' ?>>
                        <td><strong><?= $this->e((string)$r['unit_label']) ?></strong><?= $collContext === null ? '<br><span class="erp-muted">' . $this->e((string)$r['condominium_name']) . '</span>' : '' ?>
                            <?php if ($r['promise_broken']): ?><br><span class="erp-badge erp-badge--danger">Promessa quebrada</span><?php endif; ?>
                            <?php if ($r['legal_case'] !== null): ?><br><a class="erp-badge erp-badge--warning" href="<?= $this->e(erp_url($caseRoute . '?caso=' . (int)$r['legal_case']['id'])) ?>">Caso judicial: <?= $this->e(Collections::CASE_STATUSES[$r['legal_case']['status']] ?? $r['legal_case']['status']) ?></a><?php endif; ?></td>
                        <td class="is-num erp-nowrap"><?= $this->e($brl((int)$r['amount_cents'])) ?><br><span class="erp-muted"><?= (int)$r['entries'] ?> conta(s) · <?= (int)$r['max_days_late'] ?> dia(s)</span></td>
                        <td><?php if ($last === null): ?><span class="erp-muted">Sem contato</span><?php else: ?><?= $this->e($date($last['at'])) ?> · <?= $this->e(Collections::CHANNELS[$last['channel']] ?? $last['channel']) ?><br><span class="erp-muted"><?= $this->e(Collections::OUTCOMES[$last['outcome']] ?? $last['outcome']) ?><?= $last['promise_on'] ? ' para ' . $this->e($date($last['promise_on'])) : '' ?></span><?php endif; ?></td>
                        <td class="erp-nowrap"><?php if ($last !== null && $last['next_action_on']): ?><span<?= $r['action_due'] ? ' class="erp-panel-negative"' : '' ?>><?= $this->e($date($last['next_action_on'])) ?><?= $r['action_due'] ? ' (vencida)' : '' ?></span><?php else: ?><span class="erp-muted">—</span><?php endif; ?></td>
                        <td class="is-num"><?php if ($uid !== null): ?><a class="erp-btn erp-btn--sm<?= $selected ? '' : ' erp-btn--primary' ?>" href="<?= $this->e($route . '?unidade=' . (int)$uid) ?>#atender" aria-label="Atender a unidade <?= $this->e((string)$r['unit_label']) ?>"<?= $selected ? ' aria-current="true"' : '' ?>>Atender</a><?php else: ?><span class="erp-muted">Sem unidade</span><?php endif; ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>

        <?php if ($deskUnit !== null): $u = $deskUnit; $uid = (int)$u['unit_id']; ?>
            <aside class="erp-card" id="atender" tabindex="-1" aria-labelledby="desk-unit-title">
                <div class="erp-card__head"><div><h2 id="desk-unit-title"><?= $u['unit_label'] !== null ? 'Unidade ' . $this->e((string)$u['unit_label']) : 'Unidade' ?></h2><p><?= $u['amount_cents'] > 0 ? $this->e($brl((int)$u['amount_cents'])) . ' em aberto · ' . (int)$u['max_days_late'] . ' dia(s) de atraso' : 'Sem conta vencida em aberto hoje.' ?></p></div></div>
                <?php if ($u['legal_case'] !== null): ?>
                    <p><a href="<?= $this->e(erp_url($caseRoute . '?caso=' . (int)$u['legal_case']['id'])) ?>">Ver o caso judicial nº <?= (int)$u['legal_case']['id'] ?></a></p>
                <?php elseif ($collCanManage && $u['amount_cents'] > 0): ?>
                    <p><a href="<?= $this->e(erp_url($caseRoute . '?abrir=' . $uid)) ?>#abrir-caso">Abrir caso judicial para acompanhamento</a></p>
                <?php endif; ?>
                <?php if ($collCanManage): ?>
                    <form class="erp-form" data-contact-form data-api="<?= $this->e(url('/api/v1/collection-contacts')) ?>" data-unit="<?= $uid ?>" aria-labelledby="contact-title" novalidate>
                        <h3 class="erp-panel-subtitle" id="contact-title">Registrar contato</h3>
                        <div class="erp-form__grid">
                            <div class="erp-field erp-col-6"><label for="contact-channel">Canal</label><select class="erp-select" id="contact-channel" name="channel" required><option value="">Selecione</option><?php foreach (Collections::CHANNELS as $k => $l): ?><option value="<?= $this->e($k) ?>"><?= $this->e($l) ?></option><?php endforeach; ?></select></div>
                            <div class="erp-field erp-col-6"><label for="contact-outcome">Resultado</label><select class="erp-select" id="contact-outcome" name="outcome" required data-contact-outcome><option value="">Selecione</option><?php foreach (Collections::OUTCOMES as $k => $l): ?><option value="<?= $this->e($k) ?>"><?= $this->e($l) ?></option><?php endforeach; ?></select></div>
                            <div class="erp-field erp-col-12"><label for="contact-name">Com quem falou (opcional)</label><input class="erp-input" id="contact-name" name="contact_name" maxlength="160"></div>
                            <div class="erp-field erp-col-6" data-contact-promise hidden><label for="contact-promise-on">Data prometida</label><input class="erp-input" type="date" id="contact-promise-on" name="promise_on" min="<?= $today ?>" max="<?= $maxPromise ?>"></div>
                            <div class="erp-field erp-col-6" data-contact-promise hidden><label for="contact-promise-cents">Valor prometido (opcional)</label><input class="erp-input" id="contact-promise-cents" name="promise_cents" inputmode="decimal" placeholder="0,00" pattern="^\d{1,3}(\.?\d{3})*(,\d{1,2})?$"></div>
                            <div class="erp-field erp-col-6"><label for="contact-next">Próxima ação (opcional)</label><input class="erp-input" type="date" id="contact-next" name="next_action_on" min="<?= $today ?>" max="<?= $maxNext ?>"></div>
                            <div class="erp-field erp-col-12"><label for="contact-note">Nota</label><textarea class="erp-textarea" id="contact-note" name="note" minlength="5" maxlength="1000" required placeholder="O que foi conversado"></textarea></div>
                        </div>
                        <p class="erp-field__hint">O contato fica no histórico com o valor em aberto de hoje. Não se edita nem se apaga.</p>
                        <div data-form-error aria-live="assertive"></div>
                        <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary">Registrar contato</button></div>
                    </form>
                <?php endif; ?>
                <h3 class="erp-panel-subtitle">Histórico</h3>
                <?php if ($deskContacts === []): ?>
                    <p class="erp-field__hint">Nenhum contato registrado com esta unidade.</p>
                <?php else: ?>
                    <ul class="erp-list erp-coll-history">
                        <?php foreach ($deskContacts as $c): ?>
                            <li><span class="erp-list__icon"><?= $icon('user', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><?= $this->e($c['outcome_label']) ?><?= $c['promise_on'] ? ' para ' . $this->e($date($c['promise_on'])) . ($c['promise_cents'] !== null ? ' (' . $this->e($brl((int)$c['promise_cents'])) . ')' : '') : '' ?></strong>
                                <small><?= $this->e(Collections::CHANNELS[$c['channel']] ?? $c['channel']) ?><?= $c['contact_name'] ? ' · ' . $this->e((string)$c['contact_name']) : '' ?> · em aberto <?= $this->e($brl((int)$c['open_cents'])) ?><?= $c['next_action_on'] ? ' · próxima ação ' . $this->e($date($c['next_action_on'])) : '' ?></small>
                                <span class="erp-coll-note"><?= nl2br($this->e($c['note'])) ?></span></div><span class="erp-list__meta"><?= $this->e($stamp($c['created_at'])) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a class="erp-btn erp-btn--block" style="margin-top:8px" href="<?= $this->e($route) ?>">Fechar</a>
            </aside>
        <?php endif; ?>
    </div>
</div>
