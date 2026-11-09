<?php
/**
 * Checklist de chegada do condomínio (ADR 0013).
 *
 * @var array{id:int,name:string,document:?string,status:string} $condominium
 * @var list<array<string,mixed>> $items
 * @var array{total:int,done:int,open:int,pending:int,attention:int,progress:int,by_situation:array<string,int>} $summary
 * @var bool $canManage
 * @var list<array{id:int,name:string}> $responsibles
 * @var int $editing
 */

use Source\Services\OnboardingChecklist;

$this->layout('default');
$cssFile = dirname(__DIR__, 2) . '/assets/css/checklist.css';
$categories = OnboardingChecklist::CATEGORY_LABELS;
$situations = OnboardingChecklist::SITUATION_LABELS;
$statuses = OnboardingChecklist::STATUS_LABELS;
$grouped = [];
foreach ($items as $item) {
    $grouped[$item['category']][] = $item;
}
$base = url('/erp/condominios/' . (int)$condominium['id'] . '/checklist');
$date = static fn(?string $value): string => $value ? date('d/m/Y', strtotime($value)) : '—';
?>
<link rel="stylesheet" href="<?= themeErp('/assets/css/checklist.css') . '?v=' . (is_file($cssFile) ? filemtime($cssFile) : 0) ?>">

<nav class="erp-breadcrumb" aria-label="Trilha">
    <a href="<?= url('/erp/condominios') ?>">Condomínios</a> <span aria-hidden="true">/</span>
    <a href="<?= url('/erp/condominios/' . (int)$condominium['id']) ?>"><?= $this->e($condominium['name']) ?></a> <span aria-hidden="true">/</span>
    <span aria-current="page">Checklist de chegada</span>
</nav>
<header class="erp-page-header">
    <h1>Checklist de chegada</h1>
    <p>Tudo o que o <?= $this->e($condominium['name']) ?> precisa ter em ordem. O que faltar gera um protocolo com prazo; o que vence volta para a lista antes da data.</p>
    <?php if ($canCatalog ?? false): ?><p><a href="<?= url('/erp/checklist/catalogo') ?>">Configurar os itens do catálogo</a></p><?php endif; ?>
</header>

<?= flash() ?>

<?php if ($items === []): ?>
    <section class="erp-state erp-state-empty" role="status">
        <h2>Checklist ainda não iniciado</h2>
        <p>Ao iniciar, os itens do catálogo da Connect (CNPJ, convenção, AVCB, extintores, seguro e outros) são criados para este condomínio como “A verificar”.</p>
        <?php if ($canManage): ?>
            <form method="post" action="<?= $base ?>/iniciar">
                <?= csrf_input() ?>
                <button type="submit" class="chk-button chk-button-primary">Iniciar checklist de chegada</button>
            </form>
        <?php else: ?>
            <p>Peça a quem gerencia condomínios para iniciar o checklist.</p>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="chk-summary" aria-label="Resumo do checklist">
        <div class="chk-progress">
            <span class="chk-progress-label"><?= (int)$summary['progress'] ?>% em ordem</span>
            <progress max="100" value="<?= (int)$summary['progress'] ?>"><?= (int)$summary['progress'] ?>%</progress>
            <small><?= (int)$summary['done'] ?> de <?= (int)$summary['total'] ?> itens resolvidos</small>
        </div>
        <dl class="chk-counters">
            <div><dt>A verificar</dt><dd><?= (int)$summary['pending'] ?></dd></div>
            <div><dt>Protocolos abertos</dt><dd><?= (int)$summary['open'] ?></dd></div>
            <div class="<?= $summary['attention'] > 0 ? 'chk-alert' : '' ?>"><dt>Vencidos ou vencendo</dt><dd><?= (int)$summary['attention'] ?></dd></div>
        </dl>
        <?php if ($canManage): ?>
            <form method="post" action="<?= $base ?>/iniciar" class="chk-sync">
                <?= csrf_input() ?>
                <button type="submit" class="chk-button">Atualizar com o catálogo</button>
            </form>
        <?php endif; ?>
    </section>

    <?php foreach ($categories as $category => $label): ?>
        <?php if (empty($grouped[$category])) { continue; } ?>
        <section class="chk-group" aria-labelledby="chk-<?= $category ?>">
            <h2 id="chk-<?= $category ?>"><?= $this->e($label) ?></h2>
            <div class="erp-table-wrap">
                <table class="erp-table chk-table">
                    <thead>
                    <tr><th scope="col">Item</th><th scope="col">Situação</th><th scope="col">Protocolo</th><th scope="col">Prazo</th><th scope="col">Validade</th><th scope="col">Responsável</th><?php if ($canManage): ?><th scope="col"><span class="erp-sr-only">Ações</span></th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($grouped[$category] as $item): ?>
                        <tr id="item-<?= (int)$item['id'] ?>">
                            <td>
                                <strong><?= $this->e($item['title']) ?></strong>
                                <?php if ($item['description']): ?><br><small><?= $this->e($item['description']) ?></small><?php endif; ?>
                                <?php if ($item['notes']): ?><br><small class="chk-notes"><?= nl2br($this->e($item['notes'])) ?></small><?php endif; ?>
                            </td>
                            <td><span class="chk-badge chk-<?= $this->e($item['situation']) ?>"><?= $this->e($situations[$item['situation']] ?? $item['situation']) ?></span></td>
                            <td><?= $item['protocol'] ? '<code>' . $this->e($item['protocol']) . '</code>' : '—' ?></td>
                            <td><?= $date($item['due_date']) ?></td>
                            <td><?= $date($item['expires_at']) ?><?php if ($item['renewal_months']): ?><br><small>renova a cada <?= (int)$item['renewal_months'] ?> meses</small><?php endif; ?></td>
                            <td><?= $item['responsible'] ? $this->e($item['responsible']['name']) : '—' ?></td>
                            <?php if ($canManage): ?>
                                <td><a href="<?= $base ?>?item=<?= (int)$item['id'] ?>#item-<?= (int)$item['id'] ?>">Atualizar</a></td>
                            <?php endif; ?>
                        </tr>
                        <?php if ($canManage && $editing === $item['id']): ?>
                            <tr class="chk-edit">
                                <td colspan="7">
                                    <form method="post" action="<?= $base ?>/itens/<?= (int)$item['id'] ?>" class="chk-form">
                                        <?= csrf_input() ?>
                                        <label>Situação
                                            <select name="status">
                                                <?php foreach ($statuses as $value => $text): ?>
                                                    <option value="<?= $value ?>"<?= $item['status'] === $value ? ' selected' : '' ?>><?= $this->e($text) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>Prazo para regularizar
                                            <input type="date" name="due_date" value="<?= $this->e((string)$item['due_date']) ?>">
                                        </label>
                                        <label>Validade do documento
                                            <input type="date" name="expires_at" value="<?= $this->e((string)$item['expires_at']) ?>">
                                        </label>
                                        <label>Responsável
                                            <select name="responsible_user_id">
                                                <option value="">Ninguém</option>
                                                <?php foreach ($responsibles as $person): ?>
                                                    <option value="<?= $person['id'] ?>"<?= ($item['responsible']['id'] ?? 0) === $person['id'] ? ' selected' : '' ?>><?= $this->e($person['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="chk-wide">Observações
                                            <textarea name="notes" rows="3" maxlength="2000"><?= $this->e((string)$item['notes']) ?></textarea>
                                        </label>
                                        <p class="chk-hint">“Falta providenciar” e “Em andamento” geram protocolo e, sem prazo informado, usam o prazo padrão do item. “Em dia” sem validade informada usa a periodicidade de renovação.</p>
                                        <div class="chk-actions">
                                            <button type="submit" class="chk-button chk-button-primary">Salvar</button>
                                            <a href="<?= $base ?>#item-<?= (int)$item['id'] ?>">Cancelar</a>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($canManage): ?>
        <section class="chk-group" id="novo-item" aria-labelledby="chk-novo">
            <h2 id="chk-novo">Registrar outra pendência</h2>
            <form method="post" action="<?= $base ?>/itens" class="chk-form erp-state">
                <?= csrf_input() ?>
                <label>Categoria
                    <select name="category" required>
                        <?php foreach ($categories as $value => $text): ?>
                            <option value="<?= $value ?>"><?= $this->e($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="chk-wide">O que precisa ser feito
                    <input type="text" name="title" maxlength="160" required placeholder="Ex.: Portão da garagem sem contrato de manutenção">
                </label>
                <div class="chk-actions"><button type="submit" class="chk-button chk-button-primary">Registrar e gerar protocolo</button></div>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
