<?php
/**
 * Listagem de um cadastro real (RegistryService → MariaDB), com filtros e paginação no
 * servidor. Mesmo template para todos os cadastros do motor (ADR 0014/0015).
 * @var array $definition @var array $result @var array $filters @var ?string $filterField
 * @var list<array> $condominiums @var bool $canManage @var ?\Source\Support\Message $flash
 */
use Source\Support\Erp\PerPage;
use Source\Support\Erp\RegistryFormat;

$this->layout('default');
$route = $definition['route'];
$article = $definition['gender'] === 'a' ? 'Nova' : 'Novo';
// Coluna principal (nome, razão social ou placa) primeiro, com o link para o registro.
$listFields = [$definition['title'] => $definition['fields'][$definition['title']]] + array_filter($definition['fields'], static fn (array $f): bool => $f['list']);
$hasCondominium = $definition['scope'] !== 'none';
// Unidades: colunas de proprietário e inquilino quando o serviço trouxe os nomes (quem pode ver pessoas).
$peopleColumns = [];
if (isset($definition['people']) && $result['data'] !== [] && array_key_exists(reset($definition['people']['roles']), $result['data'][0])) {
    foreach ($definition['people']['roles'] as $role => $column) {
        $peopleColumns[$column] = $definition['people']['labels'][$role];
    }
}
$searchHint = isset($definition['people']) ? 'Unidade, bloco, proprietário ou inquilino' : 'Nome, documento ou unidade';
// Filtro de marca da definição (ADR 0310: Ocupação das unidades).
$flagFilter = $definition['flag_filter'] ?? null;
$flagParam = $flagFilter['param'] ?? 'ocupacao';
$query = static function (array $changes) use ($filters, $flagParam): string {
    $params = array_filter([
        'condominio' => $filters['condominium_id'] ?? null, 'situacao' => $filters['status'] ?? null,
        'tipo' => $filters['filter'] ?? null, 'q' => $filters['q'] ?? null,
        $flagParam => $filters['flag'] ?? null,
        'por_pagina' => ($filters['per_page'] ?? PerPage::DEFAULT) !== PerPage::DEFAULT ? $filters['per_page'] : null,
    ], static fn ($v): bool => $v !== null && $v !== '');
    $params = array_filter(array_merge($params, $changes), static fn ($v): bool => $v !== null && $v !== '');
    return $params === [] ? '' : '?' . http_build_query($params);
};
// Configuração em massa (ADR 0311): só na lista de unidades, com um condomínio escolhido e permissão de gravar.
$bulkCondominium = ($definition['page'] ?? '') === 'AP-006' && $canManage ? (int)($filters['condominium_id'] ?? 0) : 0;
$filtered = array_filter([$filters['status'] ?? null, $filters['filter'] ?? null, $filters['q'] ?? null, isset($flagFilter['options'][(string)($filters['flag'] ?? '')]) ? $filters['flag'] : null], static fn ($v): bool => $v !== null && $v !== '') !== [];
ob_start(); ?>
    <?php // AP-006: cadastro rápido e fração ideal/área em grade (ADR 0140).
    if (($definition['page'] ?? '') === 'AP-006'): ?>
        <a class="erp-btn" href="<?= erp_url('/configuracoes/fracao-ideal') ?>">Fração ideal e área</a>
        <?php if ($canManage): ?><a class="erp-btn" href="<?= erp_url(\Source\Controllers\Erp\Connect\UnitMoveTermScreen::ROUTE) ?>"><?= $icon('file', 'erp-icon--sm') ?>Termo de entrada/saída</a><?php endif; ?>
        <?php if ($canManage): ?><a class="erp-btn" href="<?= erp_url('/condominios/unidades/cadastro-rapido') ?>"><?= $icon('sparkle', 'erp-icon--sm') ?>Cadastro rápido</a><?php endif; ?>
    <?php endif; ?>
    <?php if ($canManage): ?><a class="erp-btn erp-btn--primary" href="<?= erp_url($route . '/novo') ?>"><?= $icon('plus', 'erp-icon--sm') ?><?= $this->e($article . ' ' . $definition['entity']) ?></a><?php endif; ?>
    <?php // AP-021: gera os lançamentos da competência a partir dos fixos (ADR 0027). A API valida tudo de novo.
    if (($definition['page'] ?? '') === 'AP-021' && $canManage && \Source\Support\Access::can('erp.entries.manage')): ?>
        <form class="erp-generate-month" data-erp-generate-recurring="<?= $this->e(url('/api/v1/recurring-entries/generate')) ?>" data-condominium="<?= (int)($filters['condominium_id'] ?? 0) ?>">
            <?= csrf_input() ?>
            <label class="erp-sr-only" for="erp-generate-competence">Competência</label>
            <input class="erp-input" type="month" id="erp-generate-competence" name="competence" value="<?= date('Y-m') ?>" required>
            <button type="submit" class="erp-btn"><?= $icon('calendar', 'erp-icon--sm') ?>Gerar lançamentos do mês</button>
        </form>
    <?php endif; ?>
<?php $actions = trim((string)ob_get_clean());
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$definition['group'] ?? 'cadastros']['label'] ?? 'Cadastros',
    'pageTitle' => $definition['label'],
    'pageLead' => $definition['lead'],
    'pageActions' => $actions,
]);
?>
<?php if ($flash !== null): $ok = str_contains((string)$flash->getType(), 'success'); ?>
    <div class="erp-alert erp-alert--<?= $ok ? 'success' : 'danger' ?>" role="status"><?= $icon($ok ? 'check' : 'alert') ?><div><?= $flash->getText() ?></div></div>
<?php endif; ?>

<section class="erp-list-page" aria-label="<?= $this->e($definition['label']) ?>">
    <form class="erp-filters" role="search" method="get" action="<?= erp_url($route) ?>">
        <div class="erp-field erp-field--grow"><label for="reg-q">Pesquisar</label><input class="erp-input" type="search" id="reg-q" name="q" maxlength="100" value="<?= $this->e((string)($filters['q'] ?? '')) ?>" placeholder="<?= $this->e($searchHint) ?>"></div>
        <?php if ($hasCondominium): ?>
            <div class="erp-field"><label for="reg-condo">Condomínio</label><select class="erp-select" id="reg-condo" name="condominio"><option value="">Todos</option>
                <?php foreach ($condominiums as $condominium): ?><option value="<?= (int)$condominium['id'] ?>"<?= (string)$condominium['id'] === (string)($filters['condominium_id'] ?? '') ? ' selected' : '' ?>><?= $this->e($condominium['name']) ?></option><?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <?php if ($filterField !== null): $ff = $definition['fields'][$filterField]; ?>
            <div class="erp-field"><label for="reg-type"><?= $this->e($ff['label']) ?></label><select class="erp-select" id="reg-type" name="tipo"><option value="">Todos</option>
                <?php foreach ($ff['options'] as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === ($filters['filter'] ?? '') ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <?php if ($flagFilter !== null): ?>
            <div class="erp-field"><label for="reg-flag"><?= $this->e($flagFilter['label']) ?></label><select class="erp-select" id="reg-flag" name="<?= $this->e($flagParam) ?>"><option value="">Todas</option>
                <?php foreach ($flagFilter['options'] as $key => [$label]): ?><option value="<?= $this->e($key) ?>"<?= $key === (string)($filters['flag'] ?? '') ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <?php if (($filters['per_page'] ?? PerPage::DEFAULT) !== PerPage::DEFAULT): ?><input type="hidden" name="por_pagina" value="<?= (int)$filters['per_page'] ?>"><?php endif; ?>
        <div class="erp-field"><label for="reg-status">Situação</label><select class="erp-select" id="reg-status" name="situacao"><option value="">Todas</option>
            <?php foreach ($definition['status'] as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === ($filters['status'] ?? '') ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?>
        </select></div>
        <div class="erp-field"><span class="erp-field__label" aria-hidden="true">&nbsp;</span><button type="submit" class="erp-btn"><?= $icon('search', 'erp-icon--sm') ?>Filtrar</button></div>
        <?php if ($filtered): ?><div class="erp-field"><span class="erp-field__label" aria-hidden="true">&nbsp;</span><a class="erp-btn erp-btn--ghost" href="<?= erp_url($route) ?>">Limpar</a></div><?php endif; ?>
    </form>

    <div class="erp-card erp-card--flush" style="margin-top:16px">
        <div class="erp-card__head"><div><h2><?= $this->e($definition['label']) ?></h2><p><?= $result['total'] === 1 ? '1 registro' : $result['total'] . ' registros' ?><?= $filtered ? ' com os filtros aplicados' : '' ?></p></div></div>
        <?php if ($result['data'] === []): ?>
            <div class="erp-empty" role="status">
                <span class="erp-empty__icon"><?= $icon('users') ?></span>
                <h3><?= $filtered ? 'Nenhum registro encontrado' : 'Nenhum registro ainda' ?></h3>
                <p><?= $filtered ? 'Ajuste os filtros ou limpe a pesquisa.' : ($hasCondominium && $condominiums === [] ? ($user->can('erp.condominiums.manage') ? 'Cadastre um condomínio em Condomínios › Novo condomínio para começar.' : 'Você ainda não tem acesso a nenhum condomínio ativo. Solicite o vínculo ao administrador do sistema.') : 'Cadastre o primeiro registro para começar.') ?></p>
                <?php if ($canManage && !$filtered && (!$hasCondominium || $condominiums !== [])): ?><a class="erp-btn erp-btn--primary" href="<?= erp_url($route . '/novo') ?>"><?= $icon('plus', 'erp-icon--sm') ?><?= $this->e($article . ' ' . $definition['entity']) ?></a><?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($bulkCondominium > 0): ?>
                <div class="erp-bulk-bar" data-unit-bulk-bar>
                    <span data-unit-bulk-selected>Marque as unidades para configurar em massa.</span>
                    <button type="button" class="erp-btn erp-btn--sm" data-erp-modal-open="unit-bulk-settings" data-unit-bulk-open disabled><?= $icon('settings', 'erp-icon--sm') ?>Configurar unidades em massa</button>
                </div>
            <?php endif; ?>
            <div class="erp-table-wrap" role="region" aria-label="<?= $this->e($definition['label']) ?>" tabindex="0">
                <table class="erp-table">
                    <caption class="erp-sr-only"><?= $this->e($definition['label']) ?> (<?= (int)$result['total'] ?>)</caption>
                    <thead><tr>
                        <?php if ($bulkCondominium > 0): ?><th scope="col" class="is-check"><input type="checkbox" data-unit-bulk-all aria-label="Marcar todas as unidades desta página"></th><?php endif; ?>
                        <?php foreach ($listFields as $field): ?><th scope="col"><?= $this->e($field['list_label'] ?? $field['label']) ?></th><?php endforeach; ?>
                        <?php foreach ($peopleColumns as $label): ?><th scope="col"><?= $this->e($label) ?></th><?php endforeach; ?>
                        <th scope="col">Situação</th>
                        <th scope="col" class="is-num"><span class="erp-sr-only">Ações</span></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($result['data'] as $row): $first = true; $href = erp_url($route . '/' . (int)$row['id']); ?>
                        <tr>
                            <?php if ($bulkCondominium > 0): ?><td class="is-check"><input type="checkbox" value="<?= (int)$row['id'] ?>" data-unit-bulk-item aria-label="Marcar a unidade <?= $this->e((string)($row[$definition['title']] ?? $row['id'])) ?>"></td><?php endif; ?>
                            <?php foreach ($listFields as $name => $field): $text = RegistryFormat::display($field, $row[$name], $row['condominium_name'] ?? null); ?>
                                <td><?php if ($first): $first = false; ?><a href="<?= $href ?>"><strong><?= $this->e($text) ?></strong></a><?php else: ?><?= $this->e($text) ?><?php endif; ?></td>
                            <?php endforeach; ?>
                            <?php foreach ($peopleColumns as $column => $label): $names = $row[$column] ?? []; ?>
                                <td><?= $names === [] ? '<span class="erp-muted">—</span>' : $this->e(implode(', ', $names)) ?></td>
                            <?php endforeach; ?>
                            <td><span class="erp-badge erp-badge--<?= $row['status'] === 'active' ? 'success' : 'danger' ?>"><?= $this->e($definition['status'][$row['status']] ?? $row['status']) ?></span></td>
                            <td class="is-num"><a class="erp-btn erp-btn--ghost erp-btn--sm" href="<?= $href ?>" aria-label="<?= $canManage ? 'Editar' : 'Ver' ?> registro <?= (int)$row['id'] ?>"><?= $icon($canManage ? 'edit' : 'eye', 'erp-icon--sm') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php // Paginação padrão (ADR 0310): moves/pager e itens por página 50/100/200/500.
            parse_str(ltrim($query([]), '?'), $currentQuery);
            $this->insert('components/tables/pagination', ['pagination' => $result, 'paginationBase' => erp_url($route), 'paginationQuery' => $currentQuery]); ?>
        <?php endif; ?>
    </div>
</section>
<?php if ($bulkCondominium > 0 && $result['data'] !== []): $this->insert('components/units/bulk-settings', ['definition' => $definition, 'bulkCondominium' => $bulkCondominium]); endif; ?>
