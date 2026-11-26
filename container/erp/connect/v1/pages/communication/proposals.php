<?php
/**
 * Propostas (CN-011, ADR 0644 e 0650) com dados reais: os pedidos de "Solicite uma proposta" do site e as propostas
 * cadastradas pela equipe, pelo ProposalDesk. Alerta no topo quando há propostas novas. Criar, gerar e enviar: só aqui.
 * @var array $currentPage @var ?array $proposalList @var ?array $proposalStats @var ?string $proposalError
 * @var string $proposalSearch @var string $proposalStatus
 */
use Source\Services\Proposals\ProposalDesk;

$this->layout('default');
$route = erp_url($currentPage['route']);
$filters = ['busca' => $proposalSearch, 'situacao' => $proposalStatus];
$query = static function () use ($filters): string {
    $params = array_filter($filters, static fn ($v): bool => $v !== '');
    return $params === [] ? '' : '?' . http_build_query($params);
};
// Página no caminho (/comunicacao/propostas/p/2) e filtros na query (pedido do Djalma, 20/11/2026).
$pageUrl = static fn (int $page): string => $route . ($page > 1 ? '/p/' . $page : '') . $query();
$when = static fn (string $date): string => date('d/m/Y H:i', (int)strtotime($date));
$rows = $proposalList['data'] ?? [];
$stats = $proposalStats ?? ['total' => 0, 'new' => 0, 'progress' => 0, 'won' => 0];

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ' · Comercial',
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Pedidos do site e propostas cadastradas pela equipe. Aqui você cadastra, anexa os PDFs prontos (administradora e síndico profissional) e envia por e-mail. Cada pedido novo do site avisa a equipe no sino.',
    'pageActions' => '<a class="erp-btn erp-btn--primary" href="' . $this->e($route . '/nova') . '">' . $icon('plus', 'erp-icon--sm') . 'Nova proposta</a>',
]);
?>
<?php if (($flash ?? null) !== null): $ok = str_contains((string)$flash->getType(), 'success'); ?>
    <div class="erp-alert erp-alert--<?= $ok ? 'success' : (str_contains((string)$flash->getType(), 'warning') ? 'warning' : 'danger') ?>" role="<?= $ok ? 'status' : 'alert' ?>"><?= $icon($ok ? 'check' : 'alert') ?><div><?= $flash->getText() ?></div></div>
<?php endif; ?>

<?php if ($proposalError !== null): ?>
    <section class="erp-card"><?php $this->insert('components/feedback/empty', ['emptyIcon' => 'alert', 'emptyTitle' => 'Propostas indisponíveis', 'emptyText' => $proposalError]); ?></section>
<?php else: ?>
    <?php if ($stats['new'] > 0): ?>
        <div class="erp-alert erp-alert--warning erp-proposal-alert" role="status" data-erp-proposal-alert>
            <?= $icon('bell') ?>
            <div><strong><?= $stats['new'] === 1 ? '1 proposta nova aguardando contato' : $stats['new'] . ' propostas novas aguardando contato' ?></strong>Abra o pedido, fale com a pessoa e mude a situação para "Contatada". O aviso sai sozinho quando não houver mais propostas novas.</div>
            <?php if ($proposalStatus !== 'new'): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '?situacao=new') ?>">Ver as novas</a><?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="erp-grid erp-grid--kpi" aria-label="Resumo das propostas">
        <?php foreach ([
            ['label' => 'Recebidas', 'value' => (string)$stats['total'], 'meta' => 'do site e cadastradas', 'icon' => 'file', 'tone' => ''],
            ['label' => 'Novas', 'value' => (string)$stats['new'], 'meta' => 'aguardando contato', 'icon' => 'bell', 'tone' => $stats['new'] > 0 ? 'warning' : ''],
            ['label' => 'Em atendimento', 'value' => (string)$stats['progress'], 'meta' => 'contatadas, qualificadas ou com proposta', 'icon' => 'clock', 'tone' => ''],
            ['label' => 'Fechadas', 'value' => (string)$stats['won'], 'meta' => 'viraram contrato', 'icon' => 'check', 'tone' => ''],
        ] as $i => $kpi) { $this->insert('components/cards/kpi', ['kpi' => $kpi + ['trend' => null], 'delay' => $i * 60]); } ?>
    </section>

    <form class="erp-filters" method="get" action="<?= $this->e($route) ?>" aria-label="Filtrar propostas">
        <div class="erp-field"><label for="prop-q">Buscar</label><input class="erp-input" type="search" id="prop-q" name="busca" maxlength="120" value="<?= $this->e($proposalSearch) ?>" placeholder="Nome, e-mail, condomínio ou protocolo"></div>
        <div class="erp-field erp-field--compact"><label for="prop-status">Situação</label><select class="erp-select" id="prop-status" name="situacao"><option value="">Todas</option>
            <?php foreach (ProposalDesk::STATUSES as $key => [$label]): ?><option value="<?= $this->e($key) ?>"<?= $proposalStatus === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
        </select></div>
        <div class="erp-field erp-field--actions"><button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Filtrar</button></div>
        <?php if ($proposalStatus !== '' || $proposalSearch !== ''): ?><div class="erp-field erp-field--actions"><a class="erp-btn erp-btn--ghost" href="<?= $this->e($route) ?>">Limpar</a></div><?php endif; ?>
    </form>

    <section class="erp-card" aria-labelledby="prop-list">
        <div class="erp-card__head"><div><h2 id="prop-list">Propostas</h2><p><?= (int)($proposalList['total'] ?? 0) ?> pedido(s)</p></div></div>
        <?php if ($rows === []): ?>
            <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong>Nenhuma proposta encontrada</strong><p><?= $proposalStatus !== '' || $proposalSearch !== '' ? 'Nenhum pedido com estes filtros.' : 'Os pedidos feitos no site aparecem aqui assim que chegam. Para um pedido por telefone ou WhatsApp, use "Nova proposta".' ?></p></div>
        <?php else: ?>
            <div class="erp-table-wrap" role="region" aria-labelledby="prop-list" tabindex="0"><table class="erp-table">
                <thead><tr><th scope="col">Solicitante</th><th scope="col">Condomínio</th><th scope="col" class="is-num">Unidades</th><th scope="col">Situação</th><th scope="col">Recebida em</th><th scope="col" class="is-num">Ações</th></tr></thead>
                <tbody><?php foreach ($rows as $item): $tone = ProposalDesk::STATUSES[$item['status']][1] ?? 'plain'; ?>
                    <tr<?= $item['status'] === 'new' ? ' class="is-new"' : '' ?>>
                        <td><strong><?= $this->e($item['name']) ?></strong><small class="erp-muted"><?= $this->e($item['profile_label']) ?> · <?= $this->e($item['email']) ?></small></td>
                        <td><?= $this->e($item['condominium']) ?><small class="erp-muted"><?= $this->e($item['protocol']) ?> · <?= $this->e($item['origin'] === 'site' ? 'Site' : $item['origin_label']) ?></small></td>
                        <td class="is-num"><?= (int)$item['units'] ?></td>
                        <td><span class="erp-badge erp-badge--<?= $tone ?>"><?= $this->e($item['status_label']) ?></span></td>
                        <td class="erp-nowrap"><?= $this->e($when($item['created_at'])) ?></td>
                        <td class="is-num"><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . '/' . (int)$item['id']) ?>" aria-label="Abrir a proposta de <?= $this->e($item['name']) ?> (<?= $this->e($item['condominium']) ?>)">Abrir</a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if (($proposalList['pages'] ?? 0) > 1): ?>
                <nav class="erp-pagination" aria-label="Paginação">
                    <span>Página <?= (int)$proposalList['page'] ?> de <?= (int)$proposalList['pages'] ?></span>
                    <div class="erp-pagination__pages">
                        <?php if ($proposalList['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($pageUrl((int)$proposalList['page'] - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
                        <?php if ($proposalList['page'] < $proposalList['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($pageUrl((int)$proposalList['page'] + 1)) ?>" rel="next">Próxima</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
