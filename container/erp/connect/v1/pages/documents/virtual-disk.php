<?php
/**
 * Disco Virtual (AP-122, ADR 0047 e 0049) com dados reais, no contexto da barra superior: arquivos
 * por pasta ($diskFiles de Panel::virtualDiskData). Enviar (multipart, com progresso), baixar e
 * excluir usam a API /api/v1/documents (erp.js, data-erp-virtual-disk). O tipo é conferido pelo
 * conteúdo no servidor; aqui só se avisa antes do envio.
 * @var array $currentPage @var array $diskFiles @var ?string $diskFolder @var string $diskQuery
 * @var list<array{id:int,name:string}> $diskCondominiums @var bool $canManageFiles
 */
$this->layout('default');
$route = erp_url($currentPage['route']);
$folders = \Source\Services\Erp\VirtualDisk::FOLDERS;
$size = static fn (int $bytes): string => \Source\Controllers\Erp\Connect\Panel::fileSize($bytes);
$canManageFiles ??= false;
$context = \Source\Support\Erp\CondominiumContext::id();
$rows = $diskFiles['data'] ?? [];
$query = static function (array $changes) use ($diskFolder, $diskQuery): string {
    $params = array_filter(array_merge(['pasta' => $diskFolder, 'q' => $diskQuery], $changes), static fn ($v): bool => $v !== null && $v !== '');
    return $params === [] ? '' : '?' . http_build_query($params);
};
$typeLabel = static function (string $mime, string $name): string {
    $ext = strtoupper(pathinfo($name, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : ($mime === 'application/pdf' ? 'PDF' : $mime);
};
$accept = '.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.odt,.ods,.csv,.txt,.xml';
$api = url('/api/v1/documents');

$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Arquivos do condomínio organizados por pasta: atas, contratos, plantas, notas fiscais e outros. Os arquivos ficam guardados fora do site e só baixa quem tem acesso ao condomínio.',
    'pageActions' => '',
]);
?>
<div class="erp-stack" data-erp-virtual-disk data-api="<?= $this->e($api) ?>">
    <?= csrf_input() ?>
    <nav class="erp-disk-folders" aria-label="Pastas">
        <a class="erp-disk-folder<?= $diskFolder === null ? ' is-active' : '' ?>" href="<?= $this->e($route . $query(['pasta' => null, 'pagina' => null])) ?>"<?= $diskFolder === null ? ' aria-current="page"' : '' ?>><?= $icon('file', 'erp-icon--sm') ?>Todas</a>
        <?php foreach ($folders as $key => $label): ?>
            <a class="erp-disk-folder<?= $diskFolder === $key ? ' is-active' : '' ?>" href="<?= $this->e($route . $query(['pasta' => $key, 'pagina' => null])) ?>"<?= $diskFolder === $key ? ' aria-current="page"' : '' ?>><?= $icon('file', 'erp-icon--sm') ?><?= $this->e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="erp-grid erp-disk-layout">
        <?php if ($canManageFiles): ?>
            <form class="erp-card erp-form erp-disk-upload" data-disk-upload aria-labelledby="disk-upload-title" novalidate>
                <div class="erp-card__head"><div><h2 id="disk-upload-title">Enviar arquivo</h2><p>PDF, imagem (JPG, PNG, WebP), DOCX, XLSX, ODT, ODS, CSV, TXT ou XML, até 20 MB.</p></div></div>
                <?php if ($context === null): ?>
                    <div class="erp-field"><label for="disk-condo">Condomínio</label><select class="erp-select" id="disk-condo" name="condominium_id" required><option value="">Selecione</option><?php foreach ($diskCondominiums as $c): ?><option value="<?= (int)$c['id'] ?>"><?= $this->e((string)$c['name']) ?></option><?php endforeach; ?></select></div>
                <?php else: ?>
                    <input type="hidden" name="condominium_id" value="<?= (int)$context ?>">
                <?php endif; ?>
                <div class="erp-field"><label for="disk-folder">Pasta</label><select class="erp-select" id="disk-folder" name="folder" required><?php foreach ($folders as $key => $label): ?><option value="<?= $key ?>"<?= $key === ($diskFolder ?? 'atas') ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach; ?></select></div>
                <label class="erp-dropzone" for="disk-file" data-disk-drop>
                    <?= $icon('upload') ?>
                    <strong data-disk-file-name>Escolha ou arraste o arquivo</strong>
                    <span class="erp-muted" data-disk-file-meta>Um arquivo por vez</span>
                    <input class="erp-dropzone__input" type="file" id="disk-file" name="file" accept="<?= $accept ?>" required data-max-bytes="<?= \Source\Services\Erp\VirtualDisk::MAX_BYTES ?>">
                </label>
                <div class="erp-field"><label for="disk-title">Título <span class="erp-muted">(opcional)</span></label><input class="erp-input" id="disk-title" name="title" maxlength="160" placeholder="Vazio = nome do arquivo"></div>
                <div class="erp-progress" data-disk-progress hidden role="progressbar" aria-label="Envio do arquivo" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-disk-progress-bar style="width:0%"></span></div>
                <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('upload', 'erp-icon--sm') ?>Enviar</button></div>
            </form>
        <?php endif; ?>

        <section class="erp-card erp-disk-list" aria-labelledby="disk-list-title">
            <div class="erp-card__head">
                <div><h2 id="disk-list-title"><?= $this->e($diskFolder !== null ? $folders[$diskFolder] : 'Todos os arquivos') ?></h2><p><?= (int)($diskFiles['total'] ?? 0) ?> arquivo(s)<?= $diskQuery !== '' ? ' com "' . $this->e($diskQuery) . '"' : '' ?>.</p></div>
                <form class="erp-disk-search" method="get" action="<?= $this->e($route) ?>" role="search">
                    <?php if ($diskFolder !== null): ?><input type="hidden" name="pasta" value="<?= $this->e($diskFolder) ?>"><?php endif; ?>
                    <label class="erp-sr-only" for="disk-q">Buscar arquivos</label>
                    <input class="erp-input" type="search" id="disk-q" name="q" value="<?= $this->e($diskQuery) ?>" maxlength="100" placeholder="Buscar por título ou nome">
                    <button type="submit" class="erp-btn"><?= $icon('filter', 'erp-icon--sm') ?>Buscar</button>
                </form>
            </div>
            <?php if ($rows === []): ?>
                <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('file') ?></span><strong><?= $diskQuery !== '' ? 'Nenhum arquivo encontrado' : 'Pasta vazia' ?></strong><p><?= $diskQuery !== '' ? 'Tente outro termo ou limpe a busca.' : ($canManageFiles ? 'Envie o primeiro arquivo ao lado.' : 'Os arquivos enviados aparecem aqui.') ?></p></div>
            <?php else: ?>
                <div class="erp-table-wrap" role="region" aria-labelledby="disk-list-title" tabindex="0"><table class="erp-table">
                    <thead><tr><th>Arquivo</th><?php if ($diskFolder === null): ?><th>Pasta</th><?php endif; ?><th class="is-num">Tamanho</th><th>Enviado em</th><th class="is-num">Ações</th></tr></thead>
                    <tbody><?php foreach ($rows as $f): ?><tr>
                        <td class="erp-disk-name"><strong class="erp-disk-title"><?= $this->e((string)$f['title']) ?></strong><br><span class="erp-muted"><?= $this->e((string)$f['original_name']) ?></span><?php if ($context === null): ?><br><span class="erp-muted"><?= $this->e((string)$f['condominium_name']) ?></span><?php endif; ?></td>
                        <?php if ($diskFolder === null): ?><td><span class="erp-badge erp-badge--plain"><?= $this->e((string)$f['folder_label']) ?></span></td><?php endif; ?>
                        <td class="is-num erp-nowrap"><?= $this->e($size((int)$f['size_bytes'])) ?><br><span class="erp-muted"><?= $this->e($typeLabel((string)$f['mime'], (string)$f['original_name'])) ?></span></td>
                        <td class="erp-nowrap"><?= $this->e($f['uploaded_at'] ? date('d/m/Y', (int)strtotime((string)$f['uploaded_at'])) : '—') ?><br><span class="erp-muted"><?= $this->e($f['uploaded_at'] ? date('H:i', (int)strtotime((string)$f['uploaded_at'])) : '') ?></span></td>
                        <td class="is-num"><div class="erp-table__actions"><a class="erp-btn erp-btn--sm" href="<?= $this->e($api . '/' . (int)$f['id'] . '/download') ?>" download><?= $icon('download', 'erp-icon--sm') ?>Baixar</a><?php if ($canManageFiles): ?><?php $shared = !empty($f['shared_with_residents']); ?><button type="button" class="erp-btn erp-btn--sm" data-disk-share="<?= (int)$f['id'] ?>" data-shared="<?= $shared ? '1' : '0' ?>" aria-pressed="<?= $shared ? 'true' : 'false' ?>" title="<?= $shared ? 'Os moradores veem este arquivo no aplicativo. Clique para esconder.' : 'Mostrar este arquivo aos moradores no aplicativo.' ?>"><?= $shared ? 'Visível aos moradores' : 'Mostrar aos moradores' ?></button><button type="button" class="erp-btn erp-btn--sm" data-disk-delete="<?= (int)$f['id'] ?>" data-label="<?= $this->e((string)$f['title']) ?>">Excluir</button><?php endif; ?></div></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
                <?php if (($diskFiles['pages'] ?? 0) > 1): ?>
                    <nav class="erp-pagination" aria-label="Paginação">
                        <span>Página <?= (int)$diskFiles['page'] ?> de <?= (int)$diskFiles['pages'] ?></span>
                        <div class="erp-pagination__pages">
                            <?php if ($diskFiles['page'] > 1): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $diskFiles['page'] - 1])) ?>" rel="prev">Anterior</a><?php endif; ?>
                            <?php if ($diskFiles['page'] < $diskFiles['pages']): ?><a class="erp-btn erp-btn--sm" href="<?= $this->e($route . $query(['pagina' => $diskFiles['page'] + 1])) ?>" rel="next">Próxima</a><?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($canManageFiles): ?>
    <dialog class="erp-modal" id="disk-delete" aria-labelledby="disk-delete-title">
        <form data-disk-delete-form>
            <div class="erp-modal__head"><h2 id="disk-delete-title">Excluir arquivo</h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
            <div class="erp-modal__body">
                <p><strong data-disk-delete-label></strong></p>
                <p class="erp-muted">O arquivo sai do Disco Virtual. A exclusão fica registrada na auditoria e o arquivo continua guardado para consulta da administração.</p>
            </div>
            <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--danger">Excluir</button></div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
<p class="erp-muted erp-trace">Ficha: <?= $this->e($currentPage['spec']) ?></p>
