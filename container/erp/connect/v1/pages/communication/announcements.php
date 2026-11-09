<?php
/**
 * Comunicação com dados reais (ADR 0059, 0065 e 0075): AP-111 comunicado geral, AP-137 aviso de
 * cobrança e AP-138 envios agendados. Cada unidade vira um aviso na outbox, que a fila entrega; fora de
 * produção nada sai para morador. Enviar: POST /api/v1/announcements ou /announcements/collection;
 * cancelar: POST /api/v1/announcements/{id}/cancel. ?comunicado=ID abre o detalhe com a situação da
 * entrega. erp.js: data-erp-communication.
 * @var array $currentPage @var string $currentPageId @var ?string $commKind @var ?int $commContext @var ?string $commError @var bool $commCanManage
 * @var array $announcements @var ?array $announcementDetail @var list<array> $commUnits
 * @var string $collectionAsOf @var ?list<array> $collectionUnits @var array<int, true> $collectionReminded @var ?array<int, int> $collectionBilling
 */
use Source\Controllers\Erp\Connect\CommunicationScreens;
use Source\Services\Erp\Announcements;

$this->layout('default');
$page = (string)$currentPageId;
$route = erp_url($currentPage['route']);
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$date = static fn (?string $value): string => $value ? date('d/m/Y', (int)strtotime($value)) : '—';
$stamp = static fn (?string $value): string => $value ? date('d/m/Y H:i', (int)strtotime($value)) : '—';
$rows = $announcements['data'] ?? [];
$commUnits ??= [];
$general = $page === 'AP-111';
$collection = $page === 'AP-137';
$scheduledPage = $page === 'AP-138';
$kindLabel = static fn (string $kind): string => $kind === 'collection' ? 'Aviso de cobrança' : 'Comunicado';
$minSend = date('Y-m-d\TH:i');
$maxSend = date('Y-m-d\TH:i', strtotime('+' . Announcements::MAX_SCHEDULE_DAYS . ' days'));
$leads = [
    'AP-111' => 'Comunicado por e-mail para as unidades do condomínio. Recebem as pessoas ativas com e-mail válido que não recusaram comunicado no cadastro.',
    'AP-137' => 'Aviso de débitos em aberto para as unidades com conta vencida. Só recebe quem autorizou a cobrança por e-mail, e a unidade avisada nos últimos ' . Announcements::COLLECTION_COOLDOWN_DAYS . ' dias fica de fora.',
    'AP-138' => 'Comunicados e avisos de cobrança que ainda vão sair. Cancelar para o que ainda não saiu.',
];
$sendWhen = static function (string $prefix) use ($minSend, $maxSend): string {
    return '<fieldset class="erp-field erp-col-12 erp-comm-when"><legend>Quando enviar</legend>'
        . '<label class="erp-check"><input type="radio" name="when" value="now" checked> Agora</label>'
        . '<label class="erp-check"><input type="radio" name="when" value="later"> Agendar</label>'
        . '<div class="erp-comm-when__at" data-comm-send-at hidden><label for="' . $prefix . '-send-at">Data e hora do envio</label>'
        . '<input class="erp-input" type="datetime-local" id="' . $prefix . '-send-at" name="send_at" min="' . $minSend . '" max="' . $maxSend . '" aria-describedby="' . $prefix . '-send-at-hint">'
        . '<small class="erp-muted" id="' . $prefix . '-send-at-hint">Até ' . Announcements::MAX_SCHEDULE_DAYS . ' dias à frente. Até lá, dá para cancelar em Agendada.</small></div></fieldset>';
};

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $announcementDetail !== null ? $kindLabel($announcementDetail['kind']) . ' nº ' . (int)$announcementDetail['id'] : $currentPage['name'],
    'pageLead' => $announcementDetail !== null ? $announcementDetail['subject'] : ($leads[$page] ?? ''),
    'pageActions' => $announcementDetail !== null ? '<a class="erp-btn" href="' . $this->e($route) . '">Voltar</a>' : '',
]);
?>
<div class="erp-stack" data-erp-communication data-route="<?= $this->e($route) ?>" data-cancel-api="<?= $this->e(url('/api/v1/announcements/__ID__/cancel')) ?>">
    <?= csrf_input() ?>
    <?php if ($commError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($commError) ?></strong><a href="<?= $this->e($route) ?>">Voltar à lista</a></div></div>
    <?php endif; ?>

    <?php if ($announcementDetail !== null): $a = $announcementDetail; $d = $a['delivery']; [$label, $tone] = CommunicationScreens::statusOf($a); ?>
        <section class="erp-card" aria-labelledby="comm-detail-title">
            <div class="erp-card__head"><div><h2 id="comm-detail-title">Entrega</h2><p><?= $this->e($a['condominium_name'] ?? '') ?> · criado em <?= $this->e($stamp($a['created_at'])) ?></p></div><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span></div>
            <dl class="erp-import-facts">
                <div><dt><?= $a['scheduled'] ? 'Sai em' : 'Envio' ?></dt><dd><?= $this->e($stamp($a['send_at'])) ?></dd></div>
                <div><dt>Unidades</dt><dd><?= (int)$a['units_count'] ?><?= $a['audience'] === 'units' ? ' (escolhidas)' : '' ?></dd></div>
                <div><dt>Destinatários</dt><dd><?= (int)$d['recipients'] ?></dd></div>
                <div><dt>Enviados</dt><dd><?= (int)$d['sent'] ?></dd></div>
                <div><dt>Na fila</dt><dd><?= (int)$d['pending'] + (int)$d['processing'] ?></dd></div>
                <div><dt>Com falha</dt><dd><?= (int)$d['failed'] ?></dd></div>
                <div><dt>Cancelados</dt><dd><?= (int)$d['cancelled'] ?></dd></div>
            </dl>
            <?php if ($a['status'] === 'cancelled'): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Cancelado em <?= $this->e($stamp($a['cancelled_at'])) ?>.</strong><?= $this->e((string)$a['cancel_reason']) ?></div></div>
            <?php endif; ?>
            <?php if ($a['kind'] === 'general' && ($a['body'] ?? null) !== null): ?>
                <h3 class="erp-comm-subtitle">Texto</h3>
                <div class="erp-comm-body"><?= nl2br($this->e((string)$a['body'])) ?></div>
            <?php else: ?>
                <p class="erp-muted">Cada unidade recebe a quantidade de contas vencidas, o valor sem encargos e o vencimento mais antigo, como estavam na data do aviso.</p>
            <?php endif; ?>
            <p class="erp-field__hint">A entrega segue a fila de avisos. Fora de produção, os e-mails vão só para o log ou para a caixa de teste.</p>
            <?php if ($commCanManage && CommunicationScreens::cancellable($a)): ?>
                <div class="erp-form__actions"><button type="button" class="erp-btn erp-btn--danger" data-cancel-open data-api="<?= $this->e(url('/api/v1/announcements/' . (int)$a['id'] . '/cancel')) ?>" data-label="<?= $this->e($kindLabel($a['kind']) . ' nº ' . (int)$a['id'] . ': ' . $a['subject']) ?>">Cancelar o que não saiu</button></div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <?php if ($commCanManage && !$scheduledPage): ?>
            <?php if ($commContext === null): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Escolha um condomínio na barra superior para enviar.</strong>A lista abaixo mostra todos os condomínios do seu escopo.</div></div>
            <?php elseif ($general && $commUnits === []): ?>
                <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><strong>O condomínio não tem unidade ativa.</strong>Cadastre as unidades em Cadastros › Unidades.</div></div>
            <?php elseif ($general): ?>
                <form class="erp-card erp-form" data-announce-form data-api="<?= $this->e(url('/api/v1/announcements')) ?>" data-condominium="<?= (int)$commContext ?>" aria-labelledby="announce-title" novalidate>
                    <div class="erp-card__head"><div><h2 id="announce-title">Novo comunicado</h2><p>Cada unidade vira um aviso na fila. O texto sai como você escrever, sem formatação.</p></div></div>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-12"><label for="announce-subject">Assunto</label><input class="erp-input" id="announce-subject" name="subject" minlength="5" maxlength="120" required data-comm-count="announce-subject-count" aria-describedby="announce-subject-count"><small class="erp-muted" id="announce-subject-count">0 de 120</small></div>
                        <div class="erp-field erp-col-12"><label for="announce-body">Texto</label><textarea class="erp-textarea erp-comm-textarea" id="announce-body" name="body" minlength="10" maxlength="<?= Announcements::MAX_BODY ?>" required data-comm-count="announce-body-count" aria-describedby="announce-body-count"></textarea><small class="erp-muted" id="announce-body-count">0 de <?= number_format(Announcements::MAX_BODY, 0, ',', '.') ?></small></div>
                        <fieldset class="erp-field erp-col-12 erp-comm-when"><legend>Para quem</legend>
                            <label class="erp-check"><input type="radio" name="audience" value="all" checked> Todas as unidades ativas (<?= count($commUnits) ?>)</label>
                            <label class="erp-check"><input type="radio" name="audience" value="units"> Só as unidades escolhidas</label>
                        </fieldset>
                        <fieldset class="erp-field erp-col-12 erp-charge-units" data-comm-units hidden>
                            <legend>Unidades <span class="erp-muted" data-comm-units-count>(0 de <?= count($commUnits) ?>)</span></legend>
                            <div class="erp-charge-units__grid"><?php foreach ($commUnits as $u): ?><label class="erp-check"><input type="checkbox" name="unit_ids" value="<?= (int)$u['id'] ?>"> <?= $this->e($u['label']) ?></label><?php endforeach; ?></div>
                        </fieldset>
                        <?= $sendWhen('announce') ?>
                    </div>
                    <div data-form-error aria-live="assertive"></div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('megaphone', 'erp-icon--sm') ?>Enviar comunicado</button></div>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($collection && $commContext !== null && $collectionUnits !== null): $eligible = 0; ?>
            <section class="erp-card" aria-labelledby="collection-units-title">
                <div class="erp-card__head"><div><h2 id="collection-units-title">Unidades em atraso</h2><p><?= count($collectionUnits) ?> unidade(s) com conta vencida em <?= $this->e($date($collectionAsOf)) ?>. Valores sem multa e juros.</p></div>
                    <form method="get" action="<?= $this->e($route) ?>" class="erp-comm-asof"><label for="collection-asof">Data de referência</label><input class="erp-input" type="date" id="collection-asof" name="referencia" value="<?= $this->e($collectionAsOf) ?>" max="<?= date('Y-m-d') ?>"><button type="submit" class="erp-btn erp-btn--sm">Atualizar</button></form></div>
                <?php if ($collectionUnits === []): ?>
                    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('check') ?></span><strong>Nenhuma unidade em atraso</strong><p>Não há conta a receber vencida e em aberto nesta data.</p></div>
                <?php else: ?>
                    <form data-collection-form data-api="<?= $this->e(url('/api/v1/announcements/collection')) ?>" data-condominium="<?= (int)$commContext ?>" data-as-of="<?= $this->e($collectionAsOf) ?>" aria-labelledby="collection-units-title" novalidate>
                        <div class="erp-table-wrap" role="region" aria-labelledby="collection-units-title" tabindex="0"><table class="erp-table">
                            <thead><tr><th scope="col"><?php if ($commCanManage): ?><label class="erp-check"><input type="checkbox" data-collection-all checked><span class="erp-sr-only">Marcar todas as unidades</span></label><?php endif; ?></th><th scope="col">Unidade</th><th scope="col" class="is-num">Contas</th><th scope="col" class="is-num">Em aberto</th><th scope="col">Vencimento mais antigo</th><th scope="col" class="is-num">Atraso</th><th scope="col">Aviso</th></tr></thead>
                            <tbody><?php foreach ($collectionUnits as $u): $uid = (int)$u['unit_id']; $reminded = isset($collectionReminded[$uid]); $billing = $collectionBilling === null ? null : (int)($collectionBilling[$uid] ?? 0); $eligible += $reminded ? 0 : 1; ?><tr data-days="<?= (int)$u['max_days_late'] ?>"<?= $reminded ? ' class="erp-muted"' : '' ?>>
                                <td><?php if ($commCanManage): ?><label class="erp-check"><input type="checkbox" name="unit_ids" value="<?= $uid ?>"<?= $reminded ? ' disabled' : ' checked' ?>><span class="erp-sr-only">Avisar a unidade <?= $this->e($u['unit_label']) ?></span></label><?php endif; ?></td>
                                <td><?= $this->e($u['unit_label']) ?></td>
                                <td class="is-num"><?= count($u['entries']) ?></td>
                                <td class="is-num erp-nowrap"><?= $this->e($brl((int)$u['amount_cents'])) ?></td>
                                <td class="erp-nowrap"><?= $this->e($date($u['oldest_due_on'])) ?></td>
                                <td class="is-num erp-nowrap"><?= (int)$u['max_days_late'] ?> dia(s)</td>
                                <td><?php if ($reminded): ?><span class="erp-badge erp-badge--plain">Avisada nos últimos <?= Announcements::COLLECTION_COOLDOWN_DAYS ?> dias</span><?php elseif ($billing === 0): ?><span class="erp-badge erp-badge--warning">Ninguém recebe cobrança por e-mail</span><?php elseif ($billing !== null): ?><span class="erp-badge erp-badge--success"><?= $billing ?> pessoa(s)</span><?php else: ?><span class="erp-muted">—</span><?php endif; ?></td>
                            </tr><?php endforeach; ?></tbody>
                        </table></div>
                        <?php if ($commCanManage): ?>
                            <?php if ($eligible === 0): ?>
                                <p class="erp-field__hint">Todas as unidades em atraso já foram avisadas nos últimos <?= Announcements::COLLECTION_COOLDOWN_DAYS ?> dias.</p>
                            <?php else: ?>
                                <div class="erp-form__grid erp-comm-collection-grid">
                                    <div class="erp-field erp-col-4"><label for="collection-min-days">Atraso mínimo (dias)</label><input class="erp-input" type="number" id="collection-min-days" name="min_days_late" min="1" max="3650" value="1" required></div>
                                    <?= $sendWhen('collection') ?>
                                </div>
                                <?php if ($collectionBilling !== null && in_array(0, array_map('intval', $collectionBilling), true)): ?><p class="erp-field__hint">Unidade sem ninguém que receba cobrança por e-mail recebe o aviso na fila, mas ninguém é avisado. Confira em <a href="<?= $this->e(erp_url('/comunicacao/enderecos?sem=cobranca')) ?>">Endereços</a>.</p><?php endif; ?>
                                <div data-form-error aria-live="assertive"></div>
                                <div class="erp-form__actions"><span class="erp-muted" data-collection-count aria-live="polite"></span><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('megaphone', 'erp-icon--sm') ?>Enviar aviso de cobrança</button></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="erp-card" aria-labelledby="comm-list-title">
            <div class="erp-card__head"><div><h2 id="comm-list-title"><?= $scheduledPage ? 'Ainda vão sair' : ($collection ? 'Avisos enviados' : 'Comunicados') ?></h2><p><?= (int)($announcements['total'] ?? 0) ?> registro(s)<?= $commContext === null ? ', em todos os condomínios' : '' ?>.</p></div></div>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon($scheduledPage ? 'calendar' : 'megaphone') ?></span><strong><?= $scheduledPage ? 'Nenhum envio agendado' : 'Nada enviado ainda' ?></strong><p><?= $scheduledPage ? 'Agende um comunicado em Comunicados gerais ou um aviso em Cobrança.' : 'Os envios aparecem aqui com a situação da entrega.' ?></p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="comm-list-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Assunto</th><?php if ($commContext === null): ?><th scope="col">Condomínio</th><?php endif; ?><th scope="col"><?= $scheduledPage ? 'Sai em' : 'Envio' ?></th><th scope="col" class="is-num">Unidades</th><th scope="col">Entrega</th><th scope="col">Situação</th><th scope="col" class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($rows as $r): [$label, $tone] = CommunicationScreens::statusOf($r); $d = $r['delivery']; ?><tr>
                        <td><a href="<?= $this->e($route . '?comunicado=' . (int)$r['id']) ?>"><?= $this->e($r['subject']) ?></a><?php if ($scheduledPage): ?><br><span class="erp-muted"><?= $kindLabel($r['kind']) ?></span><?php endif; ?></td>
                        <?php if ($commContext === null): ?><td><?= $this->e((string)$r['condominium_name']) ?></td><?php endif; ?>
                        <td class="erp-nowrap"><?= $this->e($stamp($r['send_at'])) ?></td>
                        <td class="is-num"><?= (int)$r['units_count'] ?></td>
                        <td class="erp-nowrap"><?= (int)$d['sent'] ?> de <?= (int)$r['units_count'] ?> enviado(s)<?= (int)$d['failed'] > 0 ? ' · <span class="erp-panel-negative">' . (int)$d['failed'] . ' com falha</span>' : '' ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $label ?></span></td>
                        <td class="is-num"><?php if ($commCanManage && CommunicationScreens::cancellable($r)): ?><button type="button" class="erp-btn erp-btn--sm" data-cancel-open data-api="<?= $this->e(url('/api/v1/announcements/' . (int)$r['id'] . '/cancel')) ?>" data-label="<?= $this->e($kindLabel($r['kind']) . ' nº ' . (int)$r['id'] . ': ' . $r['subject']) ?>" aria-label="Cancelar <?= $this->e($kindLabel($r['kind']) . ' nº ' . (int)$r['id']) ?>">Cancelar</button><?php else: ?><a class="erp-btn erp-btn--sm erp-btn--ghost" href="<?= $this->e($route . '?comunicado=' . (int)$r['id']) ?>" aria-label="Ver <?= $this->e($kindLabel($r['kind']) . ' nº ' . (int)$r['id']) ?>">Ver</a><?php endif; ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php $this->insert('components/cards/shortcut-cancel', ['canManage' => $commCanManage, 'title' => 'Cancelar envio', 'hint' => 'Os avisos que ainda não saíram são cancelados. Os já enviados ficam como estão.']); ?>
</div>
