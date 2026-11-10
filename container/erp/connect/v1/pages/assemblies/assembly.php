<?php
/**
 * Telas de assembleia com dados reais (ADR 0145, sobre o ADR 0088): AP-018 Assembleia Virtual (lista, nova
 * assembleia com a pauta e o detalhe com convocar, abrir, presença, encerrar e cancelar), AP-017 Votação,
 * AP-088 Listagem de presenças e AP-092 Parecer. ?assembleia=ID escolhe a assembleia. Toda mudança vai pela
 * API /api/v1/assemblies (erp.js, data-erp-assemblies), com CSRF no cabeçalho; os relatórios exportam o CSV
 * pela API (format=csv).
 * @var array $currentPage @var string $currentPageId @var string $assemblyKind @var ?int $assemblyContext @var ?string $assemblyError
 * @var bool $canManageAssembly @var bool $canConvene @var array $assemblies @var ?array $assemblyDetail @var ?array $assemblyReport
 * @var list<array> $assemblyUnits @var list<array> $assemblyVoters @var list<array> $assemblyDocuments
 */
use Source\Controllers\Erp\Connect\AssemblyScreens as Screens;
use Source\Services\Erp\Assemblies;

$this->layout('default');
$kind = $assemblyKind;
$route = erp_url($currentPage['route']);
$manageRoute = erp_url($catalog['pages']['AP-018']['route'] ?? '/comunicacao/assembleia-virtual');
$rows = $assemblies['data'] ?? [];
$a = $assemblyDetail;
$api = static fn (string $path = ''): string => url('/api/v1/assemblies' . $path);
$badge = static fn (array $map, string $status): string => '<span class="erp-badge erp-badge--' . ($map[$status][1] ?? 'plain') . '">' . ($map[$status][0] ?? $status) . '</span>';
$brl = static fn (int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$leads = [
    'manage' => 'Assembleia presencial, virtual ou híbrida, com a pauta, o edital pela fila de avisos, a presença por unidade e a ata gerada no encerramento.',
    'voting' => 'Votação da assembleia aberta: um item por vez, um voto por unidade presente e quite, com o peso da fração ideal ou um voto por unidade.',
    'attendance' => 'Unidades presentes na assembleia, com procuração e direito a voto. Unidade com conta vencida na abertura fica presente, mas não vota.',
    'results' => 'Resultado de cada item da pauta, com o quórum, e a ata gerada no encerramento.',
];
$choices = ['attendance' => ['open', 'closed'], 'results' => ['open', 'closed'], 'voting' => ['open'], 'manage' => array_keys(Screens::STATUS)][$kind];
$options = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['status'], $choices, true)));
$csv = null;
if ($a !== null && $assemblyReport !== null && ($assemblyReport['rows'] ?? []) !== []) {
    $csv = $api('/' . (int)$a['id'] . ($kind === 'attendance' ? '/attendance-report' : '/results') . '?format=csv');
}

ob_start(); ?>
    <?php if ($kind === 'manage' && $a !== null): ?><a class="erp-btn" href="<?= $this->e($route) ?>">Voltar</a><?php endif; ?>
    <?php if (in_array($kind, ['attendance', 'results'], true)): ?><button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button><?php endif; ?>
    <?php if ($csv !== null): ?><a class="erp-btn erp-btn--primary" href="<?= $this->e($csv) ?>" download><?= $icon('download', 'erp-icon--sm') ?>Exportar CSV</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $kind === 'manage' && $a !== null ? (string)$a['title'] : $currentPage['name'],
    'pageLead' => $kind === 'manage' && $a !== null ? Screens::KINDS[$a['kind']] . ' · ' . Screens::MODES[$a['mode']] . ' · ' . (string)$a['condominium_name'] : $leads[$kind],
    'pageActions' => $actions,
]);
?>
<div class="erp-stack" data-erp-assemblies data-api="<?= $this->e($api()) ?>" data-condominium="<?= (int)($assemblyContext ?? 0) ?>">
    <?= csrf_input() ?>
    <?php if ($assemblyError !== null): ?>
        <div class="erp-alert erp-alert--warning" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($assemblyError) ?></strong><a href="<?= $this->e($route) ?>">Voltar</a></div></div>
    <?php endif; ?>

    <?php if ($kind !== 'manage'): ?>
        <?php if ($options === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('users') ?></span><strong><?= $kind === 'voting' ? 'Nenhuma assembleia aberta' : 'Nenhuma assembleia aberta ou encerrada' ?></strong><p>Crie, convoque e abra a assembleia em <a href="<?= $this->e($manageRoute) ?>">Assembleia Virtual</a>.</p></div>
        <?php else: ?>
            <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Escolher a assembleia">
                <div class="erp-field erp-field--compact erp-assembly-pick"><label for="as-pick">Assembleia</label><select class="erp-select" id="as-pick" name="assembleia">
                    <?php foreach ($options as $o): ?><option value="<?= (int)$o['id'] ?>"<?= $a !== null && (int)$a['id'] === (int)$o['id'] ? ' selected' : '' ?>><?= $this->e(Screens::stamp($o['first_call_at']) . ' · ' . $o['title'] . ($assemblyContext === null ? ' · ' . $o['condominium_name'] : '') . ' (' . Screens::STATUS[$o['status']][0] . ')') ?></option><?php endforeach; ?>
                </select></div>
                <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Abrir</button></div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($a !== null): ?>
        <section class="erp-card" aria-labelledby="as-facts-title">
            <div class="erp-card__head"><div><h2 id="as-facts-title"><?= $kind === 'manage' ? 'Dados da assembleia' : $this->e((string)$a['title']) ?></h2><p><?= $this->e((string)$a['condominium_name']) ?> · <?= $this->e((string)$a['location']) ?></p></div><?= $badge(Screens::STATUS, $a['status']) ?></div>
            <dl class="erp-import-facts">
                <div><dt>Primeira convocação</dt><dd><?= $this->e(Screens::stamp($a['first_call_at'])) ?></dd></div>
                <div><dt>Segunda convocação</dt><dd><?= $this->e(Screens::stamp($a['second_call_at'])) ?></dd></div>
                <div><dt>Unidades presentes</dt><dd><?= (int)$a['attendance']['units'] ?></dd></div>
                <div><dt>Por procuração</dt><dd><?= (int)$a['attendance']['proxies'] ?></dd></div>
                <div><dt>Sem direito a voto</dt><dd><?= (int)$a['attendance']['without_vote'] ?></dd></div>
                <div><dt>Itens da pauta</dt><dd><?= count($a['items']) ?></dd></div>
            </dl>
            <?php if ($a['status'] === 'cancelled'): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Assembleia cancelada.</strong><?= $this->e((string)$a['cancel_reason']) ?></div></div>
            <?php endif; ?>
            <?php if ($kind === 'manage'): $buttons = Screens::actions($a['status'], $canManageAssembly, $canConvene); ?>
                <?php if ($a['status'] === 'draft'): ?><p class="erp-field__hint">O edital sai pela fila de avisos com pelo menos <?= Assemblies::MIN_NOTICE_DAYS ?> dias até a primeira convocação. Fora de produção, os e-mails vão só para o log ou para a caixa de teste.<?= $canManageAssembly && !$canConvene ? ' Convocar também exige a permissão de enviar comunicados.' : '' ?></p><?php endif; ?>
                <?php if ($a['status'] === 'convened'): ?><p class="erp-field__hint">A assembleia abre a partir do horário da primeira convocação.</p><?php endif; ?>
                <?php if ($a['status'] === 'open'): ?><p class="erp-field__hint">Encerrar exige todos os itens apurados em <a href="<?= $this->e(erp_url($catalog['pages']['AP-017']['route'] ?? '/comunicacao/votacao') . '?assembleia=' . (int)$a['id']) ?>">Votação</a>. A ata é gerada no encerramento e não muda mais.</p><?php endif; ?>
                <?php if ($buttons !== []): ?>
                    <div class="erp-form__actions">
                        <?php foreach ($buttons as $action): if ($action === 'cancel') continue; ?>
                            <button type="button" class="erp-btn<?= $action === 'close' ? '' : ' erp-btn--primary' ?>" data-assembly-action data-api="<?= $this->e($api('/' . (int)$a['id'] . '/' . $action)) ?>"
                                data-confirm="<?= $this->e(['convene' => 'Convocar e publicar o edital na fila de avisos?', 'open' => 'Abrir a assembleia agora?', 'close' => 'Encerrar a assembleia e gerar a ata? Depois disso, nada muda.'][$action]) ?>"
                                data-done="<?= $this->e(['convene' => 'Edital publicado na fila de avisos.', 'open' => 'Assembleia aberta.', 'close' => 'Assembleia encerrada e ata gerada.'][$action]) ?>"><?= ['convene' => 'Convocar', 'open' => 'Abrir a assembleia', 'close' => 'Encerrar e gerar a ata'][$action] ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php if (in_array('cancel', $buttons, true)): ?>
                        <details class="erp-assembly-cancel">
                            <summary class="erp-btn erp-btn--danger">Cancelar a assembleia</summary>
                            <form class="erp-form" data-assembly-cancel data-api="<?= $this->e($api('/' . (int)$a['id'] . '/cancel')) ?>" novalidate>
                                <div class="erp-form__grid"><div class="erp-field erp-col-12"><label for="as-cancel-reason">Motivo</label><input class="erp-input" id="as-cancel-reason" name="reason" minlength="3" maxlength="255" required aria-describedby="as-cancel-hint"><small class="erp-field__hint" id="as-cancel-hint">O edital que ainda não saiu também é cancelado.</small></div></div>
                                <div data-form-error aria-live="assertive"></div>
                                <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--danger">Confirmar o cancelamento</button></div>
                            </form>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($kind === 'manage' || $kind === 'voting'): $voting = Screens::votingItem($a['items']); ?>
            <section class="erp-card" aria-labelledby="as-items-title">
                <div class="erp-card__head"><div><h2 id="as-items-title">Pauta</h2><p>Cada item tem a base do voto e o quórum. Um item em votação por vez.</p></div></div>
                <div class="erp-table-wrap" role="region" aria-labelledby="as-items-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col" class="is-num">Item</th><th scope="col">Assunto</th><th scope="col">Base do voto</th><th scope="col">Quórum</th><th scope="col">Situação</th><th scope="col">Resultado</th><?php if ($kind === 'voting' && $canManageAssembly && $a['status'] === 'open'): ?><th scope="col" class="is-num">Ações</th><?php endif; ?></tr></thead>
                    <tbody><?php foreach ($a['items'] as $item): ?><tr>
                        <td class="is-num"><?= (int)$item['position'] ?></td>
                        <td><?= $this->e($item['title']) ?><?php if (($item['description'] ?? '') !== ''): ?><br><span class="erp-muted"><?= $this->e((string)$item['description']) ?></span><?php endif; ?></td>
                        <td><?= Screens::BASES[$item['vote_basis']] ?></td>
                        <td><?= $this->e(Assemblies::QUORUM_LABELS[$item['quorum']]) ?></td>
                        <td><?= $badge(Screens::ITEM_STATUS, $item['status']) ?><?php if ($item['status'] === 'decided'): ?> <span class="erp-badge erp-badge--<?= !empty($item['result']['approved']) ? 'success' : 'danger' ?>"><?= !empty($item['result']['approved']) ? 'Aprovado' : 'Rejeitado' ?></span><?php endif; ?></td>
                        <td class="erp-nowrap"><?= $this->e(Screens::resultText($item)) ?></td>
                        <?php if ($kind === 'voting' && $canManageAssembly && $a['status'] === 'open'): ?><td class="is-num">
                            <?php if ($item['status'] === 'pending' && $voting === null): ?><button type="button" class="erp-btn erp-btn--sm" data-assembly-action data-api="<?= $this->e($api('/' . (int)$a['id'] . '/items/' . (int)$item['id'] . '/start')) ?>" data-done="Item em votação." aria-label="Abrir a votação do item <?= (int)$item['position'] ?>">Abrir votação</button>
                            <?php elseif ($item['status'] === 'voting'): ?><button type="button" class="erp-btn erp-btn--sm erp-btn--primary" data-assembly-action data-api="<?= $this->e($api('/' . (int)$a['id'] . '/items/' . (int)$item['id'] . '/close')) ?>" data-confirm="Apurar o item <?= (int)$item['position'] ?>? Depois disso, ninguém mais vota nele." data-done="Item apurado." aria-label="Apurar o item <?= (int)$item['position'] ?>">Apurar</button>
                            <?php else: ?><span class="erp-muted">—</span><?php endif; ?>
                        </td><?php endif; ?>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            </section>
        <?php endif; ?>

        <?php if ($kind === 'voting' && $canManageAssembly && $a['status'] === 'open'): $item = null; foreach ($a['items'] as $i) { if ($i['status'] === 'voting') { $item = $i; } } ?>
            <?php if ($item === null): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Nenhum item em votação.</strong>Abra a votação de um item da pauta para registrar os votos.</div></div>
            <?php elseif ($assemblyVoters === []): ?>
                <div class="erp-alert erp-alert--warning" role="note"><?= $icon('alert') ?><div><strong>Nenhuma unidade presente com direito a voto.</strong>Registre a presença em <a href="<?= $this->e($manageRoute . '?assembleia=' . (int)$a['id']) ?>">Assembleia Virtual</a>.</div></div>
            <?php else: $pending = array_values(array_filter($assemblyVoters, static fn (array $v): bool => !$v['voted'])); ?>
                <form class="erp-card erp-form" data-assembly-vote data-api="<?= $this->e($api('/' . (int)$a['id'] . '/items/' . (int)$item['id'] . '/votes')) ?>" aria-labelledby="as-vote-title" novalidate>
                    <div class="erp-card__head"><div><h2 id="as-vote-title">Voto do item <?= (int)$item['position'] ?></h2><p><?= $this->e($item['title']) ?> · <?= count($assemblyVoters) - count($pending) ?> de <?= count($assemblyVoters) ?> unidade(s) já votaram.</p></div></div>
                    <?php if ($pending === []): ?>
                        <p class="erp-field__hint">Todas as unidades presentes com direito a voto já votaram. Apure o item na pauta acima.</p>
                    <?php else: ?>
                        <div class="erp-form__grid">
                            <div class="erp-field erp-col-6"><label for="as-vote-unit">Unidade</label><select class="erp-select" id="as-vote-unit" name="unit_id" required><option value="">Escolha</option><?php foreach ($pending as $v): ?><option value="<?= (int)$v['id'] ?>"><?= $this->e($v['label']) ?></option><?php endforeach; ?></select></div>
                            <fieldset class="erp-field erp-col-6 erp-comm-when"><legend>Voto</legend><?php foreach (Screens::CHOICES as $value => $label): ?><label class="erp-check"><input type="radio" name="choice" value="<?= $value ?>" required> <?= $label ?></label><?php endforeach; ?></fieldset>
                        </div>
                        <div data-form-error aria-live="assertive"></div>
                        <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary">Registrar voto</button></div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($kind === 'manage' && $canManageAssembly && $a['status'] === 'open'): ?>
            <form class="erp-card erp-form" data-assembly-checkin data-api="<?= $this->e($api('/' . (int)$a['id'] . '/attendance')) ?>" aria-labelledby="as-checkin-title" novalidate>
                <div class="erp-card__head"><div><h2 id="as-checkin-title">Registrar presença</h2><p>Uma presença por unidade. Unidade com conta vencida em aberto fica presente, mas não vota (Código Civil, art. 1.335, III).</p></div></div>
                <?php if ($assemblyUnits === []): ?>
                    <p class="erp-field__hint">Todas as unidades ativas já têm presença registrada.</p>
                <?php else: ?>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-4"><label for="as-unit">Unidade</label><select class="erp-select" id="as-unit" name="unit_id" required><option value="">Escolha</option><?php foreach ($assemblyUnits as $u): ?><option value="<?= (int)$u['id'] ?>"><?= $this->e($u['label']) ?></option><?php endforeach; ?></select></div>
                        <div class="erp-field erp-col-8"><label for="as-attendee">Quem está presente</label><input class="erp-input" id="as-attendee" name="attendee_name" minlength="3" maxlength="160" required></div>
                        <div class="erp-field erp-col-4"><label class="erp-check"><input type="checkbox" name="is_proxy" value="1" data-assembly-proxy> Por procuração</label></div>
                        <div class="erp-field erp-col-8" data-assembly-proxy-doc hidden><label for="as-proxy-doc">Procuração no Disco Virtual</label><select class="erp-select" id="as-proxy-doc" name="proxy_document_id" aria-describedby="as-proxy-hint"><option value="">Escolha o arquivo</option><?php foreach ($assemblyDocuments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= $this->e($d['label']) ?></option><?php endforeach; ?></select><small class="erp-field__hint" id="as-proxy-hint">Envie a procuração em Documentos › Disco Virtual antes de registrar.</small></div>
                    </div>
                    <div data-form-error aria-live="assertive"></div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary">Registrar presença</button></div>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($kind === 'attendance' && $assemblyReport !== null): $r = $assemblyReport; $debt = $r['rows'] !== [] && $r['rows'][0]['overdue_cents'] !== null; ?>
            <section class="erp-card" aria-labelledby="as-att-title">
                <div class="erp-card__head"><div><h2 id="as-att-title">Presenças</h2><p><?= (int)$r['totals']['units'] ?> unidade(s) · <?= (int)$r['totals']['proxies'] ?> por procuração · <?= (int)$r['totals']['without_vote'] ?> sem direito a voto · emitido em <?= date('d/m/Y') ?></p></div></div>
                <?php if ($r['rows'] === []): ?>
                    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('users') ?></span><strong>Nenhuma presença registrada</strong><p>A presença é registrada em Assembleia Virtual, com a assembleia aberta.</p></div>
                <?php else: ?>
                    <div class="erp-table-wrap" role="region" aria-labelledby="as-att-title" tabindex="0"><table class="erp-table">
                        <thead><tr><th scope="col">Unidade</th><th scope="col">Presente</th><th scope="col">Procuração</th><th scope="col">Vota</th><?php if ($debt): ?><th scope="col" class="is-num">Vencido</th><?php endif; ?><th scope="col">Entrada</th></tr></thead>
                        <tbody><?php foreach ($r['rows'] as $row): ?><tr>
                            <td><?= $this->e($row['unit_label']) ?></td>
                            <td><?= $this->e($row['attendee_name']) ?></td>
                            <td><?= $row['is_proxy'] ? 'Sim' : 'Não' ?></td>
                            <td><?= $row['eligible'] ? '<span class="erp-badge erp-badge--success">Vota</span>' : '<span class="erp-badge erp-badge--warning">Não vota</span>' ?></td>
                            <?php if ($debt): ?><td class="is-num erp-nowrap"><?= $this->e($brl((int)$row['overdue_cents'])) ?></td><?php endif; ?>
                            <td class="erp-nowrap"><?= $this->e(Screens::stamp($row['checked_in_at'])) ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($kind === 'results' && $assemblyReport !== null): ?>
            <section class="erp-card" aria-labelledby="as-res-title">
                <div class="erp-card__head"><div><h2 id="as-res-title">Resultados</h2><p><?= count($assemblyReport['rows']) ?> item(ns) · emitido em <?= date('d/m/Y') ?></p></div></div>
                <div class="erp-table-wrap" role="region" aria-labelledby="as-res-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col" class="is-num">Item</th><th scope="col">Assunto</th><th scope="col">Base</th><th scope="col">Quórum</th><th scope="col" class="is-num">Sim</th><th scope="col" class="is-num">Não</th><th scope="col" class="is-num">Abstenção</th><th scope="col" class="is-num">Total</th><th scope="col" class="is-num">Votos</th><th scope="col">Resultado</th></tr></thead>
                    <tbody><?php foreach ($assemblyReport['rows'] as $row): ?><tr>
                        <td class="is-num"><?= (int)$row['position'] ?></td>
                        <td><?= $this->e($row['title']) ?></td>
                        <td><?= Screens::BASES[$row['vote_basis']] ?></td>
                        <td><?= $this->e(Assemblies::QUORUM_LABELS[$row['quorum']]) ?></td>
                        <?php foreach (['yes', 'no', 'abstain', 'total'] as $k): ?><td class="is-num erp-nowrap"><?= $this->e($row[$k] ?? '—') ?></td><?php endforeach; ?>
                        <td class="is-num"><?= $row['votes'] === null ? '—' : (int)$row['votes'] ?></td>
                        <td><?= $row['approved'] === null ? $badge(Screens::ITEM_STATUS, $row['status']) : ($row['approved'] ? '<span class="erp-badge erp-badge--success">Aprovado</span>' : '<span class="erp-badge erp-badge--danger">Rejeitado</span>') ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            </section>
            <section class="erp-card" aria-labelledby="as-minutes-title">
                <div class="erp-card__head"><div><h2 id="as-minutes-title">Ata</h2><p><?= $a['minutes'] !== null ? 'Gerada no encerramento em ' . $this->e(Screens::stamp($a['closed_at'])) : 'A ata é gerada quando a assembleia é encerrada.' ?></p></div></div>
                <?php if ($a['minutes'] !== null): ?>
                    <div class="erp-comm-body"><?= nl2br($this->e((string)$a['minutes'])) ?></div>
                    <p class="erp-field__hint">Código de conferência (SHA-256): <code><?= $this->e((string)$a['minutes_sha256']) ?></code></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($kind === 'manage' && $a === null): ?>
        <?php if ($canManageAssembly): ?>
            <?php if ($assemblyContext === null): ?>
                <div class="erp-alert" role="note"><?= $icon('info') ?><div><strong>Escolha um condomínio na barra superior para criar uma assembleia.</strong>A lista abaixo mostra todos os condomínios do seu escopo.</div></div>
            <?php else: ?>
                <form class="erp-card erp-form" data-assembly-create aria-labelledby="as-new-title" novalidate>
                    <div class="erp-card__head"><div><h2 id="as-new-title">Nova assembleia</h2><p>Fica em rascunho até a convocação. O edital sai com pelo menos <?= Assemblies::MIN_NOTICE_DAYS ?> dias de antecedência.</p></div></div>
                    <div class="erp-form__grid">
                        <div class="erp-field erp-col-12"><label for="as-title">Título</label><input class="erp-input" id="as-title" name="title" minlength="5" maxlength="160" required></div>
                        <div class="erp-field erp-col-4"><label for="as-kind">Tipo</label><select class="erp-select" id="as-kind" name="kind" required><?php foreach (Screens::KINDS as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></div>
                        <div class="erp-field erp-col-4"><label for="as-mode">Formato</label><select class="erp-select" id="as-mode" name="mode" required><?php foreach (Screens::MODES as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></div>
                        <div class="erp-field erp-col-4"><label for="as-location">Local ou link da sala</label><input class="erp-input" id="as-location" name="location" minlength="3" maxlength="255" required></div>
                        <div class="erp-field erp-col-6"><label for="as-first">Primeira convocação</label><input class="erp-input" type="datetime-local" id="as-first" name="first_call_at" min="<?= date('Y-m-d\TH:i') ?>" required></div>
                        <div class="erp-field erp-col-6"><label for="as-second">Segunda convocação</label><input class="erp-input" type="datetime-local" id="as-second" name="second_call_at" aria-describedby="as-second-hint"><small class="erp-field__hint" id="as-second-hint">Opcional. Pelo menos 30 minutos depois da primeira.</small></div>
                    </div>
                    <fieldset class="erp-assembly-agenda"><legend>Pauta (de 1 a <?= Assemblies::MAX_ITEMS ?> itens)</legend>
                        <ol class="erp-assembly-agenda__list" data-agenda-list></ol>
                        <button type="button" class="erp-btn erp-btn--sm" data-agenda-add data-max="<?= Assemblies::MAX_ITEMS ?>"><?= $icon('plus', 'erp-icon--sm') ?>Adicionar item</button>
                    </fieldset>
                    <template data-agenda-template><li class="erp-assembly-agenda__item" data-agenda-item><div class="erp-form__grid">
                        <div class="erp-field erp-col-6"><label>Assunto</label><input class="erp-input" name="item_title" minlength="3" maxlength="160" required></div>
                        <div class="erp-field erp-col-3"><label>Base do voto</label><select class="erp-select" name="vote_basis" required><?php foreach (Screens::BASES as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></div>
                        <div class="erp-field erp-col-3"><label>Quórum</label><select class="erp-select" name="quorum" required><?php foreach (Assemblies::QUORUM_LABELS as $value => $label): ?><option value="<?= $value ?>"><?= $this->e(ucfirst($label)) ?></option><?php endforeach; ?></select></div>
                        <div class="erp-field erp-col-10"><label>Descrição</label><input class="erp-input" name="item_description" maxlength="2000"></div>
                        <div class="erp-field erp-field--actions erp-col-2"><button type="button" class="erp-btn erp-btn--sm erp-btn--ghost" data-agenda-remove>Remover</button></div>
                    </div></li></template>
                    <div data-form-error aria-live="assertive"></div>
                    <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary">Criar rascunho</button></div>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <section class="erp-card" aria-labelledby="as-list-title">
            <div class="erp-card__head"><div><h2 id="as-list-title">Assembleias</h2><p><?= (int)($assemblies['total'] ?? 0) ?> registro(s)<?= $assemblyContext === null ? ', em todos os condomínios' : '' ?>.</p></div></div>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('users') ?></span><strong>Nenhuma assembleia ainda</strong><p>Crie a primeira com a pauta e convoque pelo edital.</p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="as-list-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th scope="col">Primeira convocação</th><th scope="col">Título</th><?php if ($assemblyContext === null): ?><th scope="col">Condomínio</th><?php endif; ?><th scope="col">Tipo</th><th scope="col">Formato</th><th scope="col">Situação</th></tr></thead>
                    <tbody><?php foreach ($rows as $r): ?><tr>
                        <td class="erp-nowrap"><?= $this->e(Screens::stamp($r['first_call_at'])) ?></td>
                        <td><a href="<?= $this->e($route . '?assembleia=' . (int)$r['id']) ?>"><?= $this->e($r['title']) ?></a></td>
                        <?php if ($assemblyContext === null): ?><td><?= $this->e((string)$r['condominium_name']) ?></td><?php endif; ?>
                        <td><?= Screens::KINDS[$r['kind']] ?></td>
                        <td><?= Screens::MODES[$r['mode']] ?></td>
                        <td><?= $badge(Screens::STATUS, $r['status']) ?></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
