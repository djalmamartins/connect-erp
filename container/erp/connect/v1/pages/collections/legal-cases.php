<?php
/**
 * Cobrança judicial (AP-039; ADR 0080 e 0076) com dados reais, só acompanhamento: nada é enviado a
 * advogado ou tribunal, nenhum encargo ou lançamento é criado e nenhuma conta é travada. Lista dos casos
 * (?situacao= filtra; sem filtro, os não encerrados), detalhe em ?caso=ID com as contas, o andamento e
 * o registro de um novo (POST /api/v1/legal-cases/{id}/events), e abertura em ?abrir=UNIDADE com as
 * contas vencidas da unidade (POST /api/v1/legal-cases). erp.js: data-erp-collections.
 * @var array $currentPage @var ?int $collContext @var ?string $collError @var bool $collCanManage @var ?string $caseStatus
 * @var array $cases @var ?array $caseDetail @var ?array $caseOpenUnit @var list<array> $caseOpenUnits @var list<array> $caseLawyers
 */
use Source\Controllers\Erp\Connect\CollectionScreens;
use Source\Services\Erp\Collections;

$this->layout('default');
$route = erp_url($currentPage['route']);
$deskRoute = $catalog['pages']['AP-117']['route'];
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$today = date('Y-m-d');
$rows = $cases['data'];
$lawyerOptions = static function (array $lawyers, ?int $selected): string {
    $html = '<option value="">Sem advogado</option>';
    foreach ($lawyers as $l) {
        $html .= '<option value="' . (int)$l['id'] . '"' . ($selected === (int)$l['id'] ? ' selected' : '') . '>'
            . htmlspecialchars($l['name'] . ' · OAB ' . $l['oab'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</option>';
    }
    return $html;
};
$onlyTracking = 'Só acompanhamento: nada é enviado a advogado ou tribunal, nenhum encargo ou lançamento é criado e nenhuma conta é travada. A negociação segue pelos acordos.';

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $caseDetail !== null ? 'Caso judicial nº ' . (int)$caseDetail['id'] : $currentPage['name'],
    'pageLead' => $caseDetail !== null ? 'Unidade ' . $caseDetail['unit_label'] . ' · ' . $caseDetail['condominium_name'] : 'Acompanhamento das unidades em cobrança judicial: as contas cobradas, o advogado, o processo e o andamento.',
    'pageActions' => $caseDetail !== null ? '<a class="erp-btn" href="' . $this->e($route) . '">Voltar</a>' : '',
]);
?>
<div class="erp-stack" data-erp-collections data-route="<?= $this->e($route) ?>">
    <?= csrf_input() ?>
    <?php if ($collError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($collError) ?></strong><a href="<?= $this->e($route) ?>">Voltar à lista</a></div></div>
    <?php endif; ?>
    <div class="erp-alert" role="note"><?= $icon('info') ?><div><?= $this->e($onlyTracking) ?></div></div>

    <?php if ($caseDetail !== null): $k = $caseDetail; [$label, $tone] = CollectionScreens::caseBadge($k['status']); ?>
        <div class="erp-grid erp-grid--main">
            <div class="erp-stack">
                <section class="erp-card" aria-labelledby="case-title">
                    <div class="erp-card__head"><div><h2 id="case-title">Resumo</h2><p>Aberto em <?= $this->e($date($k['opened_on'])) ?></p></div><span class="erp-badge erp-badge--<?= $tone ?>"><?= $this->e($label) ?></span></div>
                    <dl class="erp-import-facts">
                        <div><dt>Valor na abertura</dt><dd><?= $this->e($brl((int)$k['claimed_cents'])) ?></dd></div>
                        <div><dt>Ainda em aberto</dt><dd><?= $this->e($brl((int)$k['still_open_cents'])) ?></dd></div>
                        <div><dt>Advogado</dt><dd><?= $this->e((string)($k['lawyer_name'] ?? '—')) ?></dd></div>
                        <div><dt>Processo</dt><dd><?= $this->e((string)($k['case_number'] ?? '—')) ?></dd></div>
                        <div><dt>Vara</dt><dd><?= $this->e((string)($k['court'] ?? '—')) ?></dd></div>
                        <?php if ($k['status'] === 'closed'): ?><div><dt>Encerrado em</dt><dd><?= $this->e($date($k['closed_on'])) ?></dd></div><?php endif; ?>
                    </dl>
                    <?php if ($k['status'] === 'closed' && $k['close_reason']): ?><p><strong>Motivo do encerramento:</strong> <?= $this->e((string)$k['close_reason']) ?></p><?php endif; ?>
                    <p><a href="<?= $this->e(erp_url($deskRoute . '?unidade=' . (int)$k['unit_id'])) ?>#atender">Ver os contatos de cobrança da unidade</a></p>
                </section>
                <section class="erp-card" aria-labelledby="case-entries-title">
                    <div class="erp-card__head"><div><h2 id="case-entries-title">Contas do caso</h2><p>Valores sem encargos, como estavam na abertura. As pagas depois saem do valor em aberto.</p></div></div>
                    <div class="erp-table-wrap" role="region" aria-labelledby="case-entries-title" tabindex="0"><table class="erp-table">
                        <thead><tr><th scope="col">Conta</th><th scope="col">Vencimento</th><th scope="col" class="is-num">Na abertura</th><th scope="col">Situação</th></tr></thead>
                        <tbody><?php foreach ($k['entries'] as $e): ?><tr>
                            <td><?= $this->e($e['description']) ?> <span class="erp-muted">nº <?= (int)$e['entry_id'] ?></span></td>
                            <td class="erp-nowrap"><?= $this->e($date($e['due_on'])) ?></td>
                            <td class="is-num erp-nowrap"><?= $this->e($brl((int)$e['claimed_cents'])) ?></td>
                            <td><?php if ($e['paid_on'] !== null): ?><span class="erp-badge erp-badge--success">Paga em <?= $this->e($date($e['paid_on'])) ?></span><?php elseif ($e['status'] !== 'active'): ?><span class="erp-badge erp-badge--plain">Fora da cobrança</span><?php else: ?><span class="erp-badge erp-badge--danger">Em aberto</span><?php endif; ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                </section>
            </div>
            <aside class="erp-card" aria-labelledby="case-events-title">
                <div class="erp-card__head"><div><h2 id="case-events-title">Andamento</h2><p><?= count($k['events']) ?> registro(s).</p></div></div>
                <ul class="erp-list erp-coll-history">
                    <?php foreach (array_reverse($k['events']) as $ev): ?>
                        <li><span class="erp-list__icon"><?= $icon($ev['kind'] === 'note' ? 'file' : 'scale', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong><?= $this->e(CollectionScreens::eventTitle($ev)) ?></strong><span class="erp-coll-note"><?= nl2br($this->e($ev['note'])) ?></span></div><span class="erp-list__meta"><?= $this->e($date($ev['happened_on'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($collCanManage && $k['status'] !== 'closed'): ?>
                    <form class="erp-form" data-case-event-form data-api="<?= $this->e(url('/api/v1/legal-cases/' . (int)$k['id'] . '/events')) ?>" aria-labelledby="case-event-title" novalidate>
                        <h3 class="erp-panel-subtitle" id="case-event-title">Registrar andamento</h3>
                        <div class="erp-form__grid">
                            <div class="erp-field erp-col-6"><label for="event-on">Data</label><input class="erp-input" type="date" id="event-on" name="happened_on" value="<?= $today ?>" min="<?= $this->e($k['opened_on']) ?>" max="<?= $today ?>" required></div>
                            <div class="erp-field erp-col-6"><label for="event-status">Situação</label><select class="erp-select" id="event-status" name="to_status" data-case-status><option value="">Mantém: <?= $this->e($label) ?></option><?php foreach ($k['next_statuses'] as $s): ?><option value="<?= $this->e($s) ?>"><?= $this->e(Collections::CASE_STATUSES[$s] ?? $s) ?></option><?php endforeach; ?></select></div>
                            <div class="erp-field erp-col-12"><label for="event-lawyer">Advogado</label><select class="erp-select" id="event-lawyer" name="lawyer_id"><?= $lawyerOptions($caseLawyers, $k['lawyer_id']) ?></select></div>
                            <div class="erp-field erp-col-6"><label for="event-number">Número do processo</label><input class="erp-input" id="event-number" name="case_number" maxlength="40" pattern="^[0-9.\-\/ ]{5,40}$" value="<?= $this->e((string)($k['case_number'] ?? '')) ?>"></div>
                            <div class="erp-field erp-col-6"><label for="event-court">Vara</label><input class="erp-input" id="event-court" name="court" maxlength="160" value="<?= $this->e((string)($k['court'] ?? '')) ?>"></div>
                            <div class="erp-field erp-col-12"><label for="event-note" data-case-note-label>Nota</label><textarea class="erp-textarea" id="event-note" name="note" minlength="5" maxlength="1000" required placeholder="Ex.: petição inicial protocolada"></textarea></div>
                        </div>
                        <p class="erp-field__hint" data-case-close-hint hidden>Encerrar é definitivo: o caso não volta. A nota vira o motivo do encerramento.</p>
                        <div data-form-error aria-live="assertive"></div>
                        <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary">Registrar andamento</button></div>
                    </form>
                <?php endif; ?>
            </aside>
        </div>
    <?php else: ?>
        <?php if ($collCanManage): ?>
            <?php if ($collContext === null): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Escolha um condomínio na barra superior para abrir um caso.</strong>A lista abaixo mostra todos os condomínios do seu escopo.</div></div>
            <?php elseif ($caseOpenUnit !== null): $u = $caseOpenUnit; ?>
                <form class="erp-card erp-form" id="abrir-caso" tabindex="-1" data-case-open-form data-api="<?= $this->e(url('/api/v1/legal-cases')) ?>" data-unit="<?= (int)$u['unit_id'] ?>" aria-labelledby="case-open-title" novalidate>
                    <div class="erp-card__head"><div><h2 id="case-open-title">Abrir caso da unidade <?= $this->e((string)$u['unit_label']) ?></h2><p>Escolha as contas vencidas em aberto que o caso cobra. O valor fica guardado como está hoje, sem encargos.</p></div></div>
                    <fieldset class="erp-charge-units erp-receipt-entries">
                        <legend>Contas <span class="erp-muted" data-case-total></span></legend>
                        <?php foreach ($u['entries'] as $e): ?><label class="erp-check"><input type="checkbox" name="entry_ids" value="<?= (int)$e['id'] ?>" data-amount="<?= (int)$e['amount_cents'] ?>" checked> <?= $this->e($e['description']) ?> · vence <?= $this->e($date($e['due_on'])) ?> · <?= $this->e($brl((int)$e['amount_cents'])) ?> · <?= (int)$e['days_late'] ?> dia(s)</label><?php endforeach; ?>
                    </fieldset>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-4"><label for="case-opened">Data de abertura</label><input class="erp-input" type="date" id="case-opened" name="opened_on" value="<?= $today ?>" max="<?= $today ?>" required></div>
                        <div class="erp-field erp-col-8"><label for="case-lawyer">Advogado (opcional)</label><select class="erp-select" id="case-lawyer" name="lawyer_id"><?= $lawyerOptions($caseLawyers, null) ?></select><?php if ($caseLawyers === []): ?><small class="erp-muted">Nenhum advogado ativo em Cadastros › Advogados.</small><?php endif; ?></div>
                        <div class="erp-field erp-col-6"><label for="case-number">Número do processo (opcional)</label><input class="erp-input" id="case-number" name="case_number" maxlength="40" pattern="^[0-9.\-\/ ]{5,40}$"></div>
                        <div class="erp-field erp-col-6"><label for="case-court">Vara (opcional)</label><input class="erp-input" id="case-court" name="court" maxlength="160"></div>
                        <div class="erp-field erp-col-12"><label for="case-note">Nota de abertura</label><textarea class="erp-textarea" id="case-note" name="note" minlength="5" maxlength="1000" required placeholder="Ex.: sem resposta a três contatos; encaminhado para análise"></textarea></div>
                    </div>
                    <div data-form-error aria-live="assertive"></div>
                    <div class="erp-form__actions"><a class="erp-btn" href="<?= $this->e($route) ?>">Voltar</a><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('scale', 'erp-icon--sm') ?>Abrir caso</button></div>
                </form>
            <?php elseif ($caseOpenUnits !== []): ?>
                <form class="erp-card erp-form" method="get" action="<?= $this->e($route) ?>" aria-labelledby="case-pick-title">
                    <div class="erp-card__head"><div><h2 id="case-pick-title">Abrir caso</h2><p>Unidades com conta vencida em aberto e sem caso aberto.</p></div></div>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-8"><label for="case-pick">Unidade</label><select class="erp-select" id="case-pick" name="abrir" required><option value="">Selecione</option><?php foreach ($caseOpenUnits as $u): ?><option value="<?= (int)$u['unit_id'] ?>"><?= $this->e($u['unit_label'] . ' · ' . $brl((int)$u['amount_cents']) . ' · ' . (int)$u['max_days_late'] . ' dia(s)') ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary">Escolher contas</button></div>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <section class="erp-card" aria-labelledby="cases-title">
            <div class="erp-card__head"><div><h2 id="cases-title">Casos</h2><p><?= (int)$cases['total'] ?> caso(s)<?= $caseStatus === null ? ' não encerrado(s)' : '' ?><?= $collContext === null ? ', em todos os condomínios' : '' ?>.</p></div></div>
            <nav class="erp-comm-filter erp-coll-filter" aria-label="Filtrar casos">
                <a class="erp-btn erp-btn--sm<?= $caseStatus === null ? ' erp-btn--primary' : '' ?>" href="<?= $this->e($route) ?>"<?= $caseStatus === null ? ' aria-current="page"' : '' ?>>Não encerrados</a>
                <?php foreach (Collections::CASE_STATUSES as $s => $l): ?><a class="erp-btn erp-btn--sm<?= $caseStatus === $s ? ' erp-btn--primary' : '' ?>" href="<?= $this->e($route . '?situacao=' . $s) ?>"<?= $caseStatus === $s ? ' aria-current="page"' : '' ?>><?= $this->e($l) ?></a><?php endforeach; ?>
            </nav>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('scale') ?></span><strong>Nenhum caso<?= $caseStatus !== null ? ' nesta situação' : '' ?></strong><p>Os casos abertos para acompanhamento aparecem aqui.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="cases-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Unidade</th><th scope="col">Situação</th><th scope="col">Aberto em</th><th scope="col">Advogado</th><th scope="col">Processo</th><th scope="col" class="is-num">Na abertura</th><th scope="col" class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($rows as $r): [$label, $tone] = CollectionScreens::caseBadge($r['status']); ?><tr>
                        <td><?= $this->e((string)$r['unit_label']) ?><?= $collContext === null ? '<br><span class="erp-muted">' . $this->e((string)$r['condominium_name']) . '</span>' : '' ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $this->e($label) ?></span></td>
                        <td class="erp-nowrap"><?= $this->e($date($r['opened_on'])) ?></td>
                        <td><?= $this->e((string)($r['lawyer_name'] ?? '—')) ?></td>
                        <td><?= $this->e((string)($r['case_number'] ?? '—')) ?><?= $r['court'] ? '<br><span class="erp-muted">' . $this->e((string)$r['court']) . '</span>' : '' ?></td>
                        <td class="is-num erp-nowrap"><?= $this->e($brl((int)$r['claimed_cents'])) ?></td>
                        <td class="is-num"><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?caso=' . (int)$r['id']) ?>" aria-label="Ver o caso nº <?= (int)$r['id'] ?> da unidade <?= $this->e((string)$r['unit_label']) ?>">Ver</a></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
